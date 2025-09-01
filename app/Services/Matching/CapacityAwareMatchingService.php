<?php

namespace App\Services\Matching;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class CapacityAwareMatchingService extends RequestMatchingService
{
    private function getMaxCapacity(): int
    {
        return config('capacity.max_deliverer_capacity', 3);
    }

    /**
     * Find matching delivery requests with capacity awareness
     * Returns only deliverers who have capacity and prioritizes least loaded ones
     */
    public function findMatchingDeliveryRequestsWithCapacity(SendRequest $sendRequest): Collection
    {
        // Get basic matches using parent logic
        $matchedDeliveries = parent::findMatchingDeliveryRequests($sendRequest);

        // Filter by capacity and sort by current load
        $availableDeliveries = $matchedDeliveries->filter(function($delivery) {
            $currentLoad = $this->getDelivererActiveResponses($delivery->user_id);
            return $currentLoad < $this->getMaxCapacity();
        });

        // Sort by current load (least loaded first) for fair distribution
        $sortedDeliveries = $availableDeliveries->sortBy(function($delivery) {
            return $this->getDelivererActiveResponses($delivery->user_id);
        });

        Log::info('Capacity-aware delivery matching completed', [
            'send_request_id' => $sendRequest->id,
            'total_matches_found' => $matchedDeliveries->count(),
            'available_with_capacity' => $sortedDeliveries->count(),
            'deliverer_loads' => $sortedDeliveries->mapWithKeys(function($delivery) {
                return [$delivery->user_id => $this->getDelivererActiveResponses($delivery->user_id)];
            })->toArray()
        ]);

        // Return only the least loaded deliverer for initial distribution
        return $sortedDeliveries->take(1);
    }

    /**
     * Find matching send requests with capacity awareness
     * Returns only deliverers who have capacity for manual responses
     */
    public function findMatchingSendRequestsWithCapacity(DeliveryRequest $deliveryRequest): Collection
    {
        // Get basic matches using parent logic
        $matchedSends = parent::findMatchingSendRequests($deliveryRequest);

        // Check if this deliverer has capacity for new responses
        $currentLoad = $this->getDelivererActiveResponses($deliveryRequest->user_id);

        if ($currentLoad >= $this->getMaxCapacity()) {
            Log::info('Deliverer at capacity, limiting matches', [
                'delivery_request_id' => $deliveryRequest->id,
                'deliverer_id' => $deliveryRequest->user_id,
                'current_load' => $currentLoad,
                'max_capacity' => $this->getMaxCapacity(),
                'matches_found' => $matchedSends->count(),
                'matches_returned' => 0
            ]);

            // Return empty collection if deliverer is at capacity
            return new Collection();
        }

        // Calculate how many new matches we can handle
        $availableCapacity = $this->getMaxCapacity() - $currentLoad;
        $limitedMatches = $matchedSends->take($availableCapacity);

        Log::info('Capacity-aware send matching completed', [
            'delivery_request_id' => $deliveryRequest->id,
            'deliverer_id' => $deliveryRequest->user_id,
            'current_load' => $currentLoad,
            'available_capacity' => $availableCapacity,
            'total_matches_found' => $matchedSends->count(),
            'matches_returned' => $limitedMatches->count()
        ]);

        return $limitedMatches;
    }

    /**
     * Get count of active responses for a deliverer
     */
    public function getDelivererActiveResponses(int $delivererId): int
    {
        return Response::where('user_id', $delivererId)
            ->whereIn('overall_status', ['pending', 'partial'])
            ->count();
    }

    /**
     * Get detailed deliverer capacity information
     */
    public function getDelivererCapacityInfo(int $delivererId): array
    {
        $activeResponses = Response::where('user_id', $delivererId)
            ->whereIn('overall_status', ['pending', 'partial'])
            ->get();

        $pendingCount = $activeResponses->where('overall_status', 'pending')->count();
        $partialCount = $activeResponses->where('overall_status', 'partial')->count();
        $totalActive = $activeResponses->count();

        return [
            'deliverer_id' => $delivererId,
            'max_capacity' => $this->getMaxCapacity(),
            'current_load' => $totalActive,
            'available_capacity' => $this->getMaxCapacity() - $totalActive,
            'pending_responses' => $pendingCount,
            'partial_responses' => $partialCount,
            'is_at_capacity' => $totalActive >= $this->getMaxCapacity()
        ];
    }

    /**
     * Find alternative deliverers for redistribution
     */
    public function findAlternativeDeliverers(SendRequest $sendRequest, int $excludeDelivererId): Collection
    {
        // Get basic matches using parent logic
        $matchedDeliveries = parent::findMatchingDeliveryRequests($sendRequest);

        // Exclude the current deliverer and filter by capacity
        return $matchedDeliveries
            ->where('user_id', '!=', $excludeDelivererId)
            ->filter(function($delivery) {
                return $this->getDelivererActiveResponses($delivery->user_id) < $this->getMaxCapacity();
            })
            ->sortBy(function($delivery) {
                return $this->getDelivererActiveResponses($delivery->user_id);
            });
    }
}
