<?php

namespace App\Services\Matching;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

class CapacityAwareMatchingService extends RequestMatchingService
{
    public function __construct(
        SendRequestRepositoryInterface $sendRequestRepository,
        DeliveryRequestRepositoryInterface $deliveryRequestRepository,
        private RoundRobinDistributionService $roundRobinService
    ) {
        parent::__construct($sendRequestRepository, $deliveryRequestRepository);
    }

    private function getMaxCapacity(): int
    {
        return config('capacity.max_deliverer_capacity', 3);
    }

    private function getDistributionStrategy(): string
    {
        return config('capacity.distribution_strategy', 'least_loaded');
    }

    private function isDistributionEnabled(): bool
    {
        return config('capacity.enabled', true);
    }

    /**
     * Find matching delivery requests with capacity awareness
     * Uses configured distribution strategy (round_robin or least_loaded)
     */
    public function findMatchingDeliveryRequestsWithCapacity(SendRequest $sendRequest): Collection
    {
        // If distribution is disabled, fall back to basic matching
        if (!$this->isDistributionEnabled()) {
            Log::info('Distribution system disabled, using basic matching', [
                'send_request_id' => $sendRequest->id
            ]);
            return parent::findMatchingDeliveryRequests($sendRequest);
        }

        // First check if this send request already has an active response
        if ($this->sendRequestHasActiveResponse($sendRequest->id)) {
            Log::info('Send request already has active response, skipping matching', [
                'send_request_id' => $sendRequest->id
            ]);
            return new EloquentCollection();
        }

        // Get basic matches using parent logic
        $matchedDeliveries = parent::findMatchingDeliveryRequests($sendRequest);

        // Filter by capacity
        $availableDeliveries = $matchedDeliveries->filter(function($delivery) {
            $currentLoad = $this->getDelivererActiveResponses($delivery->user_id);
            return $currentLoad < $this->getMaxCapacity();
        });

        if ($availableDeliveries->isEmpty()) {
            Log::info('No available deliverers with capacity', [
                'send_request_id' => $sendRequest->id,
                'total_matches_found' => $matchedDeliveries->count()
            ]);
            return new EloquentCollection();
        }

        // Apply distribution strategy - select ONLY ONE deliverer
        $selectedDelivery = $this->selectDelivererByStrategy($availableDeliveries, $sendRequest);

        Log::info('Capacity-aware delivery matching completed', [
            'send_request_id' => $sendRequest->id,
            'total_matches_found' => $matchedDeliveries->count(),
            'available_with_capacity' => $availableDeliveries->count(),
            'distribution_strategy' => $this->getDistributionStrategy(),
            'selected_deliverer_id' => $selectedDelivery ? $selectedDelivery->user_id : null,
            'deliverer_loads' => $availableDeliveries->mapWithKeys(function($delivery) {
                return [$delivery->user_id => $this->getDelivererActiveResponses($delivery->user_id)];
            })->toArray()
        ]);

        return $selectedDelivery ? new EloquentCollection([$selectedDelivery]) : new EloquentCollection();
    }

    /**
     * Select deliverer based on configured distribution strategy
     */
    private function selectDelivererByStrategy(Collection $availableDeliveries, SendRequest $sendRequest): ?object
    {
        $strategy = $this->getDistributionStrategy();

        switch ($strategy) {
            case 'round_robin':
                return $this->roundRobinService->getNextDeliverer($availableDeliveries);

            case 'least_loaded':
            default:
                // Sort by current load (least loaded first)
                $sortedDeliveries = $availableDeliveries->sortBy(function($delivery) {
                    return $this->getDelivererActiveResponses($delivery->user_id);
                });
                return $sortedDeliveries->first();

            case 'random':
                return $availableDeliveries->random();
        }
    }

    /**
     * Find matching send requests with capacity awareness
     * Returns only deliverers who have capacity for manual responses
     */
    public function findMatchingSendRequestsWithCapacity(DeliveryRequest $deliveryRequest): Collection
    {
        // If distribution is disabled, fall back to basic matching
        if (!$this->isDistributionEnabled()) {
            Log::info('Distribution system disabled, using basic matching', [
                'delivery_request_id' => $deliveryRequest->id
            ]);
            return parent::findMatchingSendRequests($deliveryRequest);
        }

        // Check if this deliverer has capacity for new responses
        $currentLoad = $this->getDelivererActiveResponses($deliveryRequest->user_id);

        if ($currentLoad >= $this->getMaxCapacity()) {
            Log::info('Deliverer at capacity, limiting matches', [
                'delivery_request_id' => $deliveryRequest->id,
                'deliverer_id' => $deliveryRequest->user_id,
                'current_load' => $currentLoad,
                'max_capacity' => $this->getMaxCapacity()
            ]);

            // Return empty collection if deliverer is at capacity
            return new EloquentCollection();
        }

        // Get basic matches using parent logic
        $matchedSends = parent::findMatchingSendRequests($deliveryRequest);

        // Filter out send requests that already have active responses
        $availableSends = $matchedSends->filter(function($sendRequest) {
            return !$this->sendRequestHasActiveResponse($sendRequest->id);
        });

        // Since capacity is 1, only take 1 send request maximum
        $limitedMatches = $availableSends->take($this->getMaxCapacity());

        Log::info('Capacity-aware send matching completed', [
            'delivery_request_id' => $deliveryRequest->id,
            'deliverer_id' => $deliveryRequest->user_id,
            'current_load' => $currentLoad,
            'max_capacity' => $this->getMaxCapacity(),
            'total_matches_found' => $matchedSends->count(),
            'available_sends' => $availableSends->count(),
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

    /**
     * Check if a send request already has an active response
     */
    public function sendRequestHasActiveResponse(int $sendRequestId): bool
    {
        return Response::where('offer_id', $sendRequestId)
            ->where('response_type', 'matching')
            ->whereIn('overall_status', ['pending', 'partial'])
            ->exists();
    }

    /**
     * Get active response count per send request
     */
    public function getSendRequestActiveResponses(int $sendRequestId): int
    {
        return Response::where('offer_id', $sendRequestId)
            ->where('response_type', 'matching')
            ->whereIn('overall_status', ['pending', 'partial'])
            ->count();
    }
}
