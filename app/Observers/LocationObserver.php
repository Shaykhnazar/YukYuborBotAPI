<?php

namespace App\Observers;

use App\Models\Location;
use App\Services\LocationCacheService;
use Illuminate\Support\Facades\Log;

class LocationObserver
{
    public function __construct(
        private LocationCacheService $locationCacheService
    ) {}

    /**
     * Handle the Location "created" event.
     */
    public function created(Location $location): void
    {
        Log::info('LocationObserver: Location created', [
            'location_id' => $location->id,
            'name' => $location->name,
            'type' => $location->type
        ]);

        $this->invalidateCache($location);
    }

    /**
     * Handle the Location "updated" event.
     */
    public function updated(Location $location): void
    {
        Log::info('LocationObserver: Location updated', [
            'location_id' => $location->id,
            'name' => $location->name,
            'type' => $location->type,
            'changes' => $location->getChanges()
        ]);

        $this->invalidateCache($location);
    }

    /**
     * Handle the Location "deleted" event.
     */
    public function deleted(Location $location): void
    {
        Log::info('LocationObserver: Location deleted', [
            'location_id' => $location->id,
            'name' => $location->name,
            'type' => $location->type
        ]);

        $this->invalidateCache($location);
    }

    /**
     * Handle the Location "restored" event.
     */
    public function restored(Location $location): void
    {
        Log::info('LocationObserver: Location restored', [
            'location_id' => $location->id,
            'name' => $location->name,
            'type' => $location->type
        ]);

        $this->invalidateCache($location);
    }

    /**
     * Handle the Location "force deleted" event.
     */
    public function forceDeleted(Location $location): void
    {
        Log::info('LocationObserver: Location force deleted', [
            'location_id' => $location->id,
            'name' => $location->name,
            'type' => $location->type
        ]);

        $this->invalidateCache($location);
    }

    /**
     * Invalidate relevant caches when location changes
     */
    private function invalidateCache(Location $location): void
    {
        try {
            $this->locationCacheService->invalidateLocation($location->id);
            
            Log::info('LocationObserver: Cache invalidated successfully', [
                'location_id' => $location->id
            ]);
        } catch (\Exception $e) {
            Log::error('LocationObserver: Failed to invalidate cache', [
                'location_id' => $location->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}