<?php

namespace Tests\Unit\Services;

use App\Models\DeliveryRequest;
use App\Models\Location;
use App\Models\Route;
use App\Models\SendRequest;
use App\Services\LocationCacheService;
use App\Services\RouteCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class RouteCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RouteCacheService $service;
    protected $mockLocationCacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockLocationCacheService = Mockery::mock(LocationCacheService::class);
        $this->app->instance(LocationCacheService::class, $this->mockLocationCacheService);
        
        $this->service = new RouteCacheService();
        
        // Clear cache before each test
        Cache::flush();
    }

    protected function tearDown(): void
    {
        // Ensure any open transactions are rolled back
        if ($this->app && $this->app->bound('db')) {
            try {
                \DB::rollBack();
            } catch (\Exception $e) {
                // Ignore rollback errors
            }
        }
        
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_active_routes_returns_cached_data()
    {
        $country1 = Location::factory()->create(['type' => 'country', 'name' => 'Country 1']);
        $country2 = Location::factory()->create(['type' => 'country', 'name' => 'Country 2']);
        $city1 = Location::factory()->create(['type' => 'city', 'name' => 'City 1', 'parent_id' => $country1->id]);
        $city2 = Location::factory()->create(['type' => 'city', 'name' => 'City 2', 'parent_id' => $country2->id]);

        $route = Route::factory()->create([
            'from_location_id' => $city1->id,
            'to_location_id' => $city2->id,
            'is_active' => true,
            'priority' => 1
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading active routes from database')
            ->once();

        $result = $this->service->getActiveRoutes();
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);
        $this->assertEquals($route->id, $result->first()->id);

        // Second call should use cache (no log message)
        $result2 = $this->service->getActiveRoutes();
        $this->assertEquals($result, $result2);
    }

    public function test_get_popular_routes_with_location_service_integration()
    {
        $country1 = Location::factory()->create(['type' => 'country', 'name' => 'Country 1']);
        $country2 = Location::factory()->create(['type' => 'country', 'name' => 'Country 2']);
        $city1 = Location::factory()->create(['type' => 'city', 'name' => 'City 1', 'parent_id' => $country1->id]);
        $city2 = Location::factory()->create(['type' => 'city', 'name' => 'City 2', 'parent_id' => $country2->id]);

        $route = Route::factory()->create([
            'from_location_id' => $country1->id,
            'to_location_id' => $country2->id,
            'is_active' => true,
            'priority' => 1,
            'description' => 'Popular route'
        ]);

        // Mock the location service calls
        $this->mockLocationCacheService->shouldReceive('getCitiesByCountry')
            ->with($country1->id)
            ->once()
            ->andReturn(collect([
                ['id' => $city1->id, 'name' => 'City 1'],
                ['id' => 999, 'name' => 'City Test']
            ]));

        $this->mockLocationCacheService->shouldReceive('getCitiesByCountry')
            ->with($country2->id)
            ->once()
            ->andReturn(collect([
                ['id' => $city2->id, 'name' => 'City 2']
            ]));

        Log::shouldReceive('info')
            ->with('Cache miss: Loading active routes from database')
            ->once();
        Log::shouldReceive('info')
            ->with('Cache miss: Building popular routes from database')
            ->once();
        Log::shouldReceive('info')
            ->with('Cache miss: Calculating route requests count', ['route_id' => $route->id])
            ->once();

        $result = $this->service->getPopularRoutes();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);

        $routeData = $result->first();
        $this->assertEquals($route->id, $routeData['id']);
        $this->assertEquals($country1->name, $routeData['from']['name']);
        $this->assertEquals($country2->name, $routeData['to']['name']);
        $this->assertArrayHasKey('popular_cities', $routeData);
        $this->assertArrayHasKey('active_requests', $routeData);
        $this->assertEquals('Popular route', $routeData['description']);
    }

    public function test_get_route_requests_count_calculates_correctly()
    {
        $country1 = Location::factory()->create(['type' => 'country']);
        $country2 = Location::factory()->create(['type' => 'country']);
        $city1 = Location::factory()->create(['type' => 'city', 'parent_id' => $country1->id]);
        $city2 = Location::factory()->create(['type' => 'city', 'parent_id' => $country2->id]);

        $route = Route::factory()->create([
            'from_location_id' => $country1->id,
            'to_location_id' => $country2->id,
            'is_active' => true
        ]);

        // Create some requests
        SendRequest::factory()->count(2)->create([
            'from_location_id' => $city1->id,
            'to_location_id' => $city2->id,
            'status' => 'open'
        ]);

        DeliveryRequest::factory()->create([
            'from_location_id' => $city1->id,
            'to_location_id' => $city2->id,
            'status' => 'has_responses'
        ]);

        // Create reverse direction request
        SendRequest::factory()->create([
            'from_location_id' => $city2->id,
            'to_location_id' => $city1->id,
            'status' => 'open'
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Calculating route requests count', ['route_id' => $route->id])
            ->once();

        $count = $this->service->getRouteRequestsCount($route->id);

        $this->assertEquals(4, $count);

        // Second call should use cache
        $count2 = $this->service->getRouteRequestsCount($route->id);
        $this->assertEquals(4, $count2);
    }

    public function test_get_route_requests_count_returns_zero_for_nonexistent_route()
    {
        Log::shouldReceive('info')
            ->with('Cache miss: Calculating route requests count', ['route_id' => 999])
            ->once();

        $count = $this->service->getRouteRequestsCount(999);

        $this->assertEquals(0, $count);
    }

    public function test_get_routes_with_request_counts_uses_batch_calculation()
    {
        $country1 = Location::factory()->create(['type' => 'country']);
        $country2 = Location::factory()->create(['type' => 'country']);
        
        $route1 = Route::factory()->create([
            'from_location_id' => $country1->id,
            'to_location_id' => $country2->id,
            'is_active' => true
        ]);
        
        $route2 = Route::factory()->create([
            'from_location_id' => $country2->id,
            'to_location_id' => $country1->id,
            'is_active' => true
        ]);

        // Create requests for route1
        SendRequest::factory()->create([
            'from_location_id' => $country1->id,
            'to_location_id' => $country2->id,
            'status' => 'open'
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading active routes from database')
            ->once();
        Log::shouldReceive('info')
            ->with('Cache miss: Loading routes with request counts from database')
            ->once();

        $result = $this->service->getRoutesWithRequestCounts();

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
        
        $route1Result = $result->firstWhere('id', $route1->id);
        $route2Result = $result->firstWhere('id', $route2->id);
        
        $this->assertNotNull($route1Result);
        $this->assertNotNull($route2Result);
        $this->assertArrayHasKey('active_requests_count', $route1Result->getAttributes());
        $this->assertArrayHasKey('active_requests_count', $route2Result->getAttributes());
    }

    public function test_get_country_routes_filters_correctly()
    {
        $country1 = Location::factory()->create(['type' => 'country']);
        $country2 = Location::factory()->create(['type' => 'country']);
        $country3 = Location::factory()->create(['type' => 'country']);

        $route1 = Route::factory()->create([
            'from_location_id' => $country1->id,
            'to_location_id' => $country2->id,
            'is_active' => true,
            'priority' => 1
        ]);

        $route2 = Route::factory()->create([
            'from_location_id' => $country2->id,
            'to_location_id' => $country3->id,
            'is_active' => true,
            'priority' => 2
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading country routes from database', [
                'from_country_id' => $country1->id,
                'to_country_id' => $country2->id
            ])
            ->once();

        $result = $this->service->getCountryRoutes($country1->id, $country2->id);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);
        $this->assertEquals($route1->id, $result->first()->id);
    }

    public function test_warm_cache_loads_all_major_caches()
    {
        $country1 = Location::factory()->create(['type' => 'country']);
        $country2 = Location::factory()->create(['type' => 'country']);
        
        Route::factory()->create([
            'from_location_id' => $country1->id,
            'to_location_id' => $country2->id,
            'is_active' => true
        ]);

        $this->mockLocationCacheService->shouldReceive('getCitiesByCountry')
            ->andReturn(collect([]));

        Log::shouldReceive('info')->with('Starting route cache warming')->once();
        Log::shouldReceive('info')->with('Cache miss: Loading active routes from database')->once();
        Log::shouldReceive('info')->with('Cache miss: Building popular routes from database')->once();
        Log::shouldReceive('info')->with('Cache miss: Loading routes with request counts from database')->once();
        Log::shouldReceive('info')->with('Cache miss: Loading country routes from database', Mockery::type('array'))->once();
        Log::shouldReceive('info')->with('Cache miss: Calculating route requests count', Mockery::type('array'))->once();
        Log::shouldReceive('info')->with('Route cache warming completed successfully', Mockery::type('array'))->once();

        $this->service->warmCache();

        // Verify caches are warmed
        $this->assertTrue(Cache::has(RouteCacheService::CACHE_KEY_ACTIVE_ROUTES));
        $this->assertTrue(Cache::has(RouteCacheService::CACHE_KEY_POPULAR_ROUTES));
        $this->assertTrue(Cache::has(RouteCacheService::CACHE_KEY_ACTIVE_REQUESTS_COUNTS));
    }

    public function test_warm_cache_handles_exceptions()
    {
        // Mock Route to throw exception
        $this->partialMock(Route::class, function ($mock) {
            $mock->shouldReceive('active')->andThrow(new \Exception('Database error'));
        });

        Log::shouldReceive('info')->with('Starting route cache warming')->once();
        Log::shouldReceive('error')->with('Failed to warm route cache', \Mockery::type('array'))->once();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database error');

        $this->service->warmCache();
    }

    public function test_clear_cache_removes_all_route_caches()
    {
        $route = Route::factory()->create(['is_active' => true]);
        
        // Pre-populate some caches
        Cache::put(RouteCacheService::CACHE_KEY_ACTIVE_ROUTES, collect(['test']));
        Cache::put(RouteCacheService::CACHE_KEY_POPULAR_ROUTES, collect(['test']));
        Cache::put(RouteCacheService::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $route->id, 5);

        $this->mockLocationCacheService->shouldReceive('getCountries')
            ->andReturn(collect([
                (object)['id' => 1],
                (object)['id' => 2]
            ]));

        Log::shouldReceive('info')->with('Clearing all route caches')->once();
        Log::shouldReceive('info')->with('Route cache cleared successfully')->once();

        $this->service->clearCache();

        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_ACTIVE_ROUTES));
        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_POPULAR_ROUTES));
        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $route->id));
    }

    public function test_clear_cache_handles_exceptions()
    {
        $this->mockLocationCacheService->shouldReceive('getCountries')
            ->andThrow(new \Exception('Service error'));

        Log::shouldReceive('info')->with('Clearing all route caches')->once();
        Log::shouldReceive('error')->with('Failed to clear route cache', Mockery::type('array'))->once();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Service error');

        $this->service->clearCache();
    }

    public function test_invalidate_route_cache_clears_relevant_caches()
    {
        $country1 = Location::factory()->create(['type' => 'country']);
        $country2 = Location::factory()->create(['type' => 'country']);
        
        $route = Route::factory()->create([
            'from_location_id' => $country1->id,
            'to_location_id' => $country2->id,
            'is_active' => true
        ]);

        // Pre-populate caches
        Cache::put(RouteCacheService::CACHE_KEY_ACTIVE_ROUTES, collect(['test']));
        Cache::put(RouteCacheService::CACHE_KEY_POPULAR_ROUTES, collect(['test']));
        Cache::put(RouteCacheService::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $route->id, 5);

        Log::shouldReceive('info')->with('Invalidating route cache', ['route_id' => $route->id])->once();
        Log::shouldReceive('info')->with('Route cache invalidated successfully', ['route_id' => $route->id])->once();

        $this->service->invalidateRouteCache($route->id);

        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_ACTIVE_ROUTES));
        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_POPULAR_ROUTES));
        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $route->id));
    }

    public function test_invalidate_route_cache_without_route_id()
    {
        Cache::put(RouteCacheService::CACHE_KEY_ACTIVE_ROUTES, collect(['test']));
        Cache::put(RouteCacheService::CACHE_KEY_POPULAR_ROUTES, collect(['test']));

        Log::shouldReceive('info')->with('Invalidating route cache', ['route_id' => null])->once();
        Log::shouldReceive('info')->with('Route cache invalidated successfully', ['route_id' => null])->once();

        $this->service->invalidateRouteCache();

        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_ACTIVE_ROUTES));
        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_POPULAR_ROUTES));
    }

    public function test_invalidate_request_counts_cache()
    {
        $route = Route::factory()->create(['is_active' => true]);
        
        Cache::put(RouteCacheService::CACHE_KEY_POPULAR_ROUTES, collect(['test']));
        Cache::put(RouteCacheService::CACHE_KEY_ACTIVE_REQUESTS_COUNTS, collect(['test']));
        Cache::put(RouteCacheService::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $route->id, 5);

        Log::shouldReceive('info')->with('Invalidating route request counts cache')->once();
        Log::shouldReceive('info')->with('Route request counts cache invalidated successfully')->once();

        $this->service->invalidateRequestCountsCache();

        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_POPULAR_ROUTES));
        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_ACTIVE_REQUESTS_COUNTS));
        $this->assertFalse(Cache::has(RouteCacheService::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $route->id));
    }

    public function test_get_cache_stats_returns_correct_information()
    {
        $route = Route::factory()->create(['is_active' => true]);
        
        // Set up some caches
        Cache::put(RouteCacheService::CACHE_KEY_ACTIVE_ROUTES, collect(['test']));
        Cache::put(RouteCacheService::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $route->id, 5);

        $this->mockLocationCacheService->shouldReceive('getCountries')
            ->andReturn(collect([
                (object)['id' => 1],
                (object)['id' => 2],
                (object)['id' => 3]
            ]));

        $stats = $this->service->getCacheStats();

        $this->assertArrayHasKey('active_routes', $stats);
        $this->assertArrayHasKey('popular_routes', $stats);
        $this->assertArrayHasKey('active_requests_counts', $stats);
        $this->assertArrayHasKey('individual_route_counts', $stats);
        $this->assertArrayHasKey('country_routes', $stats);
        
        $this->assertTrue($stats['active_routes']);
        $this->assertFalse($stats['popular_routes']);
        $this->assertIsInt($stats['individual_route_counts']);
        $this->assertIsInt($stats['country_routes']);
    }

    public function test_cache_keys_are_properly_defined()
    {
        $this->assertEquals('routes:all', RouteCacheService::CACHE_KEY_ALL_ROUTES);
        $this->assertEquals('routes:active', RouteCacheService::CACHE_KEY_ACTIVE_ROUTES);
        $this->assertEquals('routes:popular', RouteCacheService::CACHE_KEY_POPULAR_ROUTES);
        $this->assertEquals('routes:route:', RouteCacheService::CACHE_KEY_ROUTE_PREFIX);
        $this->assertEquals('routes:requests_count:', RouteCacheService::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX);
        $this->assertEquals('routes:country_routes:', RouteCacheService::CACHE_KEY_COUNTRY_ROUTES_PREFIX);
        $this->assertEquals('routes:active_requests_counts', RouteCacheService::CACHE_KEY_ACTIVE_REQUESTS_COUNTS);
    }

    public function test_cache_ttl_constants_are_properly_defined()
    {
        $this->assertEquals(60 * 60 * 6, RouteCacheService::CACHE_TTL_ROUTES);
        $this->assertEquals(60 * 60 * 1, RouteCacheService::CACHE_TTL_COUNTS);
    }

    public function test_calculate_all_route_requests_counts_handles_empty_routes()
    {
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateAllRouteRequestsCounts');
        $method->setAccessible(true);

        $result = $method->invoke($this->service, collect([]));

        $this->assertEquals([], $result);
    }

    public function test_cache_invalidation_methods_handle_exceptions_gracefully()
    {
        // Test invalidateRouteCache with exception
        Route::factory()->create(['is_active' => true])->delete(); // Create then delete to cause potential issues

        Log::shouldReceive('info')->with('Invalidating route cache', Mockery::type('array'))->once();
        Log::shouldReceive('error')->with('Failed to invalidate route cache', Mockery::type('array'))->never();

        // Should not throw exception
        $this->service->invalidateRouteCache(999);

        // Test invalidateRequestCountsCache with exception
        Log::shouldReceive('info')->with('Invalidating route request counts cache')->once();
        Log::shouldReceive('error')->with('Failed to invalidate route request counts cache', Mockery::type('array'))->never();

        // Should not throw exception
        $this->service->invalidateRequestCountsCache();
    }
}