<?php

namespace Tests\Unit\Controllers\User;

use App\Http\Controllers\User\UserController;
use App\Service\TelegramUserService;
use App\Models\User;
use App\Models\TelegramUser;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Review;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    protected UserController $controller;
    protected TelegramUserService $telegramService;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->telegramService = Mockery::mock(TelegramUserService::class);
        $this->controller = new UserController($this->telegramService);
        
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'city' => 'Tashkent',
            'links_balance' => 5
        ]);
        
        TelegramUser::factory()->create([
            'user_id' => $this->user->id,
            'telegram' => '123456789',
            'username' => 'testuser'
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_index_returns_user_profile_with_statistics()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        // Create some completed requests
        SendRequest::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'status' => 'completed'
        ]);
        
        DeliveryRequest::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'status' => 'closed'
        ]);
        
        // Create some reviews
        Review::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'rating' => 4
        ]);
        
        Review::factory()->create([
            'user_id' => $this->user->id,
            'rating' => 5
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('telegram', $data);
        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('reviews', $data);
        $this->assertArrayHasKey('average_rating', $data);
        
        $this->assertEquals(5, $data['user']['completed_requests_count']);
        $this->assertEquals(4.25, $data['average_rating']); // (4+4+4+5)/4
        $this->assertArrayHasKey('with_us', $data['user']);
    }

    public function test_index_handles_user_with_no_requests()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEquals(0, $data['user']['completed_requests_count']);
        $this->assertEquals(0, $data['average_rating']);
    }

    public function test_index_handles_user_with_no_reviews()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'completed'
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEquals(1, $data['user']['completed_requests_count']);
        $this->assertEquals(0, $data['average_rating']);
        $this->assertEmpty($data['reviews']);
    }

    public function test_show_returns_specific_user_profile()
    {
        $request = new Request();
        
        // Create another user to show
        $targetUser = User::factory()->create([
            'name' => 'Target User',
            'city' => 'Samarkand'
        ]);
        
        TelegramUser::factory()->create([
            'user_id' => $targetUser->id,
            'telegram' => '987654321'
        ]);
        
        SendRequest::factory()->count(2)->create([
            'user_id' => $targetUser->id,
            'status' => 'completed'
        ]);
        
        Review::factory()->count(2)->create([
            'user_id' => $targetUser->id,
            'rating' => 5
        ]);
        
        $response = $this->controller->show($request, $targetUser);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('telegram', $data);
        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('reviews', $data);
        $this->assertArrayHasKey('average_rating', $data);
        
        $this->assertEquals(2, $data['user']['completed_requests_count']);
        $this->assertEquals(5.0, $data['average_rating']);
        $this->assertArrayHasKey('with_us', $data['user']);
    }

    public function test_completed_requests_count_calculation()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        // Create requests with different statuses
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'completed'
        ]);
        
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'closed'
        ]);
        
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open' // This should not be counted
        ]);
        
        DeliveryRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'completed'
        ]);
        
        DeliveryRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open' // This should not be counted
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($request);
        
        $data = json_decode($response->getContent(), true);
        
        // Should count only completed and closed requests
        $this->assertEquals(3, $data['user']['completed_requests_count']);
    }

    public function test_average_rating_calculation_with_mixed_ratings()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        Review::factory()->create([
            'user_id' => $this->user->id,
            'rating' => 1
        ]);
        
        Review::factory()->create([
            'user_id' => $this->user->id,
            'rating' => 3
        ]);
        
        Review::factory()->create([
            'user_id' => $this->user->id,
            'rating' => 5
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($request);
        
        $data = json_decode($response->getContent(), true);
        
        // (1 + 3 + 5) / 3 = 3.0
        $this->assertEquals(3.0, $data['average_rating']);
    }

    public function test_with_us_field_is_formatted_correctly()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($request);
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('with_us', $data['user']);
        $this->assertIsString($data['user']['with_us']);
        $this->assertStringContains('С нами уже', $data['user']['with_us']);
    }

    public function test_user_data_excludes_telegram_user_in_main_object()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($request);
        
        $data = json_decode($response->getContent(), true);
        
        // Telegram user should be in separate key, not in user object
        $this->assertArrayNotHasKey('telegram_user', $data['user']);
        $this->assertArrayHasKey('telegram', $data);
    }

    public function test_reviews_are_returned_as_resource_collection()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        Review::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'rating' => 4,
            'text' => 'Great service!'
        ]);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($request);
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertIsArray($data['reviews']);
        $this->assertCount(2, $data['reviews']);
    }

    public function test_controller_uses_custom_locale_for_carbon()
    {
        $request = new Request(['telegram_id' => '123456789']);
        
        $this->telegramService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->index($request);
        
        $data = json_decode($response->getContent(), true);
        
        // Should use the custom Russian locale
        $this->assertStringContains('С нами уже', $data['user']['with_us']);
    }

    public function test_show_method_works_with_user_without_telegram_user()
    {
        $request = new Request();
        
        $userWithoutTelegram = User::factory()->create([
            'name' => 'User Without Telegram'
        ]);
        
        $response = $this->controller->show($request, $userWithoutTelegram);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertNull($data['telegram']);
        $this->assertEquals(0, $data['user']['completed_requests_count']);
        $this->assertEquals(0, $data['average_rating']);
    }
}