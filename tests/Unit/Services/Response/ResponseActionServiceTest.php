<?php

namespace Tests\Unit\Services\Response;

use App\Enums\ChatStatus;
use App\Enums\DualStatus;
use App\Enums\RequestStatus;
use App\Enums\ResponseStatus;
use App\Enums\ResponseType;
use App\Models\Chat;
use App\Models\Response;
use App\Models\User;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\ResponseRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use App\Services\Matcher;
use App\Services\NotificationService;
use App\Services\Response\ResponseActionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class ResponseActionServiceTest extends TestCase
{
    protected ResponseActionService $service;
    protected Matcher $matcher;
    protected NotificationService $notificationService;
    protected ResponseRepositoryInterface $responseRepository;
    protected SendRequestRepositoryInterface $sendRequestRepository;
    protected DeliveryRequestRepositoryInterface $deliveryRequestRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->matcher = Mockery::mock(Matcher::class);
        $this->notificationService = Mockery::mock(NotificationService::class);
        $this->responseRepository = Mockery::mock(ResponseRepositoryInterface::class);
        $this->sendRequestRepository = Mockery::mock(SendRequestRepositoryInterface::class);
        $this->deliveryRequestRepository = Mockery::mock(DeliveryRequestRepositoryInterface::class);

        $this->service = new ResponseActionService(
            $this->matcher,
            $this->notificationService,
            $this->responseRepository,
            $this->sendRequestRepository,
            $this->deliveryRequestRepository
        );

        DB::shouldReceive('beginTransaction')->byDefault();
        DB::shouldReceive('commit')->byDefault();
        DB::shouldReceive('rollBack')->byDefault();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_accept_manual_response_successfully_accepts()
    {
        $user = User::factory()->make(['id' => 1, 'name' => 'Test User']);
        $responder = User::factory()->make(['id' => 2, 'name' => 'Responder']);
        $responseId = 10;

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('getAttribute')->with('id')->andReturn($responseId);
        $response->shouldReceive('getAttribute')->with('responder_id')->andReturn(2);
        $response->shouldReceive('getAttribute')->with('offer_type')->andReturn('send');
        $response->shouldReceive('getAttribute')->with('offer_id')->andReturn(100);

        $responseCollection = new Collection([$response]);

        $this->responseRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with([
                'id' => $responseId,
                'user_id' => 1,
                'response_type' => ResponseType::MANUAL->value,
                'overall_status' => ResponseStatus::PENDING->value
            ])
            ->andReturn($responseCollection);

        $this->sendRequestRepository
            ->shouldReceive('find')
            ->once()
            ->with(100)
            ->andReturn(Mockery::mock('App\Models\SendRequest'));

        User::shouldReceive('find')->with(2)->andReturn($responder);

        Chat::shouldReceive('where')->andReturnSelf();
        Chat::shouldReceive('orWhere')->andReturnSelf();
        Chat::shouldReceive('first')->andReturn(null);
        Chat::shouldReceive('create')->andReturn(Chat::factory()->make(['id' => 5]));

        $this->responseRepository
            ->shouldReceive('update')
            ->once()
            ->with($responseId, [
                'chat_id' => 5,
                'deliverer_status' => DualStatus::ACCEPTED->value,
                'sender_status' => DualStatus::ACCEPTED->value,
                'overall_status' => ResponseStatus::ACCEPTED->value,
            ]);

        $this->sendRequestRepository
            ->shouldReceive('updateStatus')
            ->once()
            ->with(100, RequestStatus::MATCHED_MANUALLY->value);

        $this->notificationService
            ->shouldReceive('sendAcceptanceNotification')
            ->once()
            ->with(2, 'Test User');

        $result = $this->service->acceptManualResponse($user, $responseId);

        $this->assertEquals(['chat_id' => 5, 'message' => 'Manual response accepted successfully'], $result);
    }

    public function test_accept_manual_response_throws_exception_when_response_not_found()
    {
        $user = User::factory()->make(['id' => 1]);
        $responseId = 10;

        $emptyCollection = new Collection();

        $this->responseRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn($emptyCollection);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Manual response not found');

        $this->service->acceptManualResponse($user, $responseId);
    }

    public function test_reject_manual_response_successfully_rejects()
    {
        $user = User::factory()->make(['id' => 1, 'name' => 'Test User']);
        $responseId = 10;

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('getAttribute')->with('responder_id')->andReturn(2);

        $responseCollection = new Collection([$response]);

        $this->responseRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with([
                'id' => $responseId,
                'user_id' => 1,
                'response_type' => ResponseType::MANUAL->value,
                'overall_status' => ResponseStatus::PENDING->value
            ])
            ->andReturn($responseCollection);

        $this->responseRepository
            ->shouldReceive('update')
            ->once()
            ->with($responseId, [
                'overall_status' => ResponseStatus::REJECTED->value,
            ]);

        $this->notificationService
            ->shouldReceive('sendRejectionNotification')
            ->once()
            ->with(2, 'Test User');

        $result = $this->service->rejectManualResponse($user, $responseId);

        $this->assertEquals(['message' => 'Manual response rejected successfully'], $result);
    }

    public function test_cancel_manual_response_successfully_cancels()
    {
        $user = User::factory()->make(['id' => 1]);
        $responseId = 10;

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('getAttribute')->with('offer_type')->andReturn('send');
        $response->shouldReceive('getAttribute')->with('offer_id')->andReturn(100);
        $response->shouldReceive('getAttribute')->with('id')->andReturn($responseId);

        $responseCollection = new Collection([$response]);
        $responseQuery = Mockery::mock();
        $responseQuery->shouldReceive('whereIn')
            ->with('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
            ->andReturn($responseQuery);
        $responseQuery->shouldReceive('first')->andReturn($response);

        $this->responseRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with([
                'id' => $responseId,
                'responder_id' => 1,
                'response_type' => ResponseType::MANUAL->value
            ])
            ->andReturn($responseQuery);

        $this->responseRepository
            ->shouldReceive('delete')
            ->once()
            ->with($responseId);

        // Mock checking for remaining responses
        $remainingResponsesCollection = new Collection();
        $this->responseRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with([
                'offer_id' => 100,
                'offer_type' => 'send',
                'response_type' => ResponseType::MANUAL->value
            ])
            ->andReturn($remainingResponsesCollection);

        $remainingResponsesCollection->shouldReceive('whereIn')
            ->with('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
            ->andReturn($remainingResponsesCollection);
        $remainingResponsesCollection->shouldReceive('isNotEmpty')->andReturn(false);

        $this->sendRequestRepository
            ->shouldReceive('find')
            ->once()
            ->with(100)
            ->andReturn(Mockery::mock('App\Models\SendRequest'));

        $this->sendRequestRepository
            ->shouldReceive('updateStatus')
            ->once()
            ->with(100, RequestStatus::OPEN->value);

        $result = $this->service->cancelManualResponse($user, $responseId);

        $this->assertEquals(['message' => 'Manual response cancelled successfully'], $result);
    }

    public function test_accept_matching_response_handles_deliverer_acceptance()
    {
        $user = User::factory()->make(['id' => 1]);
        $responseId = 10;

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('canUserTakeAction')->with(1)->andReturn(true);
        $response->shouldReceive('getUserRole')->with(1)->andReturn('deliverer');
        $response->shouldReceive('getAttribute')->with('offer_id')->andReturn(100);
        $response->shouldReceive('getAttribute')->with('request_id')->andReturn(200);

        $responseCollection = new Collection([$response]);
        $responseQuery = Mockery::mock();
        $responseQuery->shouldReceive('whereIn')
            ->with('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
            ->andReturn($responseQuery);
        $responseQuery->shouldReceive('first')->andReturn($response);

        $this->responseRepository
            ->shouldReceive('findWhere')
            ->once()
            ->andReturn($responseQuery);

        $this->sendRequestRepository
            ->shouldReceive('find')
            ->once()
            ->with(100)
            ->andReturn(Mockery::mock('App\Models\SendRequest'));

        $this->deliveryRequestRepository
            ->shouldReceive('find')
            ->once()
            ->with(200)
            ->andReturn(Mockery::mock('App\Models\DeliveryRequest'));

        $this->responseRepository
            ->shouldReceive('findMatchingResponse')
            ->once()
            ->with(100, 200)
            ->andReturn($response);

        $this->matcher
            ->shouldReceive('handleUserResponse')
            ->once()
            ->with($responseId, 1, 'accept')
            ->andReturn(true);

        $updatedResponse = Mockery::mock(Response::class);
        $updatedResponse->shouldReceive('getAttribute')->with('overall_status')->andReturn(ResponseStatus::ACCEPTED->value);
        $updatedResponse->shouldReceive('getAttribute')->with('chat_id')->andReturn(5);

        $this->responseRepository
            ->shouldReceive('find')
            ->once()
            ->with($responseId)
            ->andReturn($updatedResponse);

        $result = $this->service->acceptMatchingResponse($user, $responseId);

        $this->assertEquals(['message' => 'Both users accepted - partnership confirmed!', 'chat_id' => 5], $result);
    }
}