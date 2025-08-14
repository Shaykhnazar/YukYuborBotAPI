<?php

namespace App\Services;

use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class LocationCacheService
{
    // Cache keys
    public const CACHE_KEY_ALL_LOCATIONS = 'locations:all';
    public const CACHE_KEY_COUNTRIES = 'locations:countries';
    public const CACHE_KEY_CITIES = 'locations:cities';
    public const CACHE_KEY_COUNTRY_PREFIX = 'locations:country:';
    public const CACHE_KEY_CITIES_BY_COUNTRY_PREFIX = 'locations:cities_by_country:';
    public const CACHE_KEY_LOCATION_PREFIX = 'locations:location:';

    // Cache TTL (24 hours)
    const CACHE_TTL = 60 * 60 * 24;

    /**
     * Get all locations with hierarchical structure
     */
    public function getAllLocations(): Collection
    {
        return Cache::remember(self::CACHE_KEY_ALL_LOCATIONS, self::CACHE_TTL, function () {
            Log::info('Cache miss: Loading all locations from database');
            
            return Location::with(['parent', 'children'])
                ->active()
                ->orderBy('type')
                ->orderBy('name')
                ->get();
        });
    }

    /**
     * Get all countries
     */
    public function getCountries(): Collection
    {
        return Cache::remember(self::CACHE_KEY_COUNTRIES, self::CACHE_TTL, function () {
            Log::info('Cache miss: Loading countries from database');
            
            return Location::countries()
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'country_code', 'type', 'is_active']);
        });
    }

    /**
     * Get all cities
     */
    public function getCities(): Collection
    {
        return Cache::remember(self::CACHE_KEY_CITIES, self::CACHE_TTL, function () {
            Log::info('Cache miss: Loading cities from database');
            
            return Location::cities()
                ->active()
                ->with('parent:id,name,type')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id', 'type', 'is_active']);
        });
    }

    /**
     * Get countries with their popular cities (for select dropdowns)
     */
    public function getCountriesWithPopularCities(int $cityLimit = 3): Collection
    {
        $cacheKey = self::CACHE_KEY_COUNTRIES . ':with_cities:' . $cityLimit;
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($cityLimit) {
            Log::info('Cache miss: Loading countries with popular cities from database', ['city_limit' => $cityLimit]);
            
            return Location::countries()
                ->active()
                ->with(['children' => function($query) use ($cityLimit) {
                    $query->select('id', 'name', 'parent_id', 'type')
                        ->active()
                        ->orderBy('name')
                        ->limit($cityLimit);
                }])
                ->orderBy('name')
                ->get(['id', 'name', 'country_code', 'type', 'is_active']);
        });
    }

    /**
     * Get cities by country ID
     */
    public function getCitiesByCountry(int $countryId, ?string $searchQuery = null): Collection
    {
        $cacheKey = self::CACHE_KEY_CITIES_BY_COUNTRY_PREFIX . $countryId;
        
        // If there's a search query, don't cache (dynamic search)
        if ($searchQuery) {
            Log::info('Searching cities by country with query (no cache)', [
                'country_id' => $countryId,
                'query' => $searchQuery
            ]);
            
            return Location::cities()
                ->where('parent_id', $countryId)
                ->where('name', 'LIKE', '%' . $searchQuery . '%')
                ->active()
                ->with('parent:id,name,type')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id', 'type']);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($countryId) {
            Log::info('Cache miss: Loading cities by country from database', ['country_id' => $countryId]);
            
            return Location::cities()
                ->where('parent_id', $countryId)
                ->active()
                ->with('parent:id,name,type')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id', 'type']);
        });
    }

    /**
     * Get a specific location by ID
     */
    public function getLocationById(int $locationId): ?Location
    {
        $cacheKey = self::CACHE_KEY_LOCATION_PREFIX . $locationId;
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($locationId) {
            Log::info('Cache miss: Loading location by ID from database', ['location_id' => $locationId]);
            
            return Location::with('parent')
                ->find($locationId);
        });
    }

    /**
     * Get multiple locations by IDs
     */
    public function getLocationsByIds(array $locationIds): Collection
    {
        $locations = collect();
        $uncachedIds = [];

        // Try to get each location from cache first
        foreach ($locationIds as $id) {
            $cacheKey = self::CACHE_KEY_LOCATION_PREFIX . $id;
            $location = Cache::get($cacheKey);
            
            if ($location) {
                $locations->push($location);
            } else {
                $uncachedIds[] = $id;
            }
        }

        // Load uncached locations from database and cache them
        if (!empty($uncachedIds)) {
            Log::info('Cache miss: Loading multiple locations from database', ['location_ids' => $uncachedIds]);
            
            $uncachedLocations = Location::with('parent')
                ->whereIn('id', $uncachedIds)
                ->get();

            foreach ($uncachedLocations as $location) {
                $cacheKey = self::CACHE_KEY_LOCATION_PREFIX . $location->id;
                Cache::put($cacheKey, $location, self::CACHE_TTL);
                $locations->push($location);
            }
        }

        return $locations;
    }

    /**
     * Search locations by name
     */
    public function searchLocations(string $query, ?string $type = null, int $limit = 10): Collection
    {
        // Don't cache search results as they are dynamic
        Log::info('Searching locations (no cache)', [
            'query' => $query,
            'type' => $type,
            'limit' => $limit
        ]);

        return Location::active()
            ->where('name', 'LIKE', '%' . $query . '%')
            ->when($type && $type !== 'all', function ($q) use ($type) {
                return $q->where('type', $type);
            })
            ->with('parent')
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(function ($location) {
                return [
                    'id' => $location->id,
                    'name' => $location->name,
                    'type' => $location->type,
                    'display_name' => $location->type === 'city'
                        ? $location->parent->name . ', ' . $location->name
                        : $location->name,
                    'parent_id' => $location->parent_id,
                    'country_name' => $location->type === 'city'
                        ? $location->parent->name
                        : $location->name,
                ];
            });
    }

    /**
     * Get location hierarchy (country -> cities) for routing
     */
    public function getLocationHierarchy(): array
    {
        $cacheKey = 'locations:hierarchy';
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            Log::info('Cache miss: Building location hierarchy from database');
            
            $countries = $this->getCountries();
            $cities = $this->getCities();

            $hierarchy = [];
            
            foreach ($countries as $country) {
                $countryCities = $cities->where('parent_id', $country->id)->values();
                
                $hierarchy[$country->id] = [
                    'id' => $country->id,
                    'name' => $country->name,
                    'country_code' => $country->country_code,
                    'type' => 'country',
                    'cities' => $countryCities->map(function ($city) {
                        return [
                            'id' => $city->id,
                            'name' => $city->name,
                            'type' => 'city',
                        ];
                    })->toArray()
                ];
            }

            return $hierarchy;
        });
    }

    /**
     * Warm up the cache with all essential location data
     */
    public function warmCache(): void
    {
        Log::info('Starting location cache warming');

        try {
            // Warm basic caches
            $this->getCountries();
            $this->getCities();
            $this->getAllLocations();
            $this->getCountriesWithPopularCities();
            $this->getLocationHierarchy();

            // Warm country-specific city caches
            $countries = $this->getCountries();
            foreach ($countries as $country) {
                $this->getCitiesByCountry($country->id);
            }

            Log::info('Location cache warming completed successfully', [
                'countries_count' => $countries->count(),
                'total_caches' => $countries->count() + 5 // Basic caches + country caches
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to warm location cache', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Clear all location caches
     */
    public function clearCache(): void
    {
        Log::info('Clearing all location caches');

        try {
            // Clear basic caches
            Cache::forget(self::CACHE_KEY_ALL_LOCATIONS);
            Cache::forget(self::CACHE_KEY_COUNTRIES);
            Cache::forget(self::CACHE_KEY_CITIES);
            Cache::forget('locations:hierarchy');

            // Clear country-specific caches
            $countries = Location::countries()->get(['id']);
            foreach ($countries as $country) {
                Cache::forget(self::CACHE_KEY_CITIES_BY_COUNTRY_PREFIX . $country->id);
            }

            // Clear location-specific caches (this is approximate, real implementation might need Redis SCAN)
            $locations = Location::get(['id']);
            foreach ($locations as $location) {
                Cache::forget(self::CACHE_KEY_LOCATION_PREFIX . $location->id);
            }

            // Clear country with cities caches (multiple variations)
            for ($i = 1; $i <= 10; $i++) {
                Cache::forget(self::CACHE_KEY_COUNTRIES . ':with_cities:' . $i);
            }

            Log::info('Location cache cleared successfully');

        } catch (\Exception $e) {
            Log::error('Failed to clear location cache', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Invalidate cache when locations are updated
     */
    public function invalidateLocation(int $locationId): void
    {
        Log::info('Invalidating cache for location', ['location_id' => $locationId]);

        try {
            $location = Location::find($locationId);
            
            if (!$location) {
                return;
            }

            // Clear specific location cache
            Cache::forget(self::CACHE_KEY_LOCATION_PREFIX . $locationId);

            // Clear broad caches that might include this location
            Cache::forget(self::CACHE_KEY_ALL_LOCATIONS);
            Cache::forget('locations:hierarchy');

            if ($location->type === 'country') {
                // Clear country-related caches
                Cache::forget(self::CACHE_KEY_COUNTRIES);
                Cache::forget(self::CACHE_KEY_CITIES_BY_COUNTRY_PREFIX . $locationId);
                
                // Clear countries with cities caches
                for ($i = 1; $i <= 10; $i++) {
                    Cache::forget(self::CACHE_KEY_COUNTRIES . ':with_cities:' . $i);
                }
            } else {
                // Clear city-related caches
                Cache::forget(self::CACHE_KEY_CITIES);
                if ($location->parent_id) {
                    Cache::forget(self::CACHE_KEY_CITIES_BY_COUNTRY_PREFIX . $location->parent_id);
                }
            }

            Log::info('Location cache invalidated successfully', ['location_id' => $locationId]);

        } catch (\Exception $e) {
            Log::error('Failed to invalidate location cache', [
                'location_id' => $locationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        $stats = [
            'all_locations' => Cache::has(self::CACHE_KEY_ALL_LOCATIONS),
            'countries' => Cache::has(self::CACHE_KEY_COUNTRIES),
            'cities' => Cache::has(self::CACHE_KEY_CITIES),
            'hierarchy' => Cache::has('locations:hierarchy'),
            'countries_with_cities' => [],
            'cities_by_country' => [],
            'individual_locations' => 0
        ];

        // Check countries with cities cache variations
        for ($i = 1; $i <= 5; $i++) {
            $key = self::CACHE_KEY_COUNTRIES . ':with_cities:' . $i;
            $stats['countries_with_cities'][$i] = Cache::has($key);
        }

        // Check some country-specific city caches
        $countries = Location::countries()->limit(5)->get(['id']);
        foreach ($countries as $country) {
            $key = self::CACHE_KEY_CITIES_BY_COUNTRY_PREFIX . $country->id;
            $stats['cities_by_country'][$country->id] = Cache::has($key);
        }

        // Count individual location caches (sample)
        $locations = Location::limit(10)->get(['id']);
        foreach ($locations as $location) {
            $key = self::CACHE_KEY_LOCATION_PREFIX . $location->id;
            if (Cache::has($key)) {
                $stats['individual_locations']++;
            }
        }

        return $stats;
    }
}