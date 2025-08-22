<?php

namespace Tests\Unit\Services;

use App\Models\DeliveryRequest;
use App\Models\Location;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\Matcher;
use App\Services\Matching\RequestMatchingService;
use App\Services\Matching\ResponseCreationService;
use App\Services\Matching\ResponseStatusService;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class MatcherTest extends TestCase
{
    use RefreshDatabase;

    protected Matcher $matcher;
    protected RequestMatchingService $matchingService;
    protected ResponseCreationService $creationService;
    protected ResponseStatusService $statusService;
    protected User $senderUser;
    protected User $delivererUser;
    protected Location $fromLocation;
    protected Location $toLocation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegramService = Mockery::mock(NotificationService::class);
        $this->matchingService = Mockery::mock(RequestMatchingService::class);
        $this->creationService = Mockery::mock(ResponseCreationService::class);
        $this->statusService = Mockery::mock(ResponseStatusService::class);

        $this->matcher = new Matcher(
            $this->telegramService,
            $this->matchingService,
            $this->creationService,
            $this->statusService
        );

        $this->senderUser = User::factory()->create();
        $this->delivererUser = User::factory()->create();
        $this->fromLocation = Location::factory()->create();
        $this->toLocation = Location::factory()->create();

        Log::swap(Mockery::mock('Psr\Log\LoggerInterface'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_match_send_request_calls_matching_service()
    {
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
            'from_location_id' => $this->fromLocation->id,
            'to_location_id' => $this->toLocation->id,
        ]);

        $emptyCollection = new Collection();

        $this->matchingService
            ->shouldReceive('findMatchingDeliveryRequests')
            ->once()
            ->with($sendRequest)
            ->andReturn($emptyCollection);

        Log::shouldReceive('info')->once()->with('Send request matching completed', [
            'send_request_id' => $sendRequest->id,
            'matches_found' => 0
        ]);

        $this->matcher->matchSendRequest($sendRequest);
    }

    public function test_match_send_request_creates_responses_for_matched_deliveries()
    {
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
        ]);

        $deliveryRequest1 = DeliveryRequest::factory()->create([
            'user_id' => $this->delivererUser->id,
        ]);

        $deliveryRequest2 = DeliveryRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $matchedDeliveries = new Collection([$deliveryRequest1, $deliveryRequest2]);

        $this->matchingService
            ->shouldReceive('findMatchingDeliveryRequests')
            ->once()
            ->with($sendRequest)
            ->andReturn($matchedDeliveries);

        // Expect creation service to be called for each matched delivery
        $this->creationService
            ->shouldReceive('createMatchingResponse')
            ->twice();

        // Mock telegram notifications
        $this->telegramService->shouldReceive('buildDeliveryResponseKeyboard')->twice()->andReturn(['keyboard' => 'test']);
        $this->telegramService->shouldReceive('sendMessageWithKeyboard')->twice();

        Log::shouldReceive('info')->once();

        $this->matcher->matchSendRequest($sendRequest);
    }

    public function test_match_delivery_request_calls_matching_service()
    {
        $deliveryRequest = DeliveryRequest::factory()->create([
            'user_id' => $this->delivererUser->id,
        ]);

        $emptyCollection = new Collection();

        $this->matchingService
            ->shouldReceive('findMatchingSendRequests')
            ->once()
            ->with($deliveryRequest)
            ->andReturn($emptyCollection);

        Log::shouldReceive('info')->once()->with('Delivery request matching completed', [
            'delivery_request_id' => $deliveryRequest->id,
            'matches_found' => 0
        ]);

        $this->matcher->matchDeliveryRequest($deliveryRequest);
    }

    public function test_match_delivery_request_creates_responses_for_matched_sends()
    {
        $deliveryRequest = DeliveryRequest::factory()->create([
            'user_id' => $this->delivererUser->id,
        ]);

        $sendRequest1 = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
        ]);

        $sendRequest2 = SendRequest::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);

        $matchedSends = new Collection([$sendRequest1, $sendRequest2]);

        $this->matchingService
            ->shouldReceive('findMatchingSendRequests')
            ->once()
            ->with($deliveryRequest)
            ->andReturn($matchedSends);

        // Expect creation service to be called for each matched send
        $this->creationService
            ->shouldReceive('createMatchingResponse')
            ->twice();

        // Mock telegram notifications
        $this->telegramService->shouldReceive('buildDeliveryResponseKeyboard')->twice()->andReturn(['keyboard' => 'test']);
        $this->telegramService->shouldReceive('sendMessageWithKeyboard')->twice();

        Log::shouldReceive('info')->once();

        $this->matcher->matchDeliveryRequest($deliveryRequest);
    }

    public function test_handle_user_response_with_accept_action()
    {
        $responseId = 1;
        $userId = $this->senderUser->id;
        $action = 'accept';

        $response = Response::factory()->make(['id' => $responseId]);

        Response::shouldReceive('find')
            ->once()
            ->with($responseId)
            ->andReturn($response);

        $this->statusService
            ->shouldReceive('updateUserStatus')
            ->once()
            ->with($response, $userId, 'accepted')
            ->andReturn(true);

        $response->shouldReceive('fresh')->andReturn($response);
        $response->shouldReceive('getAttribute')->with('overall_status')->andReturn('accepted');

        Log::shouldReceive('info')->once()->with('User response handled successfully', [
            'response_id' => $responseId,
            'user_id' => $userId,
            'action' => $action,
            'overall_status' => 'accepted'
        ]);

        $result = $this->matcher->handleUserResponse($responseId, $userId, $action);

        $this->assertTrue($result);
    }

    public function test_handle_user_response_with_reject_action()
    {
        $responseId = 1;
        $userId = $this->senderUser->id;
        $action = 'reject';

        $response = Response::factory()->make(['id' => $responseId]);

        Response::shouldReceive('find')
            ->once()
            ->with($responseId)
            ->andReturn($response);

        $this->statusService
            ->shouldReceive('updateUserStatus')
            ->once()
            ->with($response, $userId, 'rejected')
            ->andReturn(true);

        $response->shouldReceive('fresh')->andReturn($response);
        $response->shouldReceive('getAttribute')->with('overall_status')->andReturn('rejected');

        Log::shouldReceive('info')->once();

        $result = $this->matcher->handleUserResponse($responseId, $userId, $action);

        $this->assertTrue($result);
    }

    public function test_handle_user_response_returns_false_when_response_not_found()
    {
        $responseId = 999;
        $userId = $this->senderUser->id;
        $action = 'accept';

        Response::shouldReceive('find')
            ->once()
            ->with($responseId)
            ->andReturn(null);

        Log::shouldReceive('warning')->once()->with('Response not found', ['response_id' => $responseId]);

        $result = $this->matcher->handleUserResponse($responseId, $userId, $action);

        $this->assertFalse($result);
    }

    public function test_handle_user_response_returns_false_when_status_update_fails()
    {
        $responseId = 1;
        $userId = $this->senderUser->id;
        $action = 'accept';

        $response = Response::factory()->make(['id' => $responseId]);

        Response::shouldReceive('find')
            ->once()
            ->with($responseId)
            ->andReturn($response);

        $this->statusService
            ->shouldReceive('updateUserStatus')
            ->once()
            ->with($response, $userId, 'accepted')
            ->andReturn(false);

        Log::shouldReceive('warning')->once()->with('Failed to update user status', [
            'response_id' => $responseId,
            'user_id' => $userId,
            'action' => $action
        ]);

        $result = $this->matcher->handleUserResponse($responseId, $userId, $action);

        $this->assertFalse($result);
    }

    public function test_notification_handles_user_without_telegram()
    {
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->senderUser->id,
        ]);

        $deliveryRequest = DeliveryRequest::factory()->create([
            'user_id' => $this->delivererUser->id,
        ]);

        $matchedDeliveries = new Collection([$deliveryRequest]);

        $this->matchingService
            ->shouldReceive('findMatchingDeliveryRequests')
            ->once()
            ->with($sendRequest)
            ->andReturn($matchedDeliveries);

        $this->creationService
            ->shouldReceive('createMatchingResponse')
            ->once();

        // Mock user without telegram
        $this->delivererUser->load('user'); // This should load null

        Log::shouldReceive('warning')->once();
        Log::shouldReceive('info')->once();

        $this->matcher->matchSendRequest($sendRequest);
    }
}
