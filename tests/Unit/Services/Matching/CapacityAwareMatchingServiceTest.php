<?php

namespace Tests\Unit\Services\Matching;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\User;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use App\Services\Matching\CapacityAwareMatchingService;
use App\Services\Matching\RoundRobinDistributionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapacityAwareMatchingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CapacityAwareMatchingService $service;
    protected SendRequestRepositoryInterface $sendRequestRepository;
    protected DeliveryRequestRepositoryInterface $deliveryRequestRepository;
    protected RoundRobinDistributionService $roundRobinService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sendRequestRepository = $this->mock(SendRequestRepositoryInterface::class);
        $this->deliveryRequestRepository = $this->mock(DeliveryRequestRepositoryInterface::class);
        $this->roundRobinService = $this->mock(RoundRobinDistributionService::class);

        $this->service = new CapacityAwareMatchingService(
            $this->sendRequestRepository,
            $this->deliveryRequestRepository,
            $this->roundRobinService
        );

        // Set test capacity to 2 for easier testing
        config(['capacity.max_deliverer_capacity' => 2]);
    }

    /** @test */
    public function it_returns_only_deliverers_with_capacity_for_send_requests()
    {
        // Create test users
        $deliverer1 = User::factory()->create();
        $deliverer2 = User::factory()->create();
        $deliverer3 = User::factory()->create();
        $sender = User::factory()->create();

        // Create delivery requests
        $delivery1 = DeliveryRequest::factory()->create(['user_id' => $deliverer1->id]);
        $delivery2 = DeliveryRequest::factory()->create(['user_id' => $deliverer2->id]);
        $delivery3 = DeliveryRequest::factory()->create(['user_id' => $deliverer3->id]);

        // Create send request
        $sendRequest = SendRequest::factory()->create(['user_id' => $sender->id]);

        // Mock the parent method to return all deliveries
        $allDeliveries = new Collection([$delivery1, $delivery2, $delivery3]);
        $this->deliveryRequestRepository
            ->shouldReceive('findMatchingForSend')
            ->with($sendRequest)
            ->once()
            ->andReturn($allDeliveries);

        // Create responses to simulate capacity usage
        // Deliverer1: 0 responses (available)
        // Deliverer2: 1 response (available) 
        // Deliverer3: 2 responses (at capacity)
        Response::factory()->create(['user_id' => $deliverer2->id, 'overall_status' => 'pending']);
        Response::factory()->create(['user_id' => $deliverer3->id, 'overall_status' => 'pending']);
        Response::factory()->create(['user_id' => $deliverer3->id, 'overall_status' => 'partial']);

        // Test the capacity-aware matching
        $result = $this->service->findMatchingDeliveryRequestsWithCapacity($sendRequest);

        // Should return only deliverer1 (least loaded with 0 responses)
        $this->assertCount(1, $result);
        $this->assertEquals($deliverer1->id, $result->first()->user_id);
    }

    /** @test */
    public function it_prioritizes_least_loaded_deliverers()
    {
        // Create test users
        $deliverer1 = User::factory()->create();
        $deliverer2 = User::factory()->create();
        $sender = User::factory()->create();

        // Create delivery requests
        $delivery1 = DeliveryRequest::factory()->create(['user_id' => $deliverer1->id]);
        $delivery2 = DeliveryRequest::factory()->create(['user_id' => $deliverer2->id]);

        // Create send request
        $sendRequest = SendRequest::factory()->create(['user_id' => $sender->id]);

        // Mock the parent method
        $allDeliveries = new Collection([$delivery1, $delivery2]);
        $this->deliveryRequestRepository
            ->shouldReceive('findMatchingForSend')
            ->with($sendRequest)
            ->once()
            ->andReturn($allDeliveries);

        // Give deliverer2 one response (deliverer1 has 0)
        Response::factory()->create(['user_id' => $deliverer2->id, 'overall_status' => 'pending']);

        // Test the capacity-aware matching
        $result = $this->service->findMatchingDeliveryRequestsWithCapacity($sendRequest);

        // Should return deliverer1 (least loaded)
        $this->assertCount(1, $result);
        $this->assertEquals($deliverer1->id, $result->first()->user_id);
    }

    /** @test */
    public function it_respects_deliverer_capacity_for_delivery_requests()
    {
        // Create test users
        $deliverer = User::factory()->create();
        $sender1 = User::factory()->create();
        $sender2 = User::factory()->create();
        $sender3 = User::factory()->create();

        // Create delivery request
        $deliveryRequest = DeliveryRequest::factory()->create(['user_id' => $deliverer->id]);

        // Create send requests
        $send1 = SendRequest::factory()->create(['user_id' => $sender1->id]);
        $send2 = SendRequest::factory()->create(['user_id' => $sender2->id]);
        $send3 = SendRequest::factory()->create(['user_id' => $sender3->id]);

        // Mock the parent method to return all sends
        $allSends = new Collection([$send1, $send2, $send3]);
        $this->sendRequestRepository
            ->shouldReceive('findMatchingForDelivery')
            ->with($deliveryRequest)
            ->once()
            ->andReturn($allSends);

        // Deliverer already has 2 responses (at capacity)
        Response::factory()->create(['user_id' => $deliverer->id, 'overall_status' => 'pending']);
        Response::factory()->create(['user_id' => $deliverer->id, 'overall_status' => 'partial']);

        // Test the capacity-aware matching
        $result = $this->service->findMatchingSendRequestsWithCapacity($deliveryRequest);

        // Should return empty collection (deliverer at capacity)
        $this->assertCount(0, $result);
    }

    /** @test */
    public function it_returns_limited_matches_when_partial_capacity_available()
    {
        // Create test users
        $deliverer = User::factory()->create();
        $sender1 = User::factory()->create();
        $sender2 = User::factory()->create();
        $sender3 = User::factory()->create();

        // Create delivery request
        $deliveryRequest = DeliveryRequest::factory()->create(['user_id' => $deliverer->id]);

        // Create send requests
        $send1 = SendRequest::factory()->create(['user_id' => $sender1->id]);
        $send2 = SendRequest::factory()->create(['user_id' => $sender2->id]);
        $send3 = SendRequest::factory()->create(['user_id' => $sender3->id]);

        // Mock the parent method
        $allSends = new Collection([$send1, $send2, $send3]);
        $this->sendRequestRepository
            ->shouldReceive('findMatchingForDelivery')
            ->with($deliveryRequest)
            ->once()
            ->andReturn($allSends);

        // Deliverer has 1 response (1 capacity remaining)
        Response::factory()->create(['user_id' => $deliverer->id, 'overall_status' => 'pending']);

        // Test the capacity-aware matching
        $result = $this->service->findMatchingSendRequestsWithCapacity($deliveryRequest);

        // Should return only 1 match (limited by available capacity)
        $this->assertCount(1, $result);
    }

    /** @test */
    public function it_correctly_counts_active_responses()
    {
        $deliverer = User::factory()->create();

        // Create various response statuses
        Response::factory()->create(['user_id' => $deliverer->id, 'overall_status' => 'pending']);
        Response::factory()->create(['user_id' => $deliverer->id, 'overall_status' => 'partial']);
        Response::factory()->create(['user_id' => $deliverer->id, 'overall_status' => 'accepted']); // Should not count
        Response::factory()->create(['user_id' => $deliverer->id, 'overall_status' => 'rejected']); // Should not count

        $activeCount = $this->service->getDelivererActiveResponses($deliverer->id);

        $this->assertEquals(2, $activeCount);
    }

    /** @test */
    public function it_provides_detailed_capacity_information()
    {
        $deliverer = User::factory()->create();

        // Create responses with different statuses
        Response::factory()->create(['user_id' => $deliverer->id, 'overall_status' => 'pending']);
        Response::factory()->create(['user_id' => $deliverer->id, 'overall_status' => 'partial']);

        $capacityInfo = $this->service->getDelivererCapacityInfo($deliverer->id);

        $this->assertEquals([
            'deliverer_id' => $deliverer->id,
            'max_capacity' => 2,
            'current_load' => 2,
            'available_capacity' => 0,
            'pending_responses' => 1,
            'partial_responses' => 1,
            'is_at_capacity' => true
        ], $capacityInfo);
    }

    /** @test */
    public function it_finds_alternative_deliverers_excluding_current_one()
    {
        // Create test users
        $currentDeliverer = User::factory()->create();
        $alternative1 = User::factory()->create();
        $alternative2 = User::factory()->create();
        $sender = User::factory()->create();

        // Create delivery requests
        $currentDelivery = DeliveryRequest::factory()->create(['user_id' => $currentDeliverer->id]);
        $altDelivery1 = DeliveryRequest::factory()->create(['user_id' => $alternative1->id]);
        $altDelivery2 = DeliveryRequest::factory()->create(['user_id' => $alternative2->id]);

        // Create send request
        $sendRequest = SendRequest::factory()->create(['user_id' => $sender->id]);

        // Mock the parent method
        $allDeliveries = new Collection([$currentDelivery, $altDelivery1, $altDelivery2]);
        $this->deliveryRequestRepository
            ->shouldReceive('findMatchingForSend')
            ->with($sendRequest)
            ->once()
            ->andReturn($allDeliveries);

        // Make alternative2 at capacity
        Response::factory()->create(['user_id' => $alternative2->id, 'overall_status' => 'pending']);
        Response::factory()->create(['user_id' => $alternative2->id, 'overall_status' => 'pending']);

        $alternatives = $this->service->findAlternativeDeliverers($sendRequest, $currentDeliverer->id);

        // Should return only alternative1 (alternative2 at capacity, current excluded)
        $this->assertCount(1, $alternatives);
        $this->assertEquals($alternative1->id, $alternatives->first()->user_id);
    }
}