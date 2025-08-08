<?php

namespace Tests\Unit\Controllers\User\Request;

use App\Http\Controllers\User\Request\UserRequestController;
use App\Service\TelegramUserService;
use App\Models\User;
use App\Models\TelegramUser;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\Review;
use App\Http\Requests\Parcel\ParcelRequest;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class UserRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    protected UserRequestController $controller;
    protected TelegramUserService $tgService;
    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tgService = Mockery::mock(TelegramUserService::class);
        $this->controller = new UserRequestController($this->tgService);
        
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'links_balance' => 5
        ]);
        
        $this->otherUser = User::factory()->create([
            'name' => 'Other User'
        ]);
        
        TelegramUser::factory()->create([
            'user_id' => $this->user->id,
            'telegram' => '123456789'
        ]);
        
        TelegramUser::factory()->create([
            'user_id' => $this->otherUser->id,
            'telegram' => '987654321'
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_index_returns_user_requests_with_responses()
    {
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        Response::factory()->create([
            'request_type' => 'send',
            'request_id' => $sendRequest->id,
            'user_id' => $this->user->id,
            'responder_id' => $this->otherUser->id,
            'status' => 'pending'
        ]);
        
        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'filter' => null,
                'status' => null,
                'search' => null
            ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($mockRequest);
        
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $response);
    }

    public function test_index_filters_by_request_type()
    {
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        DeliveryRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'filter' => 'send', // Only send requests
                'status' => null,
                'search' => null
            ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($mockRequest);
        
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $response);
    }

    public function test_index_filters_by_delivery_type()
    {
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        DeliveryRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'filter' => 'delivery', // Only delivery requests
                'status' => null,
                'search' => null
            ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($mockRequest);
        
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $response);
    }

    public function test_index_applies_status_filter_active()
    {
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'closed'
        ]);
        
        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'filter' => null,
                'status' => 'active', // Only active requests
                'search' => null
            ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($mockRequest);
        
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $response);
    }

    public function test_index_applies_status_filter_closed()
    {
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'completed'
        ]);
        
        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'filter' => null,
                'status' => 'closed', // Only closed requests
                'search' => null
            ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($mockRequest);
        
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $response);
    }

    public function test_index_applies_search_filter()
    {
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'description' => 'Express delivery needed',
            'status' => 'open'
        ]);
        
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'description' => 'Regular shipping',
            'status' => 'open'
        ]);
        
        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'filter' => null,
                'status' => null,
                'search' => 'express' // Search for express
            ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($mockRequest);
        
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $response);
    }

    public function test_show_returns_specific_request_by_id()
    {
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'filter' => null
            ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->show($mockRequest, $sendRequest->id);
        
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $response);
    }

    public function test_show_filters_by_request_type()
    {
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'filter' => 'send' // Only show send requests
            ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->show($mockRequest, $sendRequest->id);
        
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $response);
    }

    public function test_user_requests_returns_requests_for_specific_user()
    {
        SendRequest::factory()->create([
            'user_id' => $this->otherUser->id,
            'status' => 'open'
        ]);
        
        DeliveryRequest::factory()->create([
            'user_id' => $this->otherUser->id,
            'status' => 'open'
        ]);
        
        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'filter' => null,
                'status' => null,
                'search' => null
            ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->userRequests($mockRequest, $this->otherUser);
        
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $response);
    }

    public function test_user_requests_applies_filters()
    {
        SendRequest::factory()->create([
            'user_id' => $this->otherUser->id,
            'status' => 'open'
        ]);
        
        SendRequest::factory()->create([
            'user_id' => $this->otherUser->id,
            'status' => 'closed'
        ]);
        
        $mockRequest = Mockery::mock(ParcelRequest::class);
        $mockRequest->shouldReceive('getFilters')
            ->andReturn([
                'filter' => null,
                'status' => 'active', // Only active requests
                'search' => null
            ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($mockRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->userRequests($mockRequest, $this->otherUser);
        
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $response);
    }

    public function test_apply_status_filter_returns_active_requests()
    {
        $requests = collect([
            (object)['status' => 'open'],
            (object)['status' => 'has_responses'],
            (object)['status' => 'matched'],
            (object)['status' => 'matched_manually'],
            (object)['status' => 'closed'], // Should be filtered out
            (object)['status' => 'completed'] // Should be filtered out
        ]);
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('applyStatusFilter');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $requests, 'active');
        
        $this->assertCount(4, $result);
    }

    public function test_apply_status_filter_returns_closed_requests()
    {
        $requests = collect([
            (object)['status' => 'open'], // Should be filtered out
            (object)['status' => 'has_responses'], // Should be filtered out
            (object)['status' => 'completed'],
            (object)['status' => 'closed']
        ]);
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('applyStatusFilter');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $requests, 'closed');
        
        $this->assertCount(2, $result);
    }

    public function test_apply_search_filter_searches_locations()
    {
        $requests = collect([
            (object)[
                'from_location' => 'Berlin',
                'to_location' => 'Paris',
                'description' => null,
                'user' => null,
                'responder_user' => null
            ],
            (object)[
                'from_location' => 'Madrid',
                'to_location' => 'Rome',
                'description' => null,
                'user' => null,
                'responder_user' => null
            ]
        ]);
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('applySearchFilter');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $requests, 'berlin');
        
        $this->assertCount(1, $result);
    }

    public function test_apply_search_filter_searches_description()
    {
        $requests = collect([
            (object)[
                'from_location' => 'Berlin',
                'to_location' => 'Paris',
                'description' => 'Express delivery service',
                'user' => null,
                'responder_user' => null
            ],
            (object)[
                'from_location' => 'Madrid',
                'to_location' => 'Rome',
                'description' => 'Regular shipping',
                'user' => null,
                'responder_user' => null
            ]
        ]);
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('applySearchFilter');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $requests, 'express');
        
        $this->assertCount(1, $result);
    }

    public function test_apply_search_filter_searches_user_names()
    {
        $user1 = (object)['name' => 'John Doe'];
        $user2 = (object)['name' => 'Jane Smith'];
        
        $requests = collect([
            (object)[
                'from_location' => 'Berlin',
                'to_location' => 'Paris',
                'description' => null,
                'user' => $user1,
                'responder_user' => null
            ],
            (object)[
                'from_location' => 'Madrid',
                'to_location' => 'Rome',
                'description' => null,
                'user' => $user2,
                'responder_user' => null
            ]
        ]);
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('applySearchFilter');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $requests, 'john');
        
        $this->assertCount(1, $result);
    }

    public function test_has_user_reviewed_other_party_returns_false_for_active_requests()
    {
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open' // Active status
        ]);
        
        $request = (object)[
            'status' => 'open',
            'responder_user' => null
        ];
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('hasUserReviewedOtherParty');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $this->user, $request);
        
        $this->assertFalse($result);
    }

    public function test_has_user_reviewed_other_party_checks_review_existence()
    {
        $sendRequest = SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'completed'
        ]);
        
        // Create a review
        Review::factory()->create([
            'user_id' => $this->otherUser->id,
            'owner_id' => $this->user->id,
            'request_id' => $sendRequest->id,
            'request_type' => 'send'
        ]);
        
        $request = (object)[
            'id' => $sendRequest->id,
            'status' => 'completed',
            'type' => 'send',
            'responder_user' => $this->otherUser
        ];
        
        $reflection = new \ReflectionClass($this->controller);
        $method = $reflection->getMethod('hasUserReviewedOtherParty');
        $method->setAccessible(true);
        
        $result = $method->invoke($this->controller, $this->user, $request);
        
        $this->assertTrue($result);
    }

    public function test_constructor_injects_telegram_service()
    {
        $reflection = new \ReflectionClass($this->controller);
        
        $tgServiceProperty = $reflection->getProperty('tgService');
        $tgServiceProperty->setAccessible(true);
        
        $this->assertInstanceOf(TelegramUserService::class, $tgServiceProperty->getValue($this->controller));
    }
}