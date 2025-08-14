<?php

namespace App\Services;

use App\Models\Route;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class RouteCacheService
{
    // Cache keys
    const CACHE_KEY_ALL_ROUTES = 'routes:all';
    const CACHE_KEY_ACTIVE_ROUTES = 'routes:active';
    const CACHE_KEY_POPULAR_ROUTES = 'routes:popular';
    const CACHE_KEY_ROUTE_PREFIX = 'routes:route:';
    const CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX = 'routes:requests_count:';
    const CACHE_KEY_COUNTRY_ROUTES_PREFIX = 'routes:country_routes:';
    const CACHE_KEY_ACTIVE_REQUESTS_COUNTS = 'routes:active_requests_counts';

    // Cache TTL (6 hours for route data, 1 hour for request counts)
    const CACHE_TTL_ROUTES = 60 * 60 * 6;
    const CACHE_TTL_COUNTS = 60 * 60 * 1;

    /**
     * Get all active routes with location details
     */
    public function getActiveRoutes(): Collection
    {
        return Cache::remember(self::CACHE_KEY_ACTIVE_ROUTES, self::CACHE_TTL_ROUTES, function () {
            Log::info('Cache miss: Loading active routes from database');
            
            return Route::active()
                ->with(['fromLocation.parent', 'toLocation.parent'])
                ->byPriority()
                ->get();
        });
    }

    /**
     * Get popular routes with cached request counts
     */
    public function getPopularRoutes(): Collection
    {
        return Cache::remember(self::CACHE_KEY_POPULAR_ROUTES, self::CACHE_TTL_COUNTS, function () {
            Log::info('Cache miss: Building popular routes from database');
            
            $routes = $this->getActiveRoutes();
            
            return $routes->map(function ($route) {
                // Get from location details
                $fromLocation = $route->fromLocation;
                $fromCountry = $fromLocation->type === 'country'
                    ? $fromLocation
                    : $fromLocation->parent;

                // Get to location details
                $toLocation = $route->toLocation;
                $toCountry = $toLocation->type === 'country'
                    ? $toLocation
                    : $toLocation->parent;

                // Get popular cities for both countries using cache
                $fromCities = app(LocationCacheService::class)->getCitiesByCountry($fromCountry->id)
                    ->take(3)
                    ->map(fn($city) => ['id' => $city['id'], 'name' => $city['name']]);

                $toCities = app(LocationCacheService::class)->getCitiesByCountry($toCountry->id)
                    ->take(3)
                    ->map(fn($city) => ['id' => $city['id'], 'name' => $city['name']]);

                // Get cached request count
                $activeRequestsCount = $this->getRouteRequestsCount($route->id);

                return [
                    'id' => $route->id,
                    'from' => [
                        'id' => $fromCountry->id,
                        'name' => $fromCountry->name,
                        'type' => 'country'
                    ],
                    'to' => [
                        'id' => $toCountry->id,
                        'name' => $toCountry->name,
                        'type' => 'country'
                    ],
                    'active_requests' => $activeRequestsCount,
                    'popular_cities' => [
                        ...$fromCities,
                        ...$toCities
                    ],
                    'priority' => $route->priority,
                    'description' => $route->description
                ];
            });
        });
    }

    /**
     * Get cached request count for a specific route
     */
    public function getRouteRequestsCount(int $routeId): int
    {
        $cacheKey = self::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $routeId;
        
        return Cache::remember($cacheKey, self::CACHE_TTL_COUNTS, function () use ($routeId) {
            Log::info('Cache miss: Calculating route requests count', ['route_id' => $routeId]);
            
            $route = Route::with(['fromLocation.children', 'toLocation.children'])->find($routeId);
            
            if (!$route) {
                return 0;
            }

            return $this->calculateRouteRequestsCount($route);
        });
    }

    /**
     * Get all routes with their cached request counts
     */
    public function getRoutesWithRequestCounts(): Collection
    {
        return Cache::remember(self::CACHE_KEY_ACTIVE_REQUESTS_COUNTS, self::CACHE_TTL_COUNTS, function () {
            Log::info('Cache miss: Loading routes with request counts from database');
            
            $routes = $this->getActiveRoutes();
            
            // Calculate all counts in a single efficient query
            $countsByRoute = $this->calculateAllRouteRequestsCounts($routes);
            
            return $routes->map(function ($route) use ($countsByRoute) {
                $route->active_requests_count = $countsByRoute[$route->id] ?? 0;
                return $route;
            });
        });
    }

    /**
     * Get routes for specific country pair with caching
     */
    public function getCountryRoutes(int $fromCountryId, int $toCountryId): Collection
    {
        $cacheKey = self::CACHE_KEY_COUNTRY_ROUTES_PREFIX . "{$fromCountryId}_{$toCountryId}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL_ROUTES, function () use ($fromCountryId, $toCountryId) {
            Log::info('Cache miss: Loading country routes from database', [
                'from_country_id' => $fromCountryId,
                'to_country_id' => $toCountryId
            ]);
            
            return Route::active()
                ->forCountries($fromCountryId, $toCountryId)
                ->with(['fromLocation', 'toLocation'])
                ->byPriority()
                ->get();
        });
    }

    /**
     * Calculate request count for a single route (private method)
     */
    private function calculateRouteRequestsCount(Route $route): int
    {
        $fromIds = $route->from_location_ids;
        $toIds = $route->to_location_ids;

        // Forward direction counts
        $sendForwardCount = SendRequest::whereIn('from_location_id', $fromIds)
            ->whereIn('to_location_id', $toIds)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();

        $deliveryForwardCount = DeliveryRequest::whereIn('from_location_id', $fromIds)
            ->whereIn('to_location_id', $toIds)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();

        // Reverse direction counts
        $sendReverseCount = SendRequest::whereIn('from_location_id', $toIds)
            ->whereIn('to_location_id', $fromIds)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();

        $deliveryReverseCount = DeliveryRequest::whereIn('from_location_id', $toIds)
            ->whereIn('to_location_id', $fromIds)
            ->whereIn('status', ['open', 'has_responses'])
            ->count();

        return $sendForwardCount + $deliveryForwardCount + $sendReverseCount + $deliveryReverseCount;
    }

    /**
     * Calculate all route request counts efficiently using union queries
     */
    private function calculateAllRouteRequestsCounts(Collection $routes): array
    {
        if ($routes->isEmpty()) {
            return [];
        }

        // Build union query for maximum performance (similar to Route model method)
        $unionQueries = [];
        $routeKeys = [];

        foreach ($routes as $route) {
            $fromIds = implode(',', $route->from_location_ids);
            $toIds = implode(',', $route->to_location_ids);
            $routeKey = "route_{$route->id}";
            $routeKeys[$routeKey] = $route->id;

            // Forward direction queries
            $unionQueries[] = "
                SELECT '{$routeKey}' as route_key, COUNT(*) as cnt
                FROM send_requests
                WHERE status IN ('open','has_responses')
                  AND from_location_id IN ({$fromIds})
                  AND to_location_id IN ({$toIds})
            ";

            $unionQueries[] = "
                SELECT '{$routeKey}' as route_key, COUNT(*) as cnt
                FROM delivery_requests
                WHERE status IN ('open','has_responses')
                  AND from_location_id IN ({$fromIds})
                  AND to_location_id IN ({$toIds})
            ";

            // Reverse direction queries
            $unionQueries[] = "
                SELECT '{$routeKey}' as route_key, COUNT(*) as cnt
                FROM send_requests
                WHERE status IN ('open','has_responses')
                  AND from_location_id IN ({$toIds})
                  AND to_location_id IN ({$fromIds})
            ";

            $unionQueries[] = "
                SELECT '{$routeKey}' as route_key, COUNT(*) as cnt
                FROM delivery_requests
                WHERE status IN ('open','has_responses')
                  AND from_location_id IN ({$toIds})
                  AND to_location_id IN ({$fromIds})
            ";
        }

        $counts = DB::select("
            SELECT route_key, SUM(cnt) as total
            FROM (" . implode(' UNION ALL ', $unionQueries) . ") as combined
            GROUP BY route_key
        ");

        $result = [];
        foreach ($counts as $count) {
            $routeId = $routeKeys[$count->route_key] ?? null;
            if ($routeId) {
                $result[$routeId] = (int) $count->total;
            }
        }

        return $result;
    }

    /**
     * Warm up route caches
     */
    public function warmCache(): void
    {
        Log::info('Starting route cache warming');

        try {
            // Warm basic route caches
            $this->getActiveRoutes();
            $this->getPopularRoutes();
            $this->getRoutesWithRequestCounts();

            // Warm country pair routes (sample of popular pairs)
            $routes = $this->getActiveRoutes();
            $countryPairs = $routes->map(function ($route) {
                $fromCountry = $route->fromLocation->type === 'country' 
                    ? $route->fromLocation 
                    : $route->fromLocation->parent;
                $toCountry = $route->toLocation->type === 'country' 
                    ? $route->toLocation 
                    : $route->toLocation->parent;
                
                return [$fromCountry->id, $toCountry->id];
            })->unique()->take(10);

            foreach ($countryPairs as [$fromCountryId, $toCountryId]) {
                $this->getCountryRoutes($fromCountryId, $toCountryId);
            }

            // Warm individual route request counts
            foreach ($routes->take(20) as $route) {
                $this->getRouteRequestsCount($route->id);
            }

            Log::info('Route cache warming completed successfully', [
                'routes_count' => $routes->count(),
                'country_pairs_warmed' => $countryPairs->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to warm route cache', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Clear all route caches
     */
    public function clearCache(): void
    {
        Log::info('Clearing all route caches');

        try {
            // Clear basic caches
            Cache::forget(self::CACHE_KEY_ALL_ROUTES);
            Cache::forget(self::CACHE_KEY_ACTIVE_ROUTES);
            Cache::forget(self::CACHE_KEY_POPULAR_ROUTES);
            Cache::forget(self::CACHE_KEY_ACTIVE_REQUESTS_COUNTS);

            // Clear route-specific caches
            $routes = Route::get(['id']);
            foreach ($routes as $route) {
                Cache::forget(self::CACHE_KEY_ROUTE_PREFIX . $route->id);
                Cache::forget(self::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $route->id);
            }

            // Clear country routes caches (approximate cleanup)
            // In production, you might want to use Redis SCAN for this
            $locations = app(LocationCacheService::class)->getCountries();
            foreach ($locations as $fromCountry) {
                foreach ($locations as $toCountry) {
                    if ($fromCountry->id !== $toCountry->id) {
                        Cache::forget(self::CACHE_KEY_COUNTRY_ROUTES_PREFIX . "{$fromCountry->id}_{$toCountry->id}");
                    }
                }
            }

            Log::info('Route cache cleared successfully');

        } catch (\Exception $e) {
            Log::error('Failed to clear route cache', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Invalidate cache when routes or requests change
     */
    public function invalidateRouteCache(?int $routeId = null): void
    {
        Log::info('Invalidating route cache', ['route_id' => $routeId]);

        try {
            // Clear broad caches that include multiple routes
            Cache::forget(self::CACHE_KEY_ACTIVE_ROUTES);
            Cache::forget(self::CACHE_KEY_POPULAR_ROUTES);
            Cache::forget(self::CACHE_KEY_ACTIVE_REQUESTS_COUNTS);

            if ($routeId) {
                // Clear specific route caches
                Cache::forget(self::CACHE_KEY_ROUTE_PREFIX . $routeId);
                Cache::forget(self::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $routeId);

                // Clear country routes that might include this route
                $route = Route::with(['fromLocation.parent', 'toLocation.parent'])->find($routeId);
                if ($route) {
                    $fromCountry = $route->fromLocation->type === 'country' 
                        ? $route->fromLocation 
                        : $route->fromLocation->parent;
                    $toCountry = $route->toLocation->type === 'country' 
                        ? $route->toLocation 
                        : $route->toLocation->parent;
                    
                    if ($fromCountry && $toCountry) {
                        Cache::forget(self::CACHE_KEY_COUNTRY_ROUTES_PREFIX . "{$fromCountry->id}_{$toCountry->id}");
                        Cache::forget(self::CACHE_KEY_COUNTRY_ROUTES_PREFIX . "{$toCountry->id}_{$fromCountry->id}");
                    }
                }
            }

            Log::info('Route cache invalidated successfully', ['route_id' => $routeId]);

        } catch (\Exception $e) {
            Log::error('Failed to invalidate route cache', [
                'route_id' => $routeId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidate request counts cache (called when requests are created/updated)
     */
    public function invalidateRequestCountsCache(): void
    {
        Log::info('Invalidating route request counts cache');

        try {
            // Clear all count-related caches
            Cache::forget(self::CACHE_KEY_POPULAR_ROUTES);
            Cache::forget(self::CACHE_KEY_ACTIVE_REQUESTS_COUNTS);

            // Clear individual route count caches
            $routes = Route::get(['id']);
            foreach ($routes as $route) {
                Cache::forget(self::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $route->id);
            }

            Log::info('Route request counts cache invalidated successfully');

        } catch (\Exception $e) {
            Log::error('Failed to invalidate route request counts cache', [
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
            'active_routes' => Cache::has(self::CACHE_KEY_ACTIVE_ROUTES),
            'popular_routes' => Cache::has(self::CACHE_KEY_POPULAR_ROUTES),
            'active_requests_counts' => Cache::has(self::CACHE_KEY_ACTIVE_REQUESTS_COUNTS),
            'individual_route_counts' => 0,
            'country_routes' => 0
        ];

        // Check individual route count caches (sample)
        $routes = Route::limit(10)->get(['id']);
        foreach ($routes as $route) {
            $key = self::CACHE_KEY_ROUTE_REQUESTS_COUNT_PREFIX . $route->id;
            if (Cache::has($key)) {
                $stats['individual_route_counts']++;
            }
        }

        // Check some country routes caches (sample)
        $countries = app(LocationCacheService::class)->getCountries()->take(3);
        foreach ($countries as $fromCountry) {
            foreach ($countries as $toCountry) {
                if ($fromCountry->id !== $toCountry->id) {
                    $key = self::CACHE_KEY_COUNTRY_ROUTES_PREFIX . "{$fromCountry->id}_{$toCountry->id}";
                    if (Cache::has($key)) {
                        $stats['country_routes']++;
                    }
                }
            }
        }

        return $stats;
    }
}