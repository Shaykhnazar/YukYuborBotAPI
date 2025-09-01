<?php

namespace App\Services\Matching;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class RoundRobinDistributionService
{
    private const CACHE_KEY = 'round_robin_deliverer_index';
    private const CACHE_TTL = 86400; // 24 hours

    /**
     * Get the next deliverer using round-robin strategy
     */
    public function getNextDeliverer(Collection|EloquentCollection $availableDeliverers): ?object
    {
        if ($availableDeliverers->isEmpty()) {
            return null;
        }

        // Get current round-robin index
        $currentIndex = $this->getCurrentIndex();
        
        // Convert collection to array for easier indexing
        $deliverersList = $availableDeliverers->values();
        $totalDeliverers = $deliverersList->count();

        // Calculate next deliverer index
        $nextDeliverer = $deliverersList->get($currentIndex % $totalDeliverers);
        
        // Increment index for next time
        $this->incrementIndex();

        Log::info('Round-robin deliverer selected', [
            'current_index' => $currentIndex,
            'total_deliverers' => $totalDeliverers,
            'selected_deliverer_id' => $nextDeliverer->user_id,
            'deliverer_ids' => $deliverersList->pluck('user_id')->toArray()
        ]);

        return $nextDeliverer;
    }

    /**
     * Get current round-robin index from cache
     */
    private function getCurrentIndex(): int
    {
        return Cache::get(self::CACHE_KEY, 0);
    }

    /**
     * Increment the round-robin index
     */
    private function incrementIndex(): void
    {
        $currentIndex = $this->getCurrentIndex();
        $newIndex = $currentIndex + 1;
        
        Cache::put(self::CACHE_KEY, $newIndex, self::CACHE_TTL);

        Log::debug('Round-robin index incremented', [
            'old_index' => $currentIndex,
            'new_index' => $newIndex
        ]);
    }

    /**
     * Reset the round-robin index (useful for testing or maintenance)
     */
    public function resetIndex(): void
    {
        Cache::forget(self::CACHE_KEY);
        Log::info('Round-robin index reset');
    }

    /**
     * Get current round-robin state for debugging
     */
    public function getDistributionState(): array
    {
        return [
            'current_index' => $this->getCurrentIndex(),
            'cache_key' => self::CACHE_KEY,
            'cache_ttl' => self::CACHE_TTL
        ];
    }

    /**
     * Distribute multiple requests using round-robin
     * Returns array of [deliverer_id => request_ids]
     */
    public function distributeRequests(Collection|EloquentCollection $availableDeliverers, array $requestIds): array
    {
        if ($availableDeliverers->isEmpty() || empty($requestIds)) {
            return [];
        }

        $distribution = [];
        
        foreach ($requestIds as $requestId) {
            $deliverer = $this->getNextDeliverer($availableDeliverers);
            if ($deliverer) {
                if (!isset($distribution[$deliverer->user_id])) {
                    $distribution[$deliverer->user_id] = [];
                }
                $distribution[$deliverer->user_id][] = $requestId;
            }
        }

        Log::info('Round-robin batch distribution completed', [
            'total_requests' => count($requestIds),
            'total_deliverers' => $availableDeliverers->count(),
            'distribution' => array_map('count', $distribution)
        ]);

        return $distribution;
    }
}