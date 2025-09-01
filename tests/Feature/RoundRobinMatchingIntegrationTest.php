<?php

namespace Tests\Feature;

use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Models\User;
use App\Services\Matcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoundRobinMatchingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set round-robin strategy for testing
        config(['capacity.distribution_strategy' => 'round_robin']);
        config(['capacity.max_deliverer_capacity' => 3]);
    }

    /** @test */
    public function it_distributes_matches_in_round_robin_fashion()
    {
        // Create 3 deliverers
        $deliverer1 = User::factory()->create(['name' => 'Deliverer 1']);
        $deliverer2 = User::factory()->create(['name' => 'Deliverer 2']);  
        $deliverer3 = User::factory()->create(['name' => 'Deliverer 3']);

        // Create their delivery requests (all with same route for matching)
        $delivery1 = DeliveryRequest::factory()->create([
            'user_id' => $deliverer1->id,
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);
        $delivery2 = DeliveryRequest::factory()->create([
            'user_id' => $deliverer2->id, 
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);
        $delivery3 = DeliveryRequest::factory()->create([
            'user_id' => $deliverer3->id,
            'from_location' => 'Location A', 
            'to_location' => 'Location B'
        ]);

        // Create 6 send requests from different senders
        $senders = User::factory()->count(6)->create();
        $sendRequests = [];
        
        foreach ($senders as $sender) {
            $sendRequests[] = SendRequest::factory()->create([
                'user_id' => $sender->id,
                'from_location' => 'Location A',
                'to_location' => 'Location B'
            ]);
        }

        /** @var Matcher $matcher */
        $matcher = app(Matcher::class);

        // Reset round-robin state
        app(\App\Services\Matching\RoundRobinDistributionService::class)->resetIndex();

        // Match each send request
        foreach ($sendRequests as $sendRequest) {
            $matcher->matchSendRequest($sendRequest);
        }

        // Verify round-robin distribution occurred
        $deliverer1Responses = \App\Models\Response::where('user_id', $deliverer1->id)->count();
        $deliverer2Responses = \App\Models\Response::where('user_id', $deliverer2->id)->count();
        $deliverer3Responses = \App\Models\Response::where('user_id', $deliverer3->id)->count();

        // Each deliverer should have received exactly 2 responses (6 requests / 3 deliverers = 2 each)
        $this->assertEquals(2, $deliverer1Responses, 'Deliverer 1 should have 2 responses');
        $this->assertEquals(2, $deliverer2Responses, 'Deliverer 2 should have 2 responses');
        $this->assertEquals(2, $deliverer3Responses, 'Deliverer 3 should have 2 responses');

        // Verify total responses created
        $totalResponses = \App\Models\Response::count();
        $this->assertEquals(6, $totalResponses, 'Total responses should equal send requests');

        // Verify all responses are for matching type
        $matchingResponses = \App\Models\Response::where('response_type', 'matching')->count();
        $this->assertEquals(6, $matchingResponses, 'All responses should be matching type');
    }

    /** @test */
    public function it_respects_capacity_limits_during_round_robin()
    {
        // Set capacity to 1 for this test
        config(['capacity.max_deliverer_capacity' => 1]);

        // Create 2 deliverers  
        $deliverer1 = User::factory()->create();
        $deliverer2 = User::factory()->create();

        // Create their delivery requests
        $delivery1 = DeliveryRequest::factory()->create([
            'user_id' => $deliverer1->id,
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);
        $delivery2 = DeliveryRequest::factory()->create([
            'user_id' => $deliverer2->id,
            'from_location' => 'Location A', 
            'to_location' => 'Location B'
        ]);

        // Create a response for deliverer1 to make them at capacity
        \App\Models\Response::factory()->create([
            'user_id' => $deliverer1->id,
            'overall_status' => 'pending'
        ]);

        // Create a send request
        $sendRequest = SendRequest::factory()->create([
            'from_location' => 'Location A',
            'to_location' => 'Location B' 
        ]);

        /** @var Matcher $matcher */
        $matcher = app(Matcher::class);
        $matcher->matchSendRequest($sendRequest);

        // Verify deliverer1 (at capacity) didn't get the new response
        $deliverer1NewResponses = \App\Models\Response::where('user_id', $deliverer1->id)
            ->where('offer_id', $sendRequest->id)
            ->count();
        $this->assertEquals(0, $deliverer1NewResponses, 'At-capacity deliverer should not get new responses');

        // Verify deliverer2 (available) got the response
        $deliverer2NewResponses = \App\Models\Response::where('user_id', $deliverer2->id)
            ->where('offer_id', $sendRequest->id)  
            ->count();
        $this->assertEquals(1, $deliverer2NewResponses, 'Available deliverer should get the response');
    }
}