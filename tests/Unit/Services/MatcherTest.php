<?php

namespace Tests\Unit\Services;

use App\Models\DeliveryRequest;
use App\Models\Location;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Matcher;
use App\Services\TelegramNotificationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MatcherTest extends TestCase
{
    use RefreshDatabase;

    protected Matcher $matcher;
    protected TelegramNotificationService $telegramService;
    protected User $senderUser;
    protected User $delivererUser;
    protected Location $fromLocation;
    protected Location $toLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegramService = Mockery::mock(TelegramNotificationService::class);
        $this->matcher = new Matcher($this->telegramService);

        $this->senderUser = User::factory()->create();
        $this->delivererUser = User::factory()->create();
        $this->fromLocation = Location::factory()->create();
        $this->toLocation = Location::factory()->create();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_match_send_request_finds_compatible_delivery_requests()
    {
        // Create a delivery request that should match
        $deliveryRequest = DeliveryRequest::factory()->create([
            'user_id' => $this->delivererUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now(),
            'to_date' => Carbon::now()->addDays(5),
            'size_type' => 'small',
            'status' => 'open'
        ]);

        // Create telegram user for delivery user
        TelegramUser::factory()->create([
            'user_id' => $this->delivererUser->id,
            'telegram' => '123456789'
        ]);

        // Create send request
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now()->addDay(),
            'to_date' => Carbon::now()->addDays(3),
            'size_type' => 'small',
            'status' => 'open'
        ]);

        // Mock telegram service
        $this->telegramService->shouldReceive('buildDeliveryResponseKeyboard')->andReturn(['keyboard' => 'test']);
        $this->telegramService->shouldReceive('sendMessageWithKeyboard')->once();

        $this->matcher->matchSendRequest($sendRequest);

        // Assert response was created
        $this->assertDatabaseHas('responses', [
            'user_id' => $this->delivererUser->id,
            'responder_id' => $this->senderUser->id,
            'offer_type' => 'send',
            'request_id' => $deliveryRequest->id,
            'offer_id' => $sendRequest->id,
            'response_type' => 'matching'
        ]);

        // Assert delivery request status updated
        $this->assertDatabaseHas('delivery_requests', [
            'id' => $deliveryRequest->id,
            'status' => 'has_responses'
        ]);
    }

    public function test_match_send_request_excludes_same_user_delivery_requests()
    {
        // Create delivery request from same user
        DeliveryRequest::factory()->create([
            'user_id' => $this->senderUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now(),
            'to_date' => Carbon::now()->addDays(5),
            'status' => 'open'
        ]);

        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now()->addDay(),
            'to_date' => Carbon::now()->addDays(3),
            'status' => 'open'
        ]);

        $this->matcher->matchSendRequest($sendRequest);

        // Assert no response was created
        $this->assertDatabaseMissing('responses', [
            'user_id' => $this->senderUser->id
        ]);
    }

    public function test_match_send_request_only_matches_open_delivery_requests()
    {
        // Create closed delivery request
        DeliveryRequest::factory()->create([
            'user_id' => $this->delivererUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now(),
            'to_date' => Carbon::now()->addDays(5),
            'status' => 'closed'
        ]);

        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now()->addDay(),
            'to_date' => Carbon::now()->addDays(3),
            'status' => 'open'
        ]);

        $this->matcher->matchSendRequest($sendRequest);

        // Assert no response was created
        $this->assertDatabaseMissing('responses', [
            'responder_id' => $this->senderUser->id
        ]);
    }

    public function test_match_delivery_request_finds_compatible_send_requests()
    {
        // Create a send request that should match
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now()->addDay(),
            'to_date' => Carbon::now()->addDays(3),
            'size_type' => 'medium',
            'status' => 'open'
        ]);

        // Create telegram user for delivery user
        TelegramUser::factory()->create([
            'user_id' => $this->delivererUser->id,
            'telegram' => '123456789'
        ]);

        // Create delivery request
        $deliveryRequest = DeliveryRequest::factory()->create([
            'user_id' => $this->delivererUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now(),
            'to_date' => Carbon::now()->addDays(5),
            'size_type' => 'medium',
            'status' => 'open'
        ]);

        // Mock telegram service
        $this->telegramService->shouldReceive('buildDeliveryResponseKeyboard')->andReturn(['keyboard' => 'test']);
        $this->telegramService->shouldReceive('sendMessageWithKeyboard')->once();

        $this->matcher->matchDeliveryRequest($deliveryRequest);

        // Assert response was created
        $this->assertDatabaseHas('responses', [
            'user_id' => $this->delivererUser->id,
            'responder_id' => $this->senderUser->id,
            'offer_type' => 'delivery',
            'request_id' => $deliveryRequest->id,
            'offer_id' => $sendRequest->id,
            'response_type' => 'matching'
        ]);
    }

    public function test_match_send_request_handles_no_telegram_user_gracefully()
    {
        // Create delivery request without telegram user - set size_type to accept any size
        $deliveryRequest = DeliveryRequest::factory()->create([
            'user_id' => $this->delivererUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now(),
            'to_date' => Carbon::now()->addDays(5),
            'size_type' => 'Не указана',
            'status' => 'open'
        ]);

        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now()->addDay(),
            'to_date' => Carbon::now()->addDays(3),
            'size_type' => 'Маленькая',
            'status' => 'open'
        ]);

        // Should not throw exception
        $this->matcher->matchSendRequest($sendRequest);

        // Response should still be created
        $this->assertDatabaseHas('responses', [
            'user_id' => $this->delivererUser->id,
            'responder_id' => $this->senderUser->id
        ]);
    }

    public function test_create_deliverer_response_creates_response_for_sender_on_accept()
    {
        $sendRequest = SendRequest::factory()->create(['user_id' => $this->senderUser->id]);
        $deliveryRequest = DeliveryRequest::factory()->create(['user_id' => $this->delivererUser->id]);

        // Create telegram user for sender
        TelegramUser::factory()->create([
            'user_id' => $this->senderUser->id,
            'telegram' => '987654321'
        ]);

        // Create existing deliverer response
        Response::factory()->create([
            'user_id' => $this->delivererUser->id,
            'offer_type' => 'send',
            'request_id' => $deliveryRequest->id,
            'offer_id' => $sendRequest->id,
            'status' => 'pending'
        ]);

        // Mock telegram service
        $this->telegramService->shouldReceive('sendMessage')->once();

        $this->matcher->createDelivererResponse($sendRequest->id, $deliveryRequest->id, 'accept');

        // Assert response for sender was created
        $this->assertDatabaseHas('responses', [
            'user_id' => $this->senderUser->id,
            'responder_id' => $this->delivererUser->id,
            'offer_type' => 'delivery',
            'request_id' => $sendRequest->id,
            'offer_id' => $deliveryRequest->id,
            'status' => 'waiting'
        ]);

        // Assert deliverer response was updated
        $this->assertDatabaseHas('responses', [
            'user_id' => $this->delivererUser->id,
            'request_id' => $deliveryRequest->id,
            'offer_id' => $sendRequest->id,
            'status' => 'responded'
        ]);
    }

    public function test_create_deliverer_response_handles_reject_action()
    {
        $sendRequest = SendRequest::factory()->create(['user_id' => $this->senderUser->id]);
        $deliveryRequest = DeliveryRequest::factory()->create(['user_id' => $this->delivererUser->id]);

        // Create existing deliverer response
        Response::factory()->create([
            'user_id' => $this->delivererUser->id,
            'offer_type' => 'send',
            'request_id' => $deliveryRequest->id,
            'offer_id' => $sendRequest->id,
            'status' => 'pending'
        ]);

        $this->matcher->createDelivererResponse($sendRequest->id, $deliveryRequest->id, 'reject');

        // Assert deliverer response was updated to rejected
        $this->assertDatabaseHas('responses', [
            'user_id' => $this->delivererUser->id,
            'request_id' => $deliveryRequest->id,
            'offer_id' => $sendRequest->id,
            'status' => 'rejected'
        ]);

        // Assert no response for sender was created
        $this->assertDatabaseMissing('responses', [
            'user_id' => $this->senderUser->id,
            'responder_id' => $this->delivererUser->id
        ]);
    }

    public function test_create_deliverer_response_handles_missing_requests()
    {
        // Should not throw exception when requests don't exist
        $this->matcher->createDelivererResponse(999, 998, 'accept');

        // No database changes should occur
        $this->assertDatabaseMissing('responses', [
            'request_id' => 999
        ]);
    }

    public function test_size_type_matching_logic()
    {
        // Create delivery request with no size type specified
        $deliveryRequest = DeliveryRequest::factory()->create([
            'user_id' => $this->delivererUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now(),
            'to_date' => Carbon::now()->addDays(5),
            'size_type' => 'Не указана',
            'status' => 'open'
        ]);

        TelegramUser::factory()->create([
            'user_id' => $this->delivererUser->id,
            'telegram' => '123456789'
        ]);

        // Create send request with specific size
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::now()->addDay(),
            'to_date' => Carbon::now()->addDays(3),
            'size_type' => 'large',
            'status' => 'open'
        ]);

        $this->telegramService->shouldReceive('buildDeliveryResponseKeyboard')->andReturn(['keyboard' => 'test']);
        $this->telegramService->shouldReceive('sendMessageWithKeyboard')->once();

        $this->matcher->matchSendRequest($sendRequest);

        // Should match because delivery allows any size
        $this->assertDatabaseHas('responses', [
            'user_id' => $this->delivererUser->id,
            'offer_id' => $sendRequest->id
        ]);
    }

    public function test_date_overlap_matching_logic()
    {
        // Create delivery request that accepts any size
        $deliveryRequest = DeliveryRequest::factory()->create([
            'user_id' => $this->delivererUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::parse('2024-01-01'),
            'to_date' => Carbon::parse('2024-01-10'),
            'size_type' => 'Не указана',
            'status' => 'open'
        ]);

        TelegramUser::factory()->create([
            'user_id' => $this->delivererUser->id,
            'telegram' => '123456789'
        ]);

        // Create send request that overlaps
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
            'from_date' => Carbon::parse('2024-01-05'),
            'to_date' => Carbon::parse('2024-01-15'),
            'size_type' => 'Средняя',
            'status' => 'open'
        ]);

        $this->telegramService->shouldReceive('buildDeliveryResponseKeyboard')->andReturn(['keyboard' => 'test']);
        $this->telegramService->shouldReceive('sendMessageWithKeyboard')->once();

        $this->matcher->matchSendRequest($sendRequest);

        // Should match because dates overlap
        $this->assertDatabaseHas('responses', [
            'user_id' => $this->delivererUser->id,
            'offer_id' => $sendRequest->id
        ]);
    }
}
