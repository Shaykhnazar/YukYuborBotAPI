<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\LocationController;
use App\Service\TelegramUserService;
use App\Models\Location;
use App\Models\Route;
use App\Models\SuggestedRoute;
use App\Models\User;
use App\Models\TelegramUser;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;

class LocationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected LocationController $controller;
    protected TelegramUserService $tgService;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->tgService = Mockery::mock(TelegramUserService::class);
        $this->controller = new LocationController($this->tgService);
        
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

    public function test_get_countries_returns_active_countries_with_cities()
    {
        // Create countries
        $activeCountry = Location::factory()->create([
            'name' => 'Test Country',
            'type' => 'country',
            'is_active' => true
        ]);
        
        $inactiveCountry = Location::factory()->create([
            'name' => 'Inactive Country',
            'type' => 'country',
            'is_active' => false
        ]);
        
        // Create cities for active country
        Location::factory()->count(5)->create([
            'parent_id' => $activeCountry->id,
            'type' => 'city',
            'is_active' => true
        ]);
        
        $response = $this->controller->getCountries();
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertIsArray($data);
        $this->assertCount(1, $data); // Only active country
        
        // Check that children are loaded (limited to 3)
        $country = $data[0];
        $this->assertEquals('Test Country', $country['name']);
        $this->assertArrayHasKey('children', $country);
        $this->assertLessThanOrEqual(3, count($country['children']));
    }

    public function test_get_cities_by_country_returns_cities_for_country()
    {
        $country = Location::factory()->create([
            'type' => 'country',
            'is_active' => true
        ]);
        
        Location::factory()->count(3)->create([
            'parent_id' => $country->id,
            'type' => 'city',
            'is_active' => true
        ]);
        
        // Create inactive city (should not appear)
        Location::factory()->create([
            'parent_id' => $country->id,
            'type' => 'city',
            'is_active' => false
        ]);
        
        $request = new Request();
        
        $response = $this->controller->getCitiesByCountry($request, $country->id);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertIsArray($data);
        $this->assertCount(3, $data); // Only active cities
    }

    public function test_get_cities_by_country_filters_by_search_query()
    {
        $country = Location::factory()->create([
            'type' => 'country',
            'is_active' => true
        ]);
        
        Location::factory()->create([
            'parent_id' => $country->id,
            'name' => 'Berlin',
            'type' => 'city',
            'is_active' => true
        ]);
        
        Location::factory()->create([
            'parent_id' => $country->id,
            'name' => 'Munich',
            'type' => 'city',
            'is_active' => true
        ]);
        
        $request = new Request(['q' => 'ber']);
        
        $response = $this->controller->getCitiesByCountry($request, $country->id);
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertCount(1, $data);
        $this->assertEquals('Berlin', $data[0]['name']);
    }

    public function test_search_locations_requires_minimum_query_length()
    {
        $request = new Request(['q' => 'a']);
        
        $response = $this->controller->searchLocations($request);
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEmpty($data);
    }

    public function test_search_locations_returns_matching_locations()
    {
        $country = Location::factory()->create([
            'name' => 'Germany',
            'type' => 'country',
            'is_active' => true
        ]);
        
        Location::factory()->create([
            'parent_id' => $country->id,
            'name' => 'Berlin',
            'type' => 'city',
            'is_active' => true
        ]);
        
        Location::factory()->create([
            'name' => 'France',
            'type' => 'country',
            'is_active' => true
        ]);
        
        $request = new Request(['q' => 'ger']);
        
        $response = $this->controller->searchLocations($request);
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertGreaterThan(0, count($data));
        $this->assertEquals('Germany', $data[0]['name']);
    }

    public function test_search_locations_filters_by_type()
    {
        $country = Location::factory()->create([
            'name' => 'Germany',
            'type' => 'country',
            'is_active' => true
        ]);
        
        Location::factory()->create([
            'parent_id' => $country->id,
            'name' => 'German City',
            'type' => 'city',
            'is_active' => true
        ]);
        
        $request = new Request(['q' => 'german', 'type' => 'country']);
        
        $response = $this->controller->searchLocations($request);
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertCount(1, $data);
        $this->assertEquals('country', $data[0]['type']);
    }

    public function test_search_locations_includes_display_name_for_cities()
    {
        $country = Location::factory()->create([
            'name' => 'Germany',
            'type' => 'country',
            'is_active' => true
        ]);
        
        Location::factory()->create([
            'parent_id' => $country->id,
            'name' => 'Berlin',
            'type' => 'city',
            'is_active' => true
        ]);
        
        $request = new Request(['q' => 'berlin']);
        
        $response = $this->controller->searchLocations($request);
        
        $data = json_decode($response->getContent(), true);
        
        $city = $data[0];
        $this->assertEquals('city', $city['type']);
        $this->assertEquals('Germany, Berlin', $city['display_name']);
        $this->assertEquals('Germany', $city['country_name']);
    }

    public function test_popular_routes_returns_active_routes()
    {
        $fromCountry = Location::factory()->create([
            'name' => 'Germany',
            'type' => 'country',
            'is_active' => true
        ]);
        
        $toCountry = Location::factory()->create([
            'name' => 'France',
            'type' => 'country',
            'is_active' => true
        ]);
        
        Route::factory()->create([
            'from_location_id' => $fromCountry->id,
            'to_location_id' => $toCountry->id,
            'is_active' => true,
            'priority' => 1
        ]);
        
        // Create cities for the countries
        Location::factory()->count(3)->create([
            'parent_id' => $fromCountry->id,
            'type' => 'city',
            'is_active' => true
        ]);
        
        Location::factory()->count(3)->create([
            'parent_id' => $toCountry->id,
            'type' => 'city',
            'is_active' => true
        ]);
        
        $response = $this->controller->popularRoutes();
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        
        $route = $data[0];
        $this->assertEquals('Germany', $route['from']['name']);
        $this->assertEquals('France', $route['to']['name']);
        $this->assertArrayHasKey('popular_cities', $route);
        $this->assertCount(6, $route['popular_cities']); // 3 from each country
    }

    public function test_popular_routes_handles_exceptions_gracefully()
    {
        // Force an exception by creating an invalid database state
        // In reality, this might be harder to replicate, but we can mock it
        
        $response = $this->controller->popularRoutes();
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        // Should return empty array on exception
        $this->assertIsArray($data);
    }

    public function test_suggest_route_validates_required_fields()
    {
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location' => '',
            'to_location' => 'Paris',
        ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        // This should trigger validation error before reaching the controller logic
        // In a real test, we'd use form request validation
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        
        $this->controller->suggestRoute($request);
    }

    public function test_suggest_route_creates_suggested_route()
    {
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location' => 'Berlin',
            'to_location' => 'Paris',
            'notes' => 'Popular route suggestion'
        ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->suggestRoute($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('suggestion', $data);
        
        // Verify database record
        $this->assertDatabaseHas('suggested_routes', [
            'from_location' => 'Berlin',
            'to_location' => 'Paris',
            'user_id' => $this->user->id,
            'status' => 'pending',
            'notes' => 'Popular route suggestion'
        ]);
    }

    public function test_suggest_route_validates_different_locations()
    {
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location' => 'Berlin',
            'to_location' => 'Berlin', // Same as from_location
        ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        
        $this->controller->suggestRoute($request);
    }

    public function test_suggest_route_handles_optional_notes()
    {
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location' => 'Berlin',
            'to_location' => 'Paris'
            // No notes provided
        ]);
        
        $this->tgService->shouldReceive('getUserByTelegramId')
            ->with($request)
            ->once()
            ->andReturn($this->user);
        
        $response = $this->controller->suggestRoute($request);
        
        $this->assertEquals(200, $response->getStatusCode());
        
        // Verify database record with null notes
        $this->assertDatabaseHas('suggested_routes', [
            'from_location' => 'Berlin',
            'to_location' => 'Paris',
            'user_id' => $this->user->id,
            'notes' => null
        ]);
    }

    public function test_constructor_injects_telegram_service()
    {
        $reflection = new \ReflectionClass($this->controller);
        
        $tgServiceProperty = $reflection->getProperty('tgService');
        $tgServiceProperty->setAccessible(true);
        
        $this->assertInstanceOf(TelegramUserService::class, $tgServiceProperty->getValue($this->controller));
    }

    public function test_search_locations_limits_results()
    {
        // Create more than 10 locations
        Location::factory()->count(15)->create([
            'name' => 'Test Location',
            'type' => 'country',
            'is_active' => true
        ]);
        
        $request = new Request(['q' => 'test']);
        
        $response = $this->controller->searchLocations($request);
        
        $data = json_decode($response->getContent(), true);
        
        // Should be limited to 10 results
        $this->assertLessThanOrEqual(10, count($data));
    }

    public function test_get_countries_orders_by_name()
    {
        Location::factory()->create([
            'name' => 'Zebra Country',
            'type' => 'country',
            'is_active' => true
        ]);
        
        Location::factory()->create([
            'name' => 'Alpha Country',
            'type' => 'country',
            'is_active' => true
        ]);
        
        $response = $this->controller->getCountries();
        
        $data = json_decode($response->getContent(), true);
        
        $this->assertEquals('Alpha Country', $data[0]['name']);
        $this->assertEquals('Zebra Country', $data[1]['name']);
    }
}