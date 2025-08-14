<?php

namespace App\Observers;

use App\Models\Route;
use App\Services\RouteCacheService;
use Illuminate\Support\Facades\Log;

class RouteObserver
{
    public function __construct(
        private RouteCacheService $routeCacheService
    ) {}

    /**
     * Handle the Route "created" event.
     */
    public function created(Route $route): void
    {
        Log::info('RouteObserver: Route created', [
            'route_id' => $route->id,
            'from_location_id' => $route->from_location_id,
            'to_location_id' => $route->to_location_id
        ]);

        $this->invalidateCache($route);
    }

    /**
     * Handle the Route "updated" event.
     */
    public function updated(Route $route): void
    {
        Log::info('RouteObserver: Route updated', [
            'route_id' => $route->id,
            'changes' => $route->getChanges()
        ]);

        $this->invalidateCache($route);
    }

    /**
     * Handle the Route "deleted" event.
     */
    public function deleted(Route $route): void
    {
        Log::info('RouteObserver: Route deleted', [
            'route_id' => $route->id,
            'from_location_id' => $route->from_location_id,
            'to_location_id' => $route->to_location_id
        ]);

        $this->invalidateCache($route);
    }

    /**
     * Handle the Route "restored" event.
     */
    public function restored(Route $route): void
    {
        Log::info('RouteObserver: Route restored', [
            'route_id' => $route->id
        ]);

        $this->invalidateCache($route);
    }

    /**
     * Handle the Route "force deleted" event.
     */
    public function forceDeleted(Route $route): void
    {
        Log::info('RouteObserver: Route force deleted', [
            'route_id' => $route->id
        ]);

        $this->invalidateCache($route);
    }

    /**
     * Invalidate relevant caches when route changes
     */
    private function invalidateCache(Route $route): void
    {
        try {
            $this->routeCacheService->invalidateRouteCache($route->id);
            
            Log::info('RouteObserver: Route cache invalidated successfully', [
                'route_id' => $route->id
            ]);
        } catch (\Exception $e) {
            Log::error('RouteObserver: Failed to invalidate route cache', [
                'route_id' => $route->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}