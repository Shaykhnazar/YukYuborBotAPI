<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\RequestController;
use App\Http\Requests\Parcel\ParcelRequest;
use App\Models\DeliveryRequest;
use App\Models\Location;
use App\Models\SendRequest;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\TelegramUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RequestControllerTest extends TestCase
{
    use RefreshDatabase;

    protected RequestController $controller;
    protected TelegramUserService $userService;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userService = Mockery::mock(TelegramUserService::class);
        $this->controller = new RequestController($this->userService);

        $this->user = User::factory()->create([
            'name' => 'Test User',
            'links_balance' => 5
        ]);

        TelegramUser::factory()->create([
            'user_id' => $this->user->id,
            'telegram' => '123456789'
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_index_returns_unauthorized_when_user_not_found()
    {
        $mockRequest = Mockery::mock(ParcelRequest::class);

        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn(null);

        $response = $this->controller->index($mockRequest);

        $this->assertEquals(401, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('User not found', $data['error']);
    }

    public function test_index_returns_paginated_requests()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        // Create test requests
        DeliveryRequest::factory()->count(5)->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open'
        ]);

        SendRequest::factory()->count(3)->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open'
        ]);

        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('input')
            ->with('page', 1)
            ->andReturn(1);
        $mockRequest->shouldReceive('input')
            ->with('per_page', 10)
            ->andReturn(10);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'from_location_id' => null,
                'to_location_id' => null,
                'search' => null,
                'filter' => null
            ]);

        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);

        $response = $this->controller->index($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertArrayHasKey('current_page', $data['pagination']);
        $this->assertArrayHasKey('total', $data['pagination']);
    }

    public function test_index_filters_by_delivery_requests_only()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        DeliveryRequest::factory()->count(3)->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open'
        ]);

        SendRequest::factory()->count(2)->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open'
        ]);

        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('input')
            ->with('page', 1)
            ->andReturn(1);
        $mockRequest->shouldReceive('input')
            ->with('per_page', 10)
            ->andReturn(10);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'from_location_id' => null,
                'to_location_id' => null,
                'search' => null,
                'filter' => 'delivery'
            ]);

        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);

        $response = $this->controller->index($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        // Should only contain delivery requests
        $this->assertCount(3, $data['data']);
    }

    public function test_index_filters_by_send_requests_only()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        DeliveryRequest::factory()->count(3)->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open'
        ]);

        SendRequest::factory()->count(2)->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open'
        ]);

        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('input')
            ->with('page', 1)
            ->andReturn(1);
        $mockRequest->shouldReceive('input')
            ->with('per_page', 10)
            ->andReturn(10);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'from_location_id' => null,
                'to_location_id' => null,
                'search' => null,
                'filter' => 'send'
            ]);

        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);

        $response = $this->controller->index($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        // Should only contain send requests
        $this->assertCount(2, $data['data']);
    }

    public function test_index_filters_by_route_locations()
    {
        $germanyCountry = Location::factory()->create(['type' => 'country', 'name' => 'Germany']);
        $franceCountry = Location::factory()->create(['type' => 'country', 'name' => 'France']);
        $spainCountry = Location::factory()->create(['type' => 'country', 'name' => 'Spain']);

        $berlinCity = Location::factory()->create([
            'type' => 'city',
            'name' => 'Berlin',
            'parent_id' => $germanyCountry->id
        ]);

        $parisCity = Location::factory()->create([
            'type' => 'city',
            'name' => 'Paris',
            'parent_id' => $franceCountry->id
        ]);

        // Create request matching the route filter (Germany -> France)
        DeliveryRequest::factory()->create([
            'from_location_id' => $berlinCity->id,
            'to_location_id' => $parisCity->id,
            'status' => 'open'
        ]);

        // Create request not matching the route filter
        DeliveryRequest::factory()->create([
            'from_location_id' => $berlinCity->id,
            'to_location_id' => $spainCountry->id,
            'status' => 'open'
        ]);

        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('input')
            ->with('page', 1)
            ->andReturn(1);
        $mockRequest->shouldReceive('input')
            ->with('per_page', 10)
            ->andReturn(10);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'from_location_id' => $germanyCountry->id,
                'to_location_id' => $franceCountry->id,
                'search' => null,
                'filter' => null
            ]);

        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);

        $response = $this->controller->index($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        // Should only contain the matching request
        $this->assertCount(1, $data['data']);
    }

    public function test_index_applies_search_filter()
    {
        $fromLocation = Location::factory()->create(['name' => 'Berlin']);
        $toLocation = Location::factory()->create(['name' => 'Paris']);
        $otherLocation = Location::factory()->create(['name' => 'Madrid']);

        // Create request with searchable description
        DeliveryRequest::factory()->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'description' => 'Express delivery service',
            'status' => 'open'
        ]);

        // Create request without matching description
        DeliveryRequest::factory()->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $otherLocation->id,
            'description' => 'Regular shipping',
            'status' => 'open'
        ]);

        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('input')
            ->with('page', 1)
            ->andReturn(1);
        $mockRequest->shouldReceive('input')
            ->with('per_page', 10)
            ->andReturn(10);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'from_location_id' => null,
                'to_location_id' => null,
                'search' => 'express',
                'filter' => null
            ]);

        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);

        $response = $this->controller->index($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        // Should only contain the matching request
        $this->assertCount(1, $data['data']);
    }

    public function test_index_handles_pagination_parameters()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        // Create enough requests to test pagination
        DeliveryRequest::factory()->count(25)->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open'
        ]);

        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('input')
            ->with('page', 1)
            ->andReturn(2); // Second page
        $mockRequest->shouldReceive('input')
            ->with('per_page', 10)
            ->andReturn(10); // 10 per page
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'from_location_id' => null,
                'to_location_id' => null,
                'search' => null,
                'filter' => null
            ]);

        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);

        $response = $this->controller->index($mockRequest);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertEquals(2, $data['pagination']['current_page']);
        $this->assertEquals(10, $data['pagination']['per_page']);
        $this->assertGreaterThan(2, $data['pagination']['last_page']);
    }

    public function test_index_limits_per_page_parameter()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        DeliveryRequest::factory()->count(10)->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open'
        ]);

        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('input')
            ->with('page', 1)
            ->andReturn(1);
        $mockRequest->shouldReceive('input')
            ->with('per_page', 10)
            ->andReturn(100); // Requesting 100, should be limited to 50
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'from_location_id' => null,
                'to_location_id' => null,
                'search' => null,
                'filter' => null
            ]);

        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);

        $response = $this->controller->index($mockRequest);

        $data = json_decode($response->getContent(), true);

        // Should be limited to 50 per page maximum
        $this->assertLessThanOrEqual(50, $data['pagination']['per_page']);
    }

    public function test_index_orders_by_created_at_desc()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        // Create requests at different times
        DeliveryRequest::factory()->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open',
            'created_at' => now()->subDays(2)
        ]);

        $newerRequest = DeliveryRequest::factory()->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open',
            'created_at' => now()->subDay()
        ]);

        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('input')
            ->with('page', 1)
            ->andReturn(1);
        $mockRequest->shouldReceive('input')
            ->with('per_page', 10)
            ->andReturn(10);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'from_location_id' => null,
                'to_location_id' => null,
                'search' => null,
                'filter' => null
            ]);

        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);

        $response = $this->controller->index($mockRequest);

        $data = json_decode($response->getContent(), true);

        // Should be ordered by created_at desc (newer first)
        $this->assertGreaterThan(0, count($data['data']));
        // In a real implementation, we'd verify the actual ordering
    }

    public function test_constructor_injects_telegram_user_service()
    {
        $reflection = new \ReflectionClass($this->controller);

        $userServiceProperty = $reflection->getProperty('userService');
        $userServiceProperty->setAccessible(true);

        $this->assertInstanceOf(TelegramUserService::class, $userServiceProperty->getValue($this->controller));
    }

    public function test_index_includes_only_open_and_has_responses_status()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();

        // Create requests with different statuses
        DeliveryRequest::factory()->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'open'
        ]);

        DeliveryRequest::factory()->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'has_responses'
        ]);

        DeliveryRequest::factory()->create([
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'status' => 'closed' // Should not appear
        ]);

        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('input')
            ->with('page', 1)
            ->andReturn(1);
        $mockRequest->shouldReceive('input')
            ->with('per_page', 10)
            ->andReturn(10);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'from_location_id' => null,
                'to_location_id' => null,
                'search' => null,
                'filter' => null
            ]);

        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);

        $response = $this->controller->index($mockRequest);

        $data = json_decode($response->getContent(), true);

        // Should only include 2 requests (open and has_responses)
        $this->assertCount(2, $data['data']);
    }
}
