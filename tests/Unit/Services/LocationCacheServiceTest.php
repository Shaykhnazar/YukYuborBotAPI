<?php

namespace Tests\Unit\Services;

use App\Models\Location;
use App\Services\LocationCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class LocationCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LocationCacheService $service;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = new LocationCacheService();
        
        // Clear cache before each test
        Cache::flush();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_get_all_locations_returns_cached_data()
    {
        $country = Location::factory()->create([
            'type' => 'country',
            'name' => 'Test Country',
            'is_active' => true
        ]);
        
        $city = Location::factory()->create([
            'type' => 'city',
            'name' => 'Test City',
            'parent_id' => $country->id,
            'is_active' => true
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading all locations from database')
            ->once();

        $result = $this->service->getAllLocations();
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);

        // Second call should use cache (no log message expected)
        $result2 = $this->service->getAllLocations();
        $this->assertEquals($result, $result2);
    }

    public function test_get_countries_returns_active_countries_only()
    {
        $activeCountry = Location::factory()->create([
            'type' => 'country',
            'name' => 'Active Country',
            'is_active' => true
        ]);
        
        $inactiveCountry = Location::factory()->create([
            'type' => 'country',
            'name' => 'Inactive Country',
            'is_active' => false
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading countries from database')
            ->once();

        $result = $this->service->getCountries();
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);
        $this->assertEquals('Active Country', $result->first()->name);
        $this->assertEquals($activeCountry->id, $result->first()->id);
    }

    public function test_get_cities_returns_active_cities_only()
    {
        $country = Location::factory()->create(['type' => 'country', 'is_active' => true]);
        
        $activeCity = Location::factory()->create([
            'type' => 'city',
            'name' => 'Active City',
            'parent_id' => $country->id,
            'is_active' => true
        ]);
        
        $inactiveCity = Location::factory()->create([
            'type' => 'city',
            'name' => 'Inactive City',
            'parent_id' => $country->id,
            'is_active' => false
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading cities from database')
            ->once();

        $result = $this->service->getCities();
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);
        $this->assertEquals('Active City', $result->first()->name);
        $this->assertEquals($activeCity->id, $result->first()->id);
    }

    public function test_get_countries_with_popular_cities_respects_limit()
    {
        $country = Location::factory()->create([
            'type' => 'country',
            'name' => 'Test Country',
            'is_active' => true
        ]);
        
        // Create 5 cities
        Location::factory()->count(5)->create([
            'type' => 'city',
            'parent_id' => $country->id,
            'is_active' => true
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading countries with popular cities from database', ['city_limit' => 2])
            ->once();

        $result = $this->service->getCountriesWithPopularCities(2);
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);
        
        $country = $result->first();
        $this->assertCount(2, $country->children); // Should respect the limit of 2 cities
    }

    public function test_get_cities_by_country_without_search_query()
    {
        $country = Location::factory()->create(['type' => 'country', 'is_active' => true]);
        
        $city1 = Location::factory()->create([
            'type' => 'city',
            'name' => 'Alpha City',
            'parent_id' => $country->id,
            'is_active' => true
        ]);
        
        $city2 = Location::factory()->create([
            'type' => 'city',
            'name' => 'Beta City',
            'parent_id' => $country->id,
            'is_active' => true
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading cities by country from database', ['country_id' => $country->id])
            ->once();

        $result = $this->service->getCitiesByCountry($country->id);
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
    }

    public function test_get_cities_by_country_with_search_query_bypasses_cache()
    {
        $country = Location::factory()->create(['type' => 'country', 'is_active' => true]);
        
        $matchingCity = Location::factory()->create([
            'type' => 'city',
            'name' => 'Alpha City',
            'parent_id' => $country->id,
            'is_active' => true
        ]);
        
        $nonMatchingCity = Location::factory()->create([
            'type' => 'city',
            'name' => 'Beta City',
            'parent_id' => $country->id,
            'is_active' => true
        ]);

        Log::shouldReceive('info')
            ->with('Searching cities by country with query (no cache)', [
                'country_id' => $country->id,
                'query' => 'Alpha'
            ])
            ->once();

        $result = $this->service->getCitiesByCountry($country->id, 'Alpha');
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);
        $this->assertEquals('Alpha City', $result->first()->name);
    }

    public function test_get_location_by_id_caches_individual_locations()
    {
        $location = Location::factory()->create([
            'type' => 'city',
            'name' => 'Test Location',
            'is_active' => true
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading location by ID from database', ['location_id' => $location->id])
            ->once();

        $result = $this->service->getLocationById($location->id);
        
        $this->assertInstanceOf(Location::class, $result);
        $this->assertEquals($location->id, $result->id);
        $this->assertEquals('Test Location', $result->name);

        // Second call should use cache
        $result2 = $this->service->getLocationById($location->id);
        $this->assertEquals($result->id, $result2->id);
    }

    public function test_get_location_by_id_returns_null_for_nonexistent_id()
    {
        Log::shouldReceive('info')
            ->with('Cache miss: Loading location by ID from database', ['location_id' => 999])
            ->once();

        $result = $this->service->getLocationById(999);
        
        $this->assertNull($result);
    }

    public function test_get_locations_by_ids_uses_cache_efficiently()
    {
        $location1 = Location::factory()->create(['name' => 'Location 1']);
        $location2 = Location::factory()->create(['name' => 'Location 2']);
        $location3 = Location::factory()->create(['name' => 'Location 3']);

        // Pre-cache location1
        Cache::put(LocationCacheService::CACHE_KEY_LOCATION_PREFIX . $location1->id, $location1, 3600);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading multiple locations from database', ['location_ids' => [$location2->id, $location3->id]])
            ->once();

        $result = $this->service->getLocationsByIds([$location1->id, $location2->id, $location3->id]);
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(3, $result);
        
        // Verify that location2 and location3 are now cached
        $this->assertTrue(Cache::has(LocationCacheService::CACHE_KEY_LOCATION_PREFIX . $location2->id));
        $this->assertTrue(Cache::has(LocationCacheService::CACHE_KEY_LOCATION_PREFIX . $location3->id));
    }

    public function test_search_locations_does_not_use_cache()
    {
        $country = Location::factory()->create([
            'type' => 'country',
            'name' => 'Test Country',
            'is_active' => true
        ]);
        
        $city = Location::factory()->create([
            'type' => 'city',
            'name' => 'Test City',
            'parent_id' => $country->id,
            'is_active' => true
        ]);

        Log::shouldReceive('info')
            ->with('Searching locations (no cache)', [
                'query' => 'Test',
                'type' => null,
                'limit' => 10
            ])
            ->once();

        $result = $this->service->searchLocations('Test');
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertGreaterThan(0, $result->count());
        
        // Check that results have the expected structure
        $firstResult = $result->first();
        $this->assertArrayHasKey('id', $firstResult);
        $this->assertArrayHasKey('name', $firstResult);
        $this->assertArrayHasKey('type', $firstResult);
        $this->assertArrayHasKey('display_name', $firstResult);
    }

    public function test_search_locations_filters_by_type()
    {
        $country = Location::factory()->create([
            'type' => 'country',
            'name' => 'Test Country',
            'is_active' => true
        ]);
        
        $city = Location::factory()->create([
            'type' => 'city',
            'name' => 'Test City',
            'parent_id' => $country->id,
            'is_active' => true
        ]);

        Log::shouldReceive('info')
            ->with('Searching locations (no cache)', [
                'query' => 'Test',
                'type' => 'country',
                'limit' => 5
            ])
            ->once();

        $result = $this->service->searchLocations('Test', 'country', 5);
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertGreaterThan(0, $result->count());
        
        foreach ($result as $location) {
            $this->assertEquals('country', $location['type']);
        }
    }

    public function test_get_location_hierarchy_builds_correct_structure()
    {
        $country = Location::factory()->create([
            'type' => 'country',
            'name' => 'Test Country',
            'country_code' => 'TC',
            'is_active' => true
        ]);
        
        $city = Location::factory()->create([
            'type' => 'city',
            'name' => 'Test City',
            'parent_id' => $country->id,
            'is_active' => true
        ]);

        Log::shouldReceive('info')
            ->with('Cache miss: Loading countries from database')
            ->once();
        Log::shouldReceive('info')
            ->with('Cache miss: Loading cities from database')
            ->once();
        Log::shouldReceive('info')
            ->with('Cache miss: Building location hierarchy from database')
            ->once();

        $result = $this->service->getLocationHierarchy();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey($country->id, $result);
        
        $countryData = $result[$country->id];
        $this->assertEquals('Test Country', $countryData['name']);
        $this->assertEquals('TC', $countryData['country_code']);
        $this->assertArrayHasKey('cities', $countryData);
        $this->assertCount(1, $countryData['cities']);
        $this->assertEquals('Test City', $countryData['cities'][0]['name']);
    }

    public function test_warm_cache_loads_all_essential_data()
    {
        $country = Location::factory()->create(['type' => 'country', 'is_active' => true]);
        Location::factory()->create([
            'type' => 'city',
            'parent_id' => $country->id,
            'is_active' => true
        ]);

        Log::shouldReceive('info')->with('Starting location cache warming')->once();
        Log::shouldReceive('info')->with('Cache miss: Loading countries from database')->once();
        Log::shouldReceive('info')->with('Cache miss: Loading cities from database')->once();
        Log::shouldReceive('info')->with('Cache miss: Loading all locations from database')->once();
        Log::shouldReceive('info')->with('Cache miss: Loading countries with popular cities from database', ['city_limit' => 3])->once();
        Log::shouldReceive('info')->with('Cache miss: Building location hierarchy from database')->once();
        Log::shouldReceive('info')->with('Cache miss: Loading cities by country from database', ['country_id' => $country->id])->once();
        Log::shouldReceive('info')->with('Location cache warming completed successfully', \Mockery::type('array'))->once();

        $this->service->warmCache();

        // Verify caches are warmed
        $this->assertTrue(Cache::has(LocationCacheService::CACHE_KEY_COUNTRIES));
        $this->assertTrue(Cache::has(LocationCacheService::CACHE_KEY_CITIES));
        $this->assertTrue(Cache::has(LocationCacheService::CACHE_KEY_ALL_LOCATIONS));
        $this->assertTrue(Cache::has('locations:hierarchy'));
    }

    public function test_warm_cache_handles_exceptions()
    {
        // Simulate an exception by mocking Cache to throw exception
        Cache::shouldReceive('remember')
            ->andThrow(new \Exception('Database error'));

        Log::shouldReceive('info')->with('Starting location cache warming')->once();
        Log::shouldReceive('error')->with('Failed to warm location cache', \Mockery::type('array'))->once();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database error');

        $this->service->warmCache();
    }

    public function test_clear_cache_removes_all_location_caches()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $location = Location::factory()->create(['type' => 'city']);
        
        // Pre-populate some caches
        Cache::put(LocationCacheService::CACHE_KEY_COUNTRIES, collect(['test']));
        Cache::put(LocationCacheService::CACHE_KEY_CITIES, collect(['test']));
        Cache::put(LocationCacheService::CACHE_KEY_LOCATION_PREFIX . $location->id, $location);

        Log::shouldReceive('info')->with('Clearing all location caches')->once();
        Log::shouldReceive('info')->with('Location cache cleared successfully')->once();

        $this->service->clearCache();

        $this->assertFalse(Cache::has(LocationCacheService::CACHE_KEY_COUNTRIES));
        $this->assertFalse(Cache::has(LocationCacheService::CACHE_KEY_CITIES));
        $this->assertFalse(Cache::has(LocationCacheService::CACHE_KEY_LOCATION_PREFIX . $location->id));
    }

    public function test_clear_cache_handles_exceptions()
    {
        // Simulate an exception by making Location::countries() throw
        Location::factory()->create(['type' => 'country']); // Create a country first
        
        // Mock the Cache facade to throw an exception during forget operations
        Cache::shouldReceive('forget')
            ->once()
            ->andThrow(new \Exception('Database error'));

        Log::shouldReceive('info')->with('Clearing all location caches')->once();
        Log::shouldReceive('error')->with('Failed to clear location cache', \Mockery::type('array'))->once();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Database error');

        $this->service->clearCache();
    }

    public function test_invalidate_location_for_country()
    {
        $country = Location::factory()->create(['type' => 'country']);
        
        Cache::put(LocationCacheService::CACHE_KEY_COUNTRIES, collect(['test']));
        Cache::put(LocationCacheService::CACHE_KEY_ALL_LOCATIONS, collect(['test']));
        Cache::put(LocationCacheService::CACHE_KEY_LOCATION_PREFIX . $country->id, $country);

        Log::shouldReceive('info')->with('Invalidating cache for location', ['location_id' => $country->id])->once();
        Log::shouldReceive('info')->with('Location cache invalidated successfully', ['location_id' => $country->id])->once();

        $this->service->invalidateLocation($country->id);

        $this->assertFalse(Cache::has(LocationCacheService::CACHE_KEY_COUNTRIES));
        $this->assertFalse(Cache::has(LocationCacheService::CACHE_KEY_ALL_LOCATIONS));
        $this->assertFalse(Cache::has(LocationCacheService::CACHE_KEY_LOCATION_PREFIX . $country->id));
    }

    public function test_invalidate_location_for_city()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $city = Location::factory()->create(['type' => 'city', 'parent_id' => $country->id]);
        
        Cache::put(LocationCacheService::CACHE_KEY_CITIES, collect(['test']));
        Cache::put(LocationCacheService::CACHE_KEY_CITIES_BY_COUNTRY_PREFIX . $country->id, collect(['test']));
        Cache::put(LocationCacheService::CACHE_KEY_LOCATION_PREFIX . $city->id, $city);

        Log::shouldReceive('info')->with('Invalidating cache for location', ['location_id' => $city->id])->once();
        Log::shouldReceive('info')->with('Location cache invalidated successfully', ['location_id' => $city->id])->once();

        $this->service->invalidateLocation($city->id);

        $this->assertFalse(Cache::has(LocationCacheService::CACHE_KEY_CITIES));
        $this->assertFalse(Cache::has(LocationCacheService::CACHE_KEY_CITIES_BY_COUNTRY_PREFIX . $country->id));
        $this->assertFalse(Cache::has(LocationCacheService::CACHE_KEY_LOCATION_PREFIX . $city->id));
    }

    public function test_invalidate_location_handles_nonexistent_location()
    {
        Log::shouldReceive('info')->with('Invalidating cache for location', ['location_id' => 999])->once();

        // Should not throw exception or log error
        $this->service->invalidateLocation(999);
    }

    public function test_get_cache_stats_returns_correct_information()
    {
        $country = Location::factory()->create(['type' => 'country']);
        $location = Location::factory()->create(['type' => 'city']);
        
        // Set up some caches
        Cache::put(LocationCacheService::CACHE_KEY_COUNTRIES, collect(['test']));
        Cache::put(LocationCacheService::CACHE_KEY_LOCATION_PREFIX . $location->id, $location);
        Cache::put(LocationCacheService::CACHE_KEY_COUNTRIES . ':with_cities:1', collect(['test']));

        $stats = $this->service->getCacheStats();

        $this->assertArrayHasKey('all_locations', $stats);
        $this->assertArrayHasKey('countries', $stats);
        $this->assertArrayHasKey('cities', $stats);
        $this->assertArrayHasKey('hierarchy', $stats);
        $this->assertArrayHasKey('countries_with_cities', $stats);
        $this->assertArrayHasKey('cities_by_country', $stats);
        $this->assertArrayHasKey('individual_locations', $stats);
        
        $this->assertTrue($stats['countries']);
        $this->assertFalse($stats['cities']);
        $this->assertIsArray($stats['countries_with_cities']);
        $this->assertIsArray($stats['cities_by_country']);
        $this->assertIsInt($stats['individual_locations']);
    }

    public function test_cache_constants_are_properly_defined()
    {
        $this->assertEquals('locations:all', LocationCacheService::CACHE_KEY_ALL_LOCATIONS);
        $this->assertEquals('locations:countries', LocationCacheService::CACHE_KEY_COUNTRIES);
        $this->assertEquals('locations:cities', LocationCacheService::CACHE_KEY_CITIES);
        $this->assertEquals('locations:country:', LocationCacheService::CACHE_KEY_COUNTRY_PREFIX);
        $this->assertEquals('locations:cities_by_country:', LocationCacheService::CACHE_KEY_CITIES_BY_COUNTRY_PREFIX);
        $this->assertEquals('locations:location:', LocationCacheService::CACHE_KEY_LOCATION_PREFIX);
        $this->assertEquals(60 * 60 * 24, LocationCacheService::CACHE_TTL);
    }

    public function test_search_locations_handles_empty_results()
    {
        Log::shouldReceive('info')
            ->with('Searching locations (no cache)', [
                'query' => 'NonexistentLocation',
                'type' => null,
                'limit' => 10
            ])
            ->once();

        $result = $this->service->searchLocations('NonexistentLocation');
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_get_locations_by_ids_with_empty_array()
    {
        $result = $this->service->getLocationsByIds([]);
        
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(0, $result);
    }

    public function test_invalidate_location_handles_exceptions_gracefully()
    {
        Log::shouldReceive('info')->with('Invalidating cache for location', ['location_id' => 123])->once();
        
        // The invalidateLocation method will handle the case where location doesn't exist gracefully
        // It just returns early if location is not found, no error is logged
        
        // Should not throw exception
        $this->service->invalidateLocation(123);
    }
}