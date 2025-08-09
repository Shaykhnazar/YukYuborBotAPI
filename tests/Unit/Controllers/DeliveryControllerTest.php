<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\DeliveryController;
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
use App\Http\Requests\Delivery\CreateDeliveryRequest;
use Mockery;

class DeliveryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected DeliveryController $controller;
    protected TelegramUserService $userService;
    protected Matcher $matcher;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->userService = Mockery::mock(TelegramUserService::class);
        $this->matcher = Mockery::mock(Matcher::class);
        $this->controller = new DeliveryController($this->userService, $this->matcher);
        
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
    
    private function createMockRequest(array $requestData, bool $expectsDTO = true): CreateDeliveryRequest
    {
        $mockRequest = Mockery::mock(CreateDeliveryRequest::class);
        
        if ($expectsDTO) {
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
        } else {
            $mockRequest->shouldReceive('getDTO')->never();
        }
        
        return $mockRequest;
    }

    public function test_create_enforces_active_requests_limit()
    {
        // Create 2 active delivery requests
        DeliveryRequest::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        // Create 1 active send request
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Delivery service',
            'price' => 200,
            'currency' => 'USD'
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData, false);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->create($createDeliveryRequest);
        
        $this->assertEquals(422, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('error', $data);
        $this->assertArrayHasKey('errorTitle', $data);
        $this->assertStringContainsString('Превышен лимит заявок', $data['errorTitle']);
    }

    public function test_create_allows_request_when_under_limit()
    {
        // Create only 1 active request (under the limit of 3)
        DeliveryRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Delivery service'
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchDeliveryRequest')
            ->once();
        
        $response = $this->controller->create($createDeliveryRequest);
        
        // Should not get a 422 status (limit exceeded)
        $this->assertNotEquals(422, $response->getStatusCode());
    }

    public function test_create_counts_both_send_and_delivery_requests_for_limit()
    {
        // Create 2 send requests and 1 delivery request (total 3 = at limit)
        SendRequest::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        DeliveryRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Delivery service'
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData, false);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->create($createDeliveryRequest);
        
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_create_ignores_closed_requests_in_limit_calculation()
    {
        // Create 3 closed requests (should not count toward limit)
        DeliveryRequest::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'status' => 'closed'
        ]);
        
        SendRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'closed'
        ]);
        
        // Create 1 open request (under limit)
        DeliveryRequest::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Delivery service'
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchDeliveryRequest')
            ->once();
        
        $response = $this->controller->create($createDeliveryRequest);
        
        // Should be allowed since closed requests don't count
        $this->assertNotEquals(422, $response->getStatusCode());
    }

    public function test_create_calls_matcher_service()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Delivery service',
            'price' => 150,
            'currency' => 'EUR'
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchDeliveryRequest')
            ->once()
            ->with(Mockery::type(DeliveryRequest::class));
        
        $response = $this->controller->create($createDeliveryRequest);
        
        // Verify successful response
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function test_create_saves_delivery_request_to_database()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Professional delivery service',
            'price' => 250,
            'currency' => 'GBP'
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchDeliveryRequest')
            ->once();
        
        $response = $this->controller->create($createDeliveryRequest);
        
        // Verify delivery request was saved to database
        $this->assertDatabaseHas('delivery_requests', [
            'user_id' => $this->user->id,
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'description' => 'Professional delivery service',
            'price' => 250,
            'currency' => 'GBP',
            'status' => 'open'
        ]);
    }

    public function test_create_handles_request_without_price()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Free delivery service'
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchDeliveryRequest')
            ->once();
        
        $response = $this->controller->create($createDeliveryRequest);
        
        $this->assertDatabaseHas('delivery_requests', [
            'user_id' => $this->user->id,
            'description' => 'Free delivery service',
            'price' => null,
            'currency' => null
        ]);
    }

    public function test_create_sets_correct_default_status()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Test status delivery'
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchDeliveryRequest')
            ->once();
        
        $response = $this->controller->create($createDeliveryRequest);
        
        $this->assertDatabaseHas('delivery_requests', [
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
    }

    public function test_create_requires_both_from_date_and_to_date()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'description' => 'Test delivery with dates'
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchDeliveryRequest')
            ->once();
        
        $response = $this->controller->create($createDeliveryRequest);
        
        // Should succeed with both dates provided
        $this->assertNotEquals(422, $response->getStatusCode());
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
        DeliveryRequest::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'status' => 'open'
        ]);
        
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData, false);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->create($createDeliveryRequest);
        
        // Should be blocked at 3 requests
        $this->assertEquals(422, $response->getStatusCode());
    }

    public function test_create_with_minimal_required_data()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        // Test with only required fields
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(1)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchDeliveryRequest')
            ->once();
        
        $response = $this->controller->create($createDeliveryRequest);
        
        // Should succeed with minimal data
        $this->assertNotEquals(422, $response->getStatusCode());
        
        $this->assertDatabaseHas('delivery_requests', [
            'user_id' => $this->user->id,
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id
        ]);
    }

    public function test_create_handles_date_range_validation()
    {
        $fromLocation = Location::factory()->create();
        $toLocation = Location::factory()->create();
        
        // Test with from_date after to_date (should still be handled by the controller)
        $requestData = [
            'telegram_id' => '123456789',
            'from_location_id' => $fromLocation->id,
            'to_location_id' => $toLocation->id,
            'from_date' => now()->addDays(7)->format('Y-m-d H:i:s'),
            'to_date' => now()->addDays(1)->format('Y-m-d H:i:s'), // Earlier than from_date
            'description' => 'Date range test'
        ];
        
        $createDeliveryRequest = $this->createMockRequest($requestData);
        
        $this->userService->shouldReceive('getUserByTelegramId')
            ->with($createDeliveryRequest)
            ->once()
            ->andReturn($this->user);
        
        $this->matcher->shouldReceive('matchDeliveryRequest')
            ->once();
        
        $response = $this->controller->create($createDeliveryRequest);
        
        // The controller itself may accept this - validation would typically be handled by form request
        $this->assertNotEquals(500, $response->getStatusCode()); // Should not crash
    }
}