<?php

namespace Tests\Unit\Controllers;

use App\Http\Controllers\LocationController;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\LocationCacheService;
use App\Services\RouteCacheService;
use App\Services\TelegramUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class LocationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected LocationController $controller;
    protected TelegramUserService $tgService;
    protected LocationCacheService $locationCacheService;
    protected RouteCacheService $routeCacheService;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tgService = Mockery::mock(TelegramUserService::class);
        $this->locationCacheService = Mockery::mock(LocationCacheService::class);
        $this->routeCacheService = Mockery::mock(RouteCacheService::class);
        $this->controller = new LocationController(
            $this->tgService,
            $this->locationCacheService,
            $this->routeCacheService
        );

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
        $countriesData = [
            [
                'id' => 1,
                'name' => 'Test Country',
                'type' => 'country',
                'is_active' => true,
                'children' => [
                    ['id' => 2, 'name' => 'City 1', 'type' => 'city'],
                    ['id' => 3, 'name' => 'City 2', 'type' => 'city'],
                    ['id' => 4, 'name' => 'City 3', 'type' => 'city'],
                ]
            ]
        ];

        $this->locationCacheService->shouldReceive('getCountriesWithPopularCities')
            ->with(3)
            ->once()
            ->andReturn(collect($countriesData));

        $response = $this->controller->getCountries();

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertCount(1, $data);

        $country = $data[0];
        $this->assertEquals('Test Country', $country['name']);
        $this->assertArrayHasKey('children', $country);
        $this->assertCount(3, $country['children']);
    }

    public function test_get_cities_by_country_returns_cities_for_country()
    {
        $countryId = 1;
        $citiesData = [
            ['id' => 2, 'name' => 'City 1', 'type' => 'city'],
            ['id' => 3, 'name' => 'City 2', 'type' => 'city'],
            ['id' => 4, 'name' => 'City 3', 'type' => 'city'],
        ];

        $this->locationCacheService->shouldReceive('getCitiesByCountry')
            ->with($countryId, null)
            ->once()
            ->andReturn(collect($citiesData));

        $request = new Request();

        $response = $this->controller->getCitiesByCountry($request, $countryId);

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertCount(3, $data);
    }

    public function test_get_cities_by_country_filters_by_search_query()
    {
        $countryId = 1;
        $query = 'ber';
        $filteredCitiesData = [
            ['id' => 2, 'name' => 'Berlin', 'type' => 'city'],
        ];

        $this->locationCacheService->shouldReceive('getCitiesByCountry')
            ->with($countryId, $query)
            ->once()
            ->andReturn(collect($filteredCitiesData));

        $request = new Request(['q' => $query]);

        $response = $this->controller->getCitiesByCountry($request, $countryId);

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
        $query = 'ger';
        $type = 'all';
        $searchResults = [
            ['id' => 1, 'name' => 'Germany', 'type' => 'country'],
            ['id' => 2, 'name' => 'Berlin', 'type' => 'city', 'country_name' => 'Germany'],
        ];

        $this->locationCacheService->shouldReceive('searchLocations')
            ->with($query, $type, 10)
            ->once()
            ->andReturn(collect($searchResults));

        $request = new Request(['q' => $query]);

        $response = $this->controller->searchLocations($request);

        $data = json_decode($response->getContent(), true);

        $this->assertGreaterThan(0, count($data));
        $this->assertEquals('Germany', $data[0]['name']);
    }

    public function test_search_locations_filters_by_type()
    {
        $query = 'german';
        $type = 'country';
        $searchResults = [
            ['id' => 1, 'name' => 'Germany', 'type' => 'country'],
        ];

        $this->locationCacheService->shouldReceive('searchLocations')
            ->with($query, $type, 10)
            ->once()
            ->andReturn(collect($searchResults));

        $request = new Request(['q' => $query, 'type' => $type]);

        $response = $this->controller->searchLocations($request);

        $data = json_decode($response->getContent(), true);

        $this->assertCount(1, $data);
        $this->assertEquals('country', $data[0]['type']);
    }

    public function test_search_locations_includes_display_name_for_cities()
    {
        $query = 'berlin';
        $type = 'all';
        $searchResults = [
            [
                'id' => 2,
                'name' => 'Berlin',
                'type' => 'city',
                'display_name' => 'Germany, Berlin',
                'country_name' => 'Germany'
            ],
        ];

        $this->locationCacheService->shouldReceive('searchLocations')
            ->with($query, $type, 10)
            ->once()
            ->andReturn(collect($searchResults));

        $request = new Request(['q' => $query]);

        $response = $this->controller->searchLocations($request);

        $data = json_decode($response->getContent(), true);

        $city = $data[0];
        $this->assertEquals('city', $city['type']);
        $this->assertEquals('Germany, Berlin', $city['display_name']);
        $this->assertEquals('Germany', $city['country_name']);
    }

    public function test_popular_routes_returns_active_routes()
    {
        $popularRoutesData = [
            [
                'id' => 1,
                'from' => ['id' => 1, 'name' => 'Germany', 'type' => 'country'],
                'to' => ['id' => 2, 'name' => 'France', 'type' => 'country'],
                'popular_cities' => [
                    ['id' => 3, 'name' => 'Berlin'],
                    ['id' => 4, 'name' => 'Hamburg'],
                    ['id' => 5, 'name' => 'Munich'],
                    ['id' => 6, 'name' => 'Paris'],
                    ['id' => 7, 'name' => 'Lyon'],
                    ['id' => 8, 'name' => 'Marseille'],
                ]
            ]
        ];

        $this->routeCacheService->shouldReceive('getPopularRoutes')
            ->once()
            ->andReturn(collect($popularRoutesData));

        $response = $this->controller->popularRoutes();

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        $this->assertIsArray($data);
        $this->assertCount(1, $data);

        $route = $data[0];
        $this->assertEquals('Germany', $route['from']['name']);
        $this->assertEquals('France', $route['to']['name']);
        $this->assertArrayHasKey('popular_cities', $route);
        $this->assertCount(6, $route['popular_cities']);
    }

    public function test_popular_routes_handles_exceptions_gracefully()
    {
        $this->routeCacheService->shouldReceive('getPopularRoutes')
            ->once()
            ->andThrow(new \Exception('Cache error'));

        $response = $this->controller->popularRoutes();

        $this->assertEquals(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);

        // Should return empty array on exception
        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    public function test_suggest_route_validates_required_fields()
    {
        $request = new Request([
            'telegram_id' => '123456789',
            'from_location' => '',  // Empty string should fail validation
            'to_location' => 'Paris',
        ]);

        // Don't mock getUserByTelegramId since validation will fail before it's called

        // This should trigger validation error before reaching the controller logic
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

        // Validation happens before getUserByTelegramId is called
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
        $query = 'test';
        $type = 'all';
        // Mock service returns exactly 10 results even if more are available
        $searchResults = array_fill(0, 10, ['id' => 1, 'name' => 'Test Location', 'type' => 'country']);

        $this->locationCacheService->shouldReceive('searchLocations')
            ->with($query, $type, 10)
            ->once()
            ->andReturn(collect($searchResults));

        $request = new Request(['q' => $query]);

        $response = $this->controller->searchLocations($request);

        $data = json_decode($response->getContent(), true);

        // Should be limited to 10 results
        $this->assertLessThanOrEqual(10, count($data));
        $this->assertCount(10, $data);
    }

    public function test_get_countries_orders_by_name()
    {
        $countriesData = [
            [
                'id' => 2,
                'name' => 'Alpha Country',
                'type' => 'country',
                'children' => []
            ],
            [
                'id' => 1,
                'name' => 'Zebra Country',
                'type' => 'country',
                'children' => []
            ]
        ];

        $this->locationCacheService->shouldReceive('getCountriesWithPopularCities')
            ->with(3)
            ->once()
            ->andReturn(collect($countriesData));

        $response = $this->controller->getCountries();

        $data = json_decode($response->getContent(), true);

        $this->assertEquals('Alpha Country', $data[0]['name']);
        $this->assertEquals('Zebra Country', $data[1]['name']);
    }
}
