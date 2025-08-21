<?php

namespace Tests\Unit\Services\Matching;

use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use App\Services\Matching\RequestMatchingService;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Tests\TestCase;

class RequestMatchingServiceTest extends TestCase
{
    protected RequestMatchingService $service;
    protected SendRequestRepositoryInterface $sendRequestRepository;
    protected DeliveryRequestRepositoryInterface $deliveryRequestRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sendRequestRepository = Mockery::mock(SendRequestRepositoryInterface::class);
        $this->deliveryRequestRepository = Mockery::mock(DeliveryRequestRepositoryInterface::class);

        $this->service = new RequestMatchingService(
            $this->sendRequestRepository,
            $this->deliveryRequestRepository
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_find_matching_delivery_requests_returns_collection()
    {
        $sendRequest = Mockery::mock(SendRequest::class);
        $deliveryRequest1 = Mockery::mock(DeliveryRequest::class);
        $deliveryRequest2 = Mockery::mock(DeliveryRequest::class);
        
        $expectedCollection = new Collection([$deliveryRequest1, $deliveryRequest2]);

        $this->deliveryRequestRepository
            ->shouldReceive('findMatchingForSend')
            ->once()
            ->with($sendRequest)
            ->andReturn($expectedCollection);

        $result = $this->service->findMatchingDeliveryRequests($sendRequest);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
    }

    public function test_find_matching_send_requests_returns_collection()
    {
        $deliveryRequest = Mockery::mock(DeliveryRequest::class);
        $sendRequest1 = Mockery::mock(SendRequest::class);
        $sendRequest2 = Mockery::mock(SendRequest::class);
        
        $expectedCollection = new Collection([$sendRequest1, $sendRequest2]);

        $this->sendRequestRepository
            ->shouldReceive('findMatchingForDelivery')
            ->once()
            ->with($deliveryRequest)
            ->andReturn($expectedCollection);

        $result = $this->service->findMatchingSendRequests($deliveryRequest);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
    }

    public function test_find_matching_delivery_requests_handles_empty_results()
    {
        $sendRequest = Mockery::mock(SendRequest::class);
        $emptyCollection = new Collection();

        $this->deliveryRequestRepository
            ->shouldReceive('findMatchingForSend')
            ->once()
            ->with($sendRequest)
            ->andReturn($emptyCollection);

        $result = $this->service->findMatchingDeliveryRequests($sendRequest);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_find_matching_send_requests_handles_empty_results()
    {
        $deliveryRequest = Mockery::mock(DeliveryRequest::class);
        $emptyCollection = new Collection();

        $this->sendRequestRepository
            ->shouldReceive('findMatchingForDelivery')
            ->once()
            ->with($deliveryRequest)
            ->andReturn($emptyCollection);

        $result = $this->service->findMatchingSendRequests($deliveryRequest);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }
}