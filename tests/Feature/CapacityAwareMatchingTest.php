<?php

namespace Tests\Feature;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\User;
use App\Services\Matcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapacityAwareMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set test capacity for consistent testing
        config(['capacity.max_deliverer_capacity' => 3]);
    }

    /** @test */
    public function it_distributes_send_requests_fairly_among_available_deliverers()
    {
        // Create 3 deliverers
        $deliverer1 = User::factory()->create();
        $deliverer2 = User::factory()->create();
        $deliverer3 = User::factory()->create();

        // Create delivery requests with matching criteria
        $delivery1 = DeliveryRequest::factory()->create([
            'user_id' => $deliverer1->id,
            'status' => 'open',
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);

        $delivery2 = DeliveryRequest::factory()->create([
            'user_id' => $deliverer2->id,
            'status' => 'open',
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);

        $delivery3 = DeliveryRequest::factory()->create([
            'user_id' => $deliverer3->id,
            'status' => 'open',
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);

        // Create multiple send requests
        $sendRequests = [];
        for ($i = 0; $i < 9; $i++) {
            $sender = User::factory()->create();
            $sendRequests[] = SendRequest::factory()->create([
                'user_id' => $sender->id,
                'status' => 'open',
                'from_location' => 'Location A',
                'to_location' => 'Location B'
            ]);
        }

        $matcher = app(Matcher::class);

        // Process each send request
        foreach ($sendRequests as $sendRequest) {
            $matcher->matchSendRequest($sendRequest);
        }

        // Verify fair distribution (each deliverer should have exactly 3 responses)
        $deliverer1Responses = Response::where('user_id', $deliverer1->id)->count();
        $deliverer2Responses = Response::where('user_id', $deliverer2->id)->count();
        $deliverer3Responses = Response::where('user_id', $deliverer3->id)->count();

        $this->assertEquals(3, $deliverer1Responses);
        $this->assertEquals(3, $deliverer2Responses);
        $this->assertEquals(3, $deliverer3Responses);
    }

    /** @test */
    public function it_stops_assigning_to_deliverers_at_capacity()
    {
        // Create 1 deliverer
        $deliverer = User::factory()->create();

        DeliveryRequest::factory()->create([
            'user_id' => $deliverer->id,
            'status' => 'open',
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);

        // Create more send requests than capacity allows
        $sendRequests = [];
        for ($i = 0; $i < 5; $i++) {
            $sender = User::factory()->create();
            $sendRequests[] = SendRequest::factory()->create([
                'user_id' => $sender->id,
                'status' => 'open',
                'from_location' => 'Location A',
                'to_location' => 'Location B'
            ]);
        }

        $matcher = app(Matcher::class);

        // Process each send request
        foreach ($sendRequests as $sendRequest) {
            $matcher->matchSendRequest($sendRequest);
        }

        // Should have only 3 responses (capacity limit)
        $responses = Response::where('user_id', $deliverer->id)->count();
        $this->assertEquals(3, $responses);
    }

    /** @test */
    public function it_rebalances_responses_after_deliverer_acceptance()
    {
        // Create deliverers
        $deliverer1 = User::factory()->create();
        $deliverer2 = User::factory()->create();

        // Create delivery requests
        $delivery1 = DeliveryRequest::factory()->create([
            'user_id' => $deliverer1->id,
            'status' => 'open',
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);

        $delivery2 = DeliveryRequest::factory()->create([
            'user_id' => $deliverer2->id,
            'status' => 'open',
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);

        // Create send requests and match them
        $senders = [];
        $sendRequests = [];
        for ($i = 0; $i < 4; $i++) {
            $senders[] = User::factory()->create();
            $sendRequests[] = SendRequest::factory()->create([
                'user_id' => $senders[$i]->id,
                'status' => 'open',
                'from_location' => 'Location A',
                'to_location' => 'Location B'
            ]);
        }

        $matcher = app(Matcher::class);

        // Match all send requests (should distribute among deliverers)
        foreach ($sendRequests as $sendRequest) {
            $matcher->matchSendRequest($sendRequest);
        }

        // Verify initial distribution
        $deliverer1InitialCount = Response::where('user_id', $deliverer1->id)->count();
        $deliverer2InitialCount = Response::where('user_id', $deliverer2->id)->count();
        
        $this->assertGreaterThan(0, $deliverer1InitialCount);
        $this->assertGreaterThan(0, $deliverer2InitialCount);

        // Get one of deliverer1's responses and accept it
        $responseToAccept = Response::where('user_id', $deliverer1->id)
            ->where('overall_status', 'pending')
            ->first();

        $this->assertNotNull($responseToAccept);

        // Accept the response (this should trigger rebalancing)
        $matcher->handleUserResponse($responseToAccept->id, $deliverer1->id, 'accept');

        // Refresh the response
        $responseToAccept->refresh();

        // Should now be partial (deliverer accepted, waiting for sender)
        $this->assertEquals('partial', $responseToAccept->overall_status);
        $this->assertEquals('accepted', $responseToAccept->deliverer_status);
        $this->assertEquals('pending', $responseToAccept->sender_status);

        // Check if rebalancing occurred (exact counts depend on implementation)
        $deliverer1FinalCount = Response::where('user_id', $deliverer1->id)
            ->whereIn('overall_status', ['pending', 'partial'])
            ->count();

        // Deliverer1 should now have at most 3 active responses
        $this->assertLessThanOrEqual(3, $deliverer1FinalCount);
    }

    /** @test */
    public function it_creates_chat_after_both_users_accept_matching_response()
    {
        // Create users
        $deliverer = User::factory()->create();
        $sender = User::factory()->create();

        // Create delivery request
        $delivery = DeliveryRequest::factory()->create([
            'user_id' => $deliverer->id,
            'status' => 'open',
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);

        // Create send request
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $sender->id,
            'status' => 'open',
            'from_location' => 'Location A',
            'to_location' => 'Location B'
        ]);

        $matcher = app(Matcher::class);

        // Match the send request
        $matcher->matchSendRequest($sendRequest);

        // Get the created response
        $response = Response::where('user_id', $deliverer->id)
            ->where('responder_id', $sender->id)
            ->first();

        $this->assertNotNull($response);
        $this->assertEquals('pending', $response->overall_status);

        // Deliverer accepts first
        $result1 = $matcher->handleUserResponse($response->id, $deliverer->id, 'accept');
        $this->assertTrue($result1);

        $response->refresh();
        $this->assertEquals('partial', $response->overall_status);
        $this->assertNull($response->chat_id); // No chat yet

        // Sender accepts second
        $result2 = $matcher->handleUserResponse($response->id, $sender->id, 'accept');
        $this->assertTrue($result2);

        $response->refresh();
        $this->assertEquals('accepted', $response->overall_status);
        $this->assertNotNull($response->chat_id); // Chat should be created

        // Verify chat exists
        $this->assertDatabaseHas('chats', [
            'id' => $response->chat_id,
            'sender_id' => $sender->id,
            'receiver_id' => $deliverer->id,
            'status' => 'active'
        ]);

        // Verify requests are marked as matched
        $sendRequest->refresh();
        $delivery->refresh();

        $this->assertEquals('matched', $sendRequest->status);
        $this->assertEquals('matched', $delivery->status);
        $this->assertEquals($delivery->id, $sendRequest->matched_delivery_id);
        $this->assertEquals($sendRequest->id, $delivery->matched_send_id);
    }

    /** @test */
    public function it_handles_rejection_properly()
    {
        // Create users
        $deliverer = User::factory()->create();
        $sender = User::factory()->create();

        // Create delivery request
        $delivery = DeliveryRequest::factory()->create([
            'user_id' => $deliverer->id,
            'status' => 'open'
        ]);

        // Create send request
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $sender->id,
            'status' => 'open'
        ]);

        $matcher = app(Matcher::class);

        // Create a response manually for testing
        $response = Response::create([
            'user_id' => $deliverer->id,
            'responder_id' => $sender->id,
            'offer_type' => 'send',
            'request_id' => $delivery->id,
            'offer_id' => $sendRequest->id,
            'response_type' => 'matching',
            'deliverer_status' => 'pending',
            'sender_status' => 'pending',
            'overall_status' => 'pending'
        ]);

        // Deliverer rejects
        $result = $matcher->handleUserResponse($response->id, $deliverer->id, 'reject');
        $this->assertTrue($result);

        $response->refresh();
        $this->assertEquals('rejected', $response->overall_status);
        $this->assertEquals('rejected', $response->deliverer_status);
        $this->assertNull($response->chat_id); // No chat created
    }
}