<?php

namespace App\Services\Matching;

use App\Models\Response;
use App\Models\SendRequest;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class RedistributionService
{
    public function __construct(
        private CapacityAwareMatchingService $capacityService,
        private ResponseCreationService $creationService,
        private NotificationService $notificationService
    ) {}

    /**
     * Handle redistribution when a deliverer declines a request
     */
    public function redistributeOnDecline(Response $declinedResponse): bool
    {
        // Check if redistribution is disabled
        if (!config('capacity.rebalancing.enabled', true)) {
            Log::info('Redistribution disabled, skipping automatic redistribution', [
                'response_id' => $declinedResponse->id
            ]);
            return false;
        }

        if ($declinedResponse->response_type !== 'matching') {
            Log::info('Skipping redistribution for non-matching response', [
                'response_id' => $declinedResponse->id,
                'response_type' => $declinedResponse->response_type
            ]);
            return false;
        }

        // Get the send request that needs to be redistributed
        $sendRequest = SendRequest::find($declinedResponse->offer_id);
        if (!$sendRequest) {
            Log::warning('Send request not found for redistribution', [
                'response_id' => $declinedResponse->id,
                'send_request_id' => $declinedResponse->offer_id
            ]);
            return false;
        }

        // Find alternative deliverers (excluding the one who declined)
        $alternativeDeliverers = $this->capacityService->findAlternativeDeliverers(
            $sendRequest,
            $declinedResponse->user_id
        );

        if ($alternativeDeliverers->isEmpty()) {
            Log::info('No alternative deliverers available for redistribution', [
                'response_id' => $declinedResponse->id,
                'send_request_id' => $sendRequest->id,
                'declined_by' => $declinedResponse->user_id
            ]);
            return false;
        }

        // Use round-robin to select the next deliverer
        $nextDeliverer = $alternativeDeliverers->first(); // Already sorted by load

        // Create new response for the next deliverer
        $newResponse = $this->creationService->createMatchingResponse(
            $nextDeliverer->user_id,        // new deliverer receives the match
            $sendRequest->user_id,          // sender offered the match
            'send',                         // type of offer
            $nextDeliverer->id,             // new deliverer's request ID
            $sendRequest->id               // sender's request ID
        );

        // Notify the new deliverer
        $this->notificationService->sendResponseNotification($nextDeliverer->user_id);

        Log::info('Request successfully redistributed', [
            'original_response_id' => $declinedResponse->id,
            'new_response_id' => $newResponse->id,
            'send_request_id' => $sendRequest->id,
            'declined_by' => $declinedResponse->user_id,
            'redistributed_to' => $nextDeliverer->user_id,
            'alternative_deliverers_count' => $alternativeDeliverers->count()
        ]);

        return true;
    }

    /**
     * Get redistribution statistics for monitoring
     */
    public function getRedistributionStats(): array
    {
        $declined = Response::where('overall_status', 'rejected')
            ->where('response_type', 'matching')
            ->whereDate('created_at', today())
            ->count();

        $totalResponses = Response::where('response_type', 'matching')
            ->whereDate('created_at', today())
            ->count();

        return [
            'declined_responses_today' => $declined,
            'total_responses_today' => $totalResponses,
            'decline_rate' => $totalResponses > 0 ? round(($declined / $totalResponses) * 100, 2) : 0
        ];
    }

    /**
     * Check if a send request needs redistribution
     */
    public function needsRedistribution(int $sendRequestId): bool
    {
        $activeResponses = Response::where('offer_id', $sendRequestId)
            ->where('response_type', 'matching')
            ->whereIn('overall_status', ['pending', 'partial'])
            ->count();

        return $activeResponses === 0;
    }

    /**
     * Get available deliverers for a send request
     */
    public function getAvailableDeliverersForRequest(SendRequest $sendRequest): array
    {
        $alternatives = $this->capacityService->findAlternativeDeliverers($sendRequest, 0);
        
        return $alternatives->map(function ($delivery) {
            $capacity = $this->capacityService->getDelivererCapacityInfo($delivery->user_id);
            return [
                'deliverer_id' => $delivery->user_id,
                'delivery_request_id' => $delivery->id,
                'current_load' => $capacity['current_load'],
                'available_capacity' => $capacity['available_capacity'],
                'is_available' => !$capacity['is_at_capacity']
            ];
        })->toArray();
    }
}