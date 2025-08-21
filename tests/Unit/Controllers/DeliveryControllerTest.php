<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\DeliveryController;
use App\Http\Requests\Delivery\CreateDeliveryRequest;
use App\Models\DeliveryRequest;
use App\Models\Location;
use App\Models\User;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Services\RequestService;
use App\Services\TelegramUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class DeliveryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected DeliveryController $controller;
    protected TelegramUserService $telegramUserService;
    protected RequestService $requestService;
    protected DeliveryRequestRepositoryInterface $deliveryRequestRepository;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegramUserService = Mockery::mock(TelegramUserService::class);
        $this->requestService = Mockery::mock(RequestService::class);
        $this->deliveryRequestRepository = Mockery::mock(DeliveryRequestRepositoryInterface::class);

        $this->controller = new DeliveryController(
            $this->telegramUserService,
            $this->requestService,
            $this->deliveryRequestRepository
        );

        $this->user = User::factory()->make(['id' => 1, 'name' => 'Test User']);
        
        Log::swap(Mockery::mock('Psr\Log\LoggerInterface'));
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createMockRequest(array $requestData): CreateDeliveryRequest
    {
        $mockRequest = Mockery::mock(CreateDeliveryRequest::class);

        $mockRequest->shouldReceive('getDTO')
            ->once()
            ->andReturn(new \App\Http\DTO\DeliveryRequest\CreateDeliveryRequestDTO(
                $requestData['from_location_id'],
                $requestData['to_location_id'],
                $requestData['description'] ?? null,
                \Carbon\CarbonImmutable::parse($requestData['from_date']),
                \Carbon\CarbonImmutable::parse($requestData['to_date']),
                $requestData['price'] ?? null,
                $requestData['currency'] ?? null
            ));

        return $mockRequest;
    }

    public function test_create_calls_request_service_to_check_limit()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        $requestData = [
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Test delivery'
        ];

        $mockRequest = $this->createMockRequest($requestData);

        $this->telegramUserService
            ->shouldReceive('getUserByTelegramId')
            ->once()
            ->with($mockRequest)
            ->andReturn($this->user);

        $this->requestService
            ->shouldReceive('checkActiveRequestsLimit')
            ->once()
            ->with($this->user);

        $deliveryRequest = DeliveryRequest::factory()->make(['id' => 1]);

        $this->deliveryRequestRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn($deliveryRequest);

        Queue::shouldReceive('push')->once();
        
        $response = $this->controller->create($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_create_handles_limit_exceeded_exception()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        $requestData = [
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ];

        $mockRequest = $this->createMockRequest($requestData);

        $this->telegramUserService
            ->shouldReceive('getUserByTelegramId')
            ->once()
            ->with($mockRequest)
            ->andReturn($this->user);

        $this->requestService
            ->shouldReceive('checkActiveRequestsLimit')
            ->once()
            ->with($this->user)
            ->andThrow(new \Exception('Request limit exceeded'));

        Log::shouldReceive('error')->once();

        $response = $this->controller->create($mockRequest);

        $this->assertEquals(422, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertEquals('Request limit exceeded', $data['error']);
    }

    public function test_create_saves_delivery_request_with_correct_data()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        $requestData = [
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Professional delivery service',
            'price' => 250,
            'currency' => 'USD'
        ];

        $mockRequest = $this->createMockRequest($requestData);

        $this->telegramUserService
            ->shouldReceive('getUserByTelegramId')
            ->once()
            ->with($mockRequest)
            ->andReturn($this->user);

        $this->requestService
            ->shouldReceive('checkActiveRequestsLimit')
            ->once()
            ->with($this->user);

        $deliveryRequest = DeliveryRequest::factory()->make(['id' => 1]);

        $this->deliveryRequestRepository
            ->shouldReceive('create')
            ->once()
            ->with([
                'from_location_id' => $fromLocation->id,
                'to_location_id' => $toLocation->id,
                'description' => 'Professional delivery service',
                'from_date' => now()->addDays(1)->toDateString(),
                'to_date' => now()->addDays(7)->toDateString(),
                'price' => 250,
                'currency' => 'USD',
                'user_id' => 1,
                'status' => 'open',
            ])
            ->andReturn($deliveryRequest);

        Queue::shouldReceive('push')->once();

        $response = $this->controller->create($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_create_dispatches_matching_job()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        $requestData = [
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ];

        $mockRequest = $this->createMockRequest($requestData);

        $this->telegramUserService
            ->shouldReceive('getUserByTelegramId')
            ->once()
            ->andReturn($this->user);

        $this->requestService
            ->shouldReceive('checkActiveRequestsLimit')
            ->once();

        $deliveryRequest = DeliveryRequest::factory()->make(['id' => 123]);

        $this->deliveryRequestRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn($deliveryRequest);

        Queue::shouldReceive('push')
            ->once()
            ->with(Mockery::type('App\Jobs\MatchRequestsJob'));

        $response = $this->controller->create($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_delete_successfully_deletes_request()
    {
        $deliveryRequest = DeliveryRequest::factory()->make(['id' => 1]);

        $mockRequest = Mockery::mock(\Illuminate\Http\Request::class);

        $this->telegramUserService
            ->shouldReceive('getUserByTelegramId')
            ->once()
            ->with($mockRequest)
            ->andReturn($this->user);

        $this->deliveryRequestRepository
            ->shouldReceive('findByUserAndId')
            ->once()
            ->with($this->user, 1)
            ->andReturn($deliveryRequest);

        $this->requestService
            ->shouldReceive('deleteRequest')
            ->once()
            ->with($deliveryRequest);

        $response = $this->controller->delete($mockRequest, 1);

        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Request deleted successfully', $data['message']);
    }

    public function test_delete_returns_404_when_request_not_found()
    {
        $mockRequest = Mockery::mock(\Illuminate\Http\Request::class);

        $this->telegramUserService
            ->shouldReceive('getUserByTelegramId')
            ->once()
            ->with($mockRequest)
            ->andReturn($this->user);

        $this->deliveryRequestRepository
            ->shouldReceive('findByUserAndId')
            ->once()
            ->with($this->user, 999)
            ->andReturn(null);

        $response = $this->controller->delete($mockRequest, 999);

        $this->assertEquals(404, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Request not found', $data['error']);
    }

    public function test_close_successfully_closes_request()
    {
        $deliveryRequest = DeliveryRequest::factory()->make(['id' => 1]);

        $mockRequest = Mockery::mock(\Illuminate\Http\Request::class);

        $this->telegramUserService
            ->shouldReceive('getUserByTelegramId')
            ->once()
            ->with($mockRequest)
            ->andReturn($this->user);

        $this->deliveryRequestRepository
            ->shouldReceive('findByUserAndId')
            ->once()
            ->with($this->user, 1)
            ->andReturn($deliveryRequest);

        $this->requestService
            ->shouldReceive('closeRequest')
            ->once()
            ->with($deliveryRequest);

        $response = $this->controller->close($mockRequest, 1);

        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Delivery request closed successfully', $data['message']);
    }

    public function test_index_returns_user_delivery_requests()
    {
        $deliveryRequests = collect([
            DeliveryRequest::factory()->make(['id' => 1]),
            DeliveryRequest::factory()->make(['id' => 2])
        ]);

        $mockRequest = Mockery::mock(\Illuminate\Http\Request::class);

        $this->telegramUserService
            ->shouldReceive('getUserByTelegramId')
            ->once()
            ->with($mockRequest)
            ->andReturn($this->user);

        $this->deliveryRequestRepository
            ->shouldReceive('findActiveByUser')
            ->once()
            ->with($this->user)
            ->andReturn($deliveryRequests);

        $response = $this->controller->index($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        $this->assertCount(2, $data);
    }

    public function test_create_handles_null_optional_fields()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        $requestData = [
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => null,
            'price' => null,
            'currency' => null
        ];

        $mockRequest = $this->createMockRequest($requestData);

        $this->telegramUserService
            ->shouldReceive('getUserByTelegramId')
            ->once()
            ->andReturn($this->user);

        $this->requestService
            ->shouldReceive('checkActiveRequestsLimit')
            ->once();

        $this->deliveryRequestRepository
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['description'] === null && 
                       $data['price'] === null && 
                       $data['currency'] === null;
            }))
            ->andReturn(DeliveryRequest::factory()->make(['id' => 1]));

        Queue::shouldReceive('push')->once();

        $response = $this->controller->create($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());
    }
}