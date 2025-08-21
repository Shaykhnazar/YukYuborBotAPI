<?php

namespace Tests\Unit\Services\Matching;

use App\Enums\DualStatus;
use App\Enums\RequestStatus;
use App\Enums\ResponseStatus;
use App\Models\Response;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\ResponseRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use App\Services\Matching\ResponseCreationService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ResponseCreationServiceTest extends TestCase
{
    protected ResponseCreationService $service;
    protected ResponseRepositoryInterface $responseRepository;
    protected SendRequestRepositoryInterface $sendRequestRepository;
    protected DeliveryRequestRepositoryInterface $deliveryRequestRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responseRepository = Mockery::mock(ResponseRepositoryInterface::class);
        $this->sendRequestRepository = Mockery::mock(SendRequestRepositoryInterface::class);
        $this->deliveryRequestRepository = Mockery::mock(DeliveryRequestRepositoryInterface::class);

        $this->service = new ResponseCreationService(
            $this->responseRepository,
            $this->sendRequestRepository,
            $this->deliveryRequestRepository
        );

        Log::swap(Mockery::mock('Psr\Log\LoggerInterface'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_matching_response_with_send_offer_type()
    {
        $userId = 1;
        $responderId = 2;
        $offerType = 'send';
        $requestId = 10;
        $offerId = 20;

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('getAttribute')->with('id')->andReturn(100);

        $this->responseRepository
            ->shouldReceive('updateOrCreateMatching')
            ->once()
            ->with(
                [
                    'user_id' => $userId,
                    'responder_id' => $responderId,
                    'offer_type' => $offerType,
                    'request_id' => $requestId,
                    'offer_id' => $offerId,
                ],
                [
                    'deliverer_status' => DualStatus::PENDING->value,
                    'sender_status' => DualStatus::PENDING->value,
                    'overall_status' => ResponseStatus::PENDING->value,
                    'message' => null
                ]
            )
            ->andReturn($response);

        $this->deliveryRequestRepository
            ->shouldReceive('updateStatus')
            ->once()
            ->with($requestId, RequestStatus::HAS_RESPONSES->value);

        Log::shouldReceive('info')->twice();

        $result = $this->service->createMatchingResponse($userId, $responderId, $offerType, $requestId, $offerId);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(100, $result->id);
    }

    public function test_create_matching_response_with_delivery_offer_type()
    {
        $userId = 1;
        $responderId = 2;
        $offerType = 'delivery';
        $requestId = 10;
        $offerId = 20;

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('getAttribute')->with('id')->andReturn(100);

        $this->responseRepository
            ->shouldReceive('updateOrCreateMatching')
            ->once()
            ->andReturn($response);

        $this->sendRequestRepository
            ->shouldReceive('updateStatus')
            ->once()
            ->with($requestId, RequestStatus::HAS_RESPONSES->value);

        Log::shouldReceive('info')->twice();

        $result = $this->service->createMatchingResponse($userId, $responderId, $offerType, $requestId, $offerId);

        $this->assertInstanceOf(Response::class, $result);
        $this->assertEquals(100, $result->id);
    }

    public function test_create_matching_response_logs_creation_details()
    {
        $userId = 1;
        $responderId = 2;
        $offerType = 'send';
        $requestId = 10;
        $offerId = 20;

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('getAttribute')->with('id')->andReturn(100);

        $this->responseRepository
            ->shouldReceive('updateOrCreateMatching')
            ->once()
            ->andReturn($response);

        $this->deliveryRequestRepository
            ->shouldReceive('updateStatus')
            ->once();

        Log::shouldReceive('info')
            ->once()
            ->with('Updated receiving DeliveryRequest status', [
                'delivery_request_id' => $requestId,
                'status' => RequestStatus::HAS_RESPONSES->value
            ]);

        Log::shouldReceive('info')
            ->once()
            ->with('Matching response created/updated', [
                'user_id' => $userId,
                'responder_id' => $responderId,
                'offer_type' => $offerType,
                'request_id' => $requestId,
                'offer_id' => $offerId,
                'response_id' => 100
            ]);

        $this->service->createMatchingResponse($userId, $responderId, $offerType, $requestId, $offerId);
    }

    public function test_create_matching_response_updates_delivery_request_status_for_send_offer()
    {
        $userId = 1;
        $responderId = 2;
        $offerType = 'send';
        $requestId = 10;
        $offerId = 20;

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('getAttribute')->with('id')->andReturn(100);

        $this->responseRepository
            ->shouldReceive('updateOrCreateMatching')
            ->once()
            ->andReturn($response);

        $this->deliveryRequestRepository
            ->shouldReceive('updateStatus')
            ->once()
            ->with($requestId, RequestStatus::HAS_RESPONSES->value);

        Log::shouldReceive('info')->twice();

        $this->service->createMatchingResponse($userId, $responderId, $offerType, $requestId, $offerId);
    }

    public function test_create_matching_response_updates_send_request_status_for_delivery_offer()
    {
        $userId = 1;
        $responderId = 2;
        $offerType = 'delivery';
        $requestId = 10;
        $offerId = 20;

        $response = Mockery::mock(Response::class);
        $response->shouldReceive('getAttribute')->with('id')->andReturn(100);

        $this->responseRepository
            ->shouldReceive('updateOrCreateMatching')
            ->once()
            ->andReturn($response);

        $this->sendRequestRepository
            ->shouldReceive('updateStatus')
            ->once()
            ->with($requestId, RequestStatus::HAS_RESPONSES->value);

        Log::shouldReceive('info')->twice();

        $this->service->createMatchingResponse($userId, $responderId, $offerType, $requestId, $offerId);
    }
}