<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\SendRequestController;
use App\Service\TelegramUserService;
use App\Service\Matcher;
use App\Models\User;
use App\Models\TelegramUser;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Models\Location;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;

class SendRequestControllerTest extends TestCase
{
    use RefreshDatabase;

    protected SendRequestController $controller;
    protected TelegramUserService $userService;
    protected Matcher $matcher;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->userService = Mockery::mock(TelegramUserService::class);
        $this->matcher = Mockery::mock(Matcher::class);
        $this->controller = new SendRequestController($this->userService, $this->matcher);
        
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

    public function test_create_enforces_active_requests_limit()
    {
        // Create 2 active send requests
        SendRequest::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        // Create 1 active delivery request
        DeliveryRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Test package',
            'price' => 100,
            'currency' => 'USD'
        ]);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->create($request);
        
        $this->assertEquals(422, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('errorTitle', $data);
        $this->assertStringContains('Превышен лимит заявок', $data['errorTitle']);
    }

    public function test_create_allows_request_when_under_limit()
    {
        // Create only 1 active request (under the limit of 3)
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Test package',
            'price' => 100,
            'currency' => 'USD'
        ]);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchSendRequest')
            ->once();
        
        $response = $this->controller->create($request);
        
        // Should not get a 422 status (limit exceeded)
        $this->assertNotEquals(422, $response->getStatusCode());
    }

    public function test_create_counts_both_send_and_delivery_requests_for_limit()
    {
        // Create 2 delivery requests and 1 send request (total 3 = at limit)
        DeliveryRequest::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Test package'
        ]);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->create($request);
        
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_create_ignores_closed_requests_in_limit_calculation()
    {
        // Create 3 closed requests (should not count toward limit)
        SendRequest::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'status' => 'closed'
        ]);
        
        DeliveryRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'closed'
        ]);
        
        // Create 1 open request (under limit)
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Test package'
        ]);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchSendRequest')
            ->once();
        
        $response = $this->controller->create($request);
        
        // Should be allowed since closed requests don't count
        $this->assertNotEquals(422, $response->getStatusCode());
    }

    public function test_create_calls_matcher_service()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Test package',
            'price' => 100,
            'currency' => 'USD'
        ]);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchSendRequest')
            ->once()
            ->with(Mockery::type(SendRequest::class));
        
        $response = $this->controller->create($request);
        
        // Verify matcher was called
        $this->matcher->shouldHaveReceived('matchSendRequest');
    }

    public function test_create_saves_send_request_to_database()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Test package for delivery',
            'price' => 150,
            'currency' => 'EUR'
        ]);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchSendRequest')
            ->once();
        
        $response = $this->controller->create($request);
        
        // Verify send request was saved to database
        $this->assertDatabaseHas('send_requests', [
            'user_id' => $this->user->id,
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'description' => 'Test package for delivery',
            'price' => 150,
            'currency' => 'EUR',
            'status' => 'open'
        ]);
    }

    public function test_create_handles_request_without_price()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Free delivery'
        ]);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchSendRequest')
            ->once();
        
        $response = $this->controller->create($request);
        
        $this->assertDatabaseHas('send_requests', [
            'user_id' => $this->user->id,
            'description' => 'Free delivery',
            'price' => null,
            'currency' => null
        ]);
    }

    public function test_create_sets_correct_default_status()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Test status'
        ]);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchSendRequest')
            ->once();
        
        $response = $this->controller->create($request);
        
        $this->assertDatabaseHas('send_requests', [
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
    }

    public function test_controller_constructor_dependencies()
    {
        // Test that constructor dependencies are properly injected
        $reflection = new \ReflectionClass($this->controller);
        
        $userServiceProperty = $reflection->getProperty('userService');
        $userServiceProperty->setAccessible(true);
        $this->assertInstanceOf(TelegramUserService::class, $userServiceProperty->getValue($this->controller));
        
        $matcherProperty = $reflection->getProperty('matcher');
        $matcherProperty->setAccessible(true);
        $this->assertInstanceOf(Matcher::class, $matcherProperty->getValue($this->controller));
    }

    public function test_max_active_requests_limit_is_three()
    {
        // This test verifies that the hardcoded limit is 3
        // Create exactly 3 active requests (at the limit)
        SendRequest::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ]);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->create($request);
        
        // Should be blocked at 3 requests
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_create_with_minimal_required_data()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        // Test with only required fields
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ]);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchSendRequest')
            ->once();
        
        $response = $this->controller->create($request);
        
        // Should succeed with minimal data
        $this->assertNotEquals(422, $response->getStatusCode());
        
        $this->assertDatabaseHas('send_requests', [
            'user_id' => $this->user->id,
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id
        ]);
    }
}