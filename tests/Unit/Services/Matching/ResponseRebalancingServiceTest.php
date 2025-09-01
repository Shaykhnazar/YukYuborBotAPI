<?php

namespace Tests\Unit\Services\Matching;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\User;
use App\Services\Matching\CapacityAwareMatchingService;
use App\Services\Matching\ResponseCreationService;
use App\Services\Matching\ResponseRebalancingService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResponseRebalancingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ResponseRebalancingService $service;
    protected CapacityAwareMatchingService $capacityMatchingService;
    protected ResponseCreationService $creationService;
    protected NotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capacityMatchingService = $this->mock(CapacityAwareMatchingService::class);
        $this->creationService = $this->mock(ResponseCreationService::class);
        $this->notificationService = $this->mock(NotificationService::class);

        $this->service = new ResponseRebalancingService(
            $this->capacityMatchingService,
            $this->creationService,
            $this->notificationService
        );

        // Set test capacity to 2 for easier testing
        config(['capacity.max_deliverer_capacity' => 2]);
    }

    /** @test */
    public function it_only_rebalances_matching_responses_from_deliverers()
    {
        $deliverer = User::factory()->create();
        $sender = User::factory()->create();

        // Create a manual response (should not trigger rebalancing)
        $manualResponse = Response::factory()->create([
            'user_id' => $deliverer->id,
            'responder_id' => $sender->id,
            'response_type' => Response::TYPE_MANUAL,
            'overall_status' => 'accepted'
        ]);

        // Mock capacity info to show over capacity
        $this->capacityMatchingService
            ->shouldReceive('getDelivererCapacityInfo')
            ->never(); // Should not be called for manual responses

        $this->service->rebalanceAfterAcceptance($manualResponse);

        // No assertions needed - test passes if no methods are called
    }

    /** @test */
    public function it_redistributes_excess_responses_when_deliverer_over_capacity()
    {
        $deliverer = User::factory()->create();
        $sender1 = User::factory()->create();
        $sender2 = User::factory()->create();
        $sender3 = User::factory()->create();
        $alternative = User::factory()->create();

        // Create the accepted response
        $acceptedResponse = Response::factory()->create([
            'user_id' => $deliverer->id,
            'responder_id' => $sender1->id,
            'response_type' => Response::TYPE_MATCHING,
            'overall_status' => 'partial',
            'offer_type' => 'send'
        ]);

        // Create excess pending responses
        $excessResponse1 = Response::factory()->create([
            'user_id' => $deliverer->id,
            'responder_id' => $sender2->id,
            'response_type' => Response::TYPE_MATCHING,
            'overall_status' => 'pending',
            'offer_type' => 'send'
        ]);

        $excessResponse2 = Response::factory()->create([
            'user_id' => $deliverer->id,
            'responder_id' => $sender3->id,
            'response_type' => Response::TYPE_MATCHING,
            'overall_status' => 'pending',
            'offer_type' => 'send'
        ]);

        // Create send requests
        $sendRequest1 = SendRequest::factory()->create(['user_id' => $sender2->id]);
        $sendRequest2 = SendRequest::factory()->create(['user_id' => $sender3->id]);

        // Create alternative delivery
        $altDelivery = DeliveryRequest::factory()->create(['user_id' => $alternative->id]);

        // Mock capacity info showing over capacity
        $this->capacityMatchingService
            ->shouldReceive('getDelivererCapacityInfo')
            ->with($deliverer->id)
            ->once()
            ->andReturn([
                'current_load' => 3,
                'max_capacity' => 2,
                'is_at_capacity' => true
            ]);

        // Mock finding alternatives for first excess response
        $this->capacityMatchingService
            ->shouldReceive('findAlternativeDeliverers')
            ->with($sendRequest1, $deliverer->id)
            ->once()
            ->andReturn(collect([$altDelivery]));

        // Mock finding no alternatives for second excess response
        $this->capacityMatchingService
            ->shouldReceive('findAlternativeDeliverers')
            ->with($sendRequest2, $deliverer->id)
            ->once()
            ->andReturn(collect([]));

        // Mock creating new response for alternative deliverer
        $newResponse = Response::factory()->create([
            'user_id' => $alternative->id,
            'responder_id' => $sender2->id
        ]);

        $this->creationService
            ->shouldReceive('createMatchingResponse')
            ->with(
                $alternative->id,
                $sender2->id,
                'send',
                $altDelivery->id,
                $sendRequest1->id
            )
            ->once()
            ->andReturn($newResponse);

        // Mock notifications
        $this->notificationService
            ->shouldReceive('sendResponseNotification')
            ->with($alternative->id)
            ->once();

        $this->notificationService
            ->shouldReceive('sendResponseNotification')
            ->with($sender3->id)
            ->once();

        $this->service->rebalanceAfterAcceptance($acceptedResponse);

        // Verify that excess responses were handled
        $this->assertDatabaseHas('responses', [
            'id' => $excessResponse1->id,
            'overall_status' => 'rejected',
            'message' => 'Auto-rejected: Redistributed to alternative deliverer'
        ]);

        $this->assertDatabaseHas('responses', [
            'id' => $excessResponse2->id,
            'overall_status' => 'rejected',
            'message' => 'Auto-rejected: No alternative deliverers available'
        ]);
    }

    /** @test */
    public function it_does_not_rebalance_when_deliverer_within_capacity()
    {
        $deliverer = User::factory()->create();
        $sender = User::factory()->create();

        $acceptedResponse = Response::factory()->create([
            'user_id' => $deliverer->id,
            'responder_id' => $sender->id,
            'response_type' => Response::TYPE_MATCHING,
            'overall_status' => 'partial'
        ]);

        // Mock capacity info showing within capacity
        $this->capacityMatchingService
            ->shouldReceive('getDelivererCapacityInfo')
            ->with($deliverer->id)
            ->once()
            ->andReturn([
                'current_load' => 1,
                'max_capacity' => 2,
                'is_at_capacity' => false
            ]);

        // Should not call any redistribution methods
        $this->capacityMatchingService
            ->shouldReceive('findAlternativeDeliverers')
            ->never();

        $this->service->rebalanceAfterAcceptance($acceptedResponse);

        // Test passes if no redistribution occurs
    }

    /** @test */
    public function it_provides_system_capacity_statistics()
    {
        $deliverer1 = User::factory()->create();
        $deliverer2 = User::factory()->create();

        // Create responses for different deliverers
        Response::factory()->create(['user_id' => $deliverer1->id, 'overall_status' => 'pending']);
        Response::factory()->create(['user_id' => $deliverer1->id, 'overall_status' => 'partial']);
        Response::factory()->create(['user_id' => $deliverer2->id, 'overall_status' => 'pending']);

        // Mock capacity info for each deliverer
        $this->capacityMatchingService
            ->shouldReceive('getDelivererCapacityInfo')
            ->with($deliverer1->id)
            ->once()
            ->andReturn([
                'deliverer_id' => $deliverer1->id,
                'current_load' => 2,
                'max_capacity' => 2,
                'is_at_capacity' => true
            ]);

        $this->capacityMatchingService
            ->shouldReceive('getDelivererCapacityInfo')
            ->with($deliverer2->id)
            ->once()
            ->andReturn([
                'deliverer_id' => $deliverer2->id,
                'current_load' => 1,
                'max_capacity' => 2,
                'is_at_capacity' => false
            ]);

        $stats = $this->service->getSystemCapacityStats();

        $this->assertEquals([
            'total_deliverers_with_responses' => 2,
            'deliverers_over_capacity' => 1,
            'total_active_responses' => 3,
            'capacity_utilization_rate' => 75.0, // 3 / (2 * 2) * 100
            'deliverer_details' => [
                [
                    'deliverer_id' => $deliverer1->id,
                    'current_load' => 2,
                    'max_capacity' => 2,
                    'is_at_capacity' => true
                ],
                [
                    'deliverer_id' => $deliverer2->id,
                    'current_load' => 1,
                    'max_capacity' => 2,
                    'is_at_capacity' => false
                ]
            ]
        ], $stats);
    }

    /** @test */
    public function it_correctly_identifies_over_capacity_deliverers()
    {
        $deliverer = User::factory()->create();

        // Mock capacity info showing over capacity
        $this->capacityMatchingService
            ->shouldReceive('getDelivererCapacityInfo')
            ->with($deliverer->id)
            ->once()
            ->andReturn([
                'is_at_capacity' => true
            ]);

        $result = $this->service->isDelivererOverCapacity($deliverer->id);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_handles_send_request_extraction_from_different_offer_types()
    {
        $deliverer = User::factory()->create();
        $sender = User::factory()->create();

        // Test offer_type = 'send' (send request is in offer_id)
        $sendRequest1 = SendRequest::factory()->create(['user_id' => $sender->id]);
        $response1 = Response::factory()->create([
            'user_id' => $deliverer->id,
            'responder_id' => $sender->id,
            'offer_type' => 'send',
            'offer_id' => $sendRequest1->id,
            'overall_status' => 'pending'
        ]);

        // Test offer_type = 'delivery' (send request is in request_id)
        $sendRequest2 = SendRequest::factory()->create(['user_id' => $sender->id]);
        $response2 = Response::factory()->create([
            'user_id' => $deliverer->id,
            'responder_id' => $sender->id,
            'offer_type' => 'delivery',
            'request_id' => $sendRequest2->id,
            'overall_status' => 'pending'
        ]);

        // Create accepted response to trigger rebalancing
        $acceptedResponse = Response::factory()->create([
            'user_id' => $deliverer->id,
            'response_type' => Response::TYPE_MATCHING,
            'overall_status' => 'partial'
        ]);

        // Mock capacity info showing over capacity
        $this->capacityMatchingService
            ->shouldReceive('getDelivererCapacityInfo')
            ->with($deliverer->id)
            ->once()
            ->andReturn([
                'current_load' => 3,
                'max_capacity' => 2
            ]);

        // Mock finding no alternatives (will auto-reject)
        $this->capacityMatchingService
            ->shouldReceive('findAlternativeDeliverers')
            ->andReturn(collect([]));

        // Mock notifications
        $this->notificationService
            ->shouldReceive('sendResponseNotification')
            ->twice();

        $this->service->rebalanceAfterAcceptance($acceptedResponse);

        // Both responses should be auto-rejected
        $this->assertDatabaseHas('responses', [
            'id' => $response1->id,
            'overall_status' => 'rejected'
        ]);

        $this->assertDatabaseHas('responses', [
            'id' => $response2->id,
            'overall_status' => 'rejected'
        ]);
    }
}