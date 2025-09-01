<?php

namespace App\Services\Matching;

use App\Enums\DualStatus;
use App\Enums\ResponseStatus;
use App\Models\Response;
use App\Models\SendRequest;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class ResponseRebalancingService
{
    public function __construct(
        private CapacityAwareMatchingService $capacityMatchingService,
        private ResponseCreationService $creationService,
        private NotificationService $notificationService
    ) {}

    /**
     * Rebalance responses after a deliverer accepts a partnership
     */
    public function rebalanceAfterAcceptance(Response $acceptedResponse): void
    {
        // Only rebalance for matching responses where deliverer accepted
        if ($acceptedResponse->response_type !== Response::TYPE_MATCHING) {
            return;
        }

        $delivererRole = $acceptedResponse->getUserRole($acceptedResponse->user_id);
        if ($delivererRole !== 'deliverer') {
            return;
        }

        $busyDelivererId = $acceptedResponse->user_id;

        Log::info('Starting rebalancing after deliverer acceptance', [
            'response_id' => $acceptedResponse->id,
            'deliverer_id' => $busyDelivererId,
            'accepted_response_status' => $acceptedResponse->overall_status
        ]);

        // Get deliverer's current capacity info
        $capacityInfo = $this->capacityMatchingService->getDelivererCapacityInfo($busyDelivererId);

        // If deliverer is over capacity, redistribute excess responses
        if ($capacityInfo['current_load'] > $capacityInfo['max_capacity']) {
            $this->redistributeExcessResponses($busyDelivererId, $capacityInfo);
        }

        Log::info('Rebalancing completed', [
            'deliverer_id' => $busyDelivererId,
            'capacity_info' => $capacityInfo
        ]);
    }

    /**
     * Redistribute excess responses from an over-capacity deliverer
     */
    private function redistributeExcessResponses(int $busyDelivererId, array $capacityInfo): void
    {
        // Get all pending responses for this deliverer (excluding partial/accepted ones)
        $pendingResponses = Response::where('user_id', $busyDelivererId)
            ->where('overall_status', ResponseStatus::PENDING->value)
            ->orderBy('created_at', 'asc') // Keep oldest responses, redistribute newest
            ->get();

        $excessCount = $capacityInfo['current_load'] - $capacityInfo['max_capacity'];
        $responsesToRedistribute = $pendingResponses->take($excessCount);

        Log::info('Redistributing excess responses', [
            'deliverer_id' => $busyDelivererId,
            'total_pending' => $pendingResponses->count(),
            'excess_count' => $excessCount,
            'responses_to_redistribute' => $responsesToRedistribute->pluck('id')->toArray()
        ]);

        foreach ($responsesToRedistribute as $response) {
            $this->redistributeResponse($response);
        }
    }

    /**
     * Redistribute a single response to an alternative deliverer
     */
    private function redistributeResponse(Response $response): void
    {
        // Get the send request for this response
        $sendRequest = $this->getSendRequestFromResponse($response);
        if (!$sendRequest) {
            Log::warning('Cannot redistribute response: send request not found', [
                'response_id' => $response->id
            ]);
            $this->autoRejectResponse($response, 'Send request not found');
            return;
        }

        // Find alternative deliverers with capacity
        $alternativeDeliverers = $this->capacityMatchingService->findAlternativeDeliverers(
            $sendRequest, 
            $response->user_id
        );

        if ($alternativeDeliverers->isNotEmpty()) {
            $newDeliverer = $alternativeDeliverers->first();
            $this->transferResponse($response, $newDeliverer, $sendRequest);
        } else {
            // No alternatives available, auto-reject response
            $this->autoRejectResponse($response, 'No alternative deliverers available');
        }
    }

    /**
     * Transfer a response to a new deliverer
     */
    private function transferResponse(Response $oldResponse, $newDeliverer, SendRequest $sendRequest): void
    {
        // Create new response for the alternative deliverer
        $newResponse = $this->creationService->createMatchingResponse(
            $newDeliverer->user_id,        // new deliverer receives the match
            $oldResponse->responder_id,    // same sender
            'send',                        // same offer type
            $newDeliverer->id,             // new deliverer's request ID
            $sendRequest->id               // same send request ID
        );

        // Auto-reject the old response
        $this->autoRejectResponse($oldResponse, 'Redistributed to alternative deliverer');

        // Notify the new deliverer
        $this->notificationService->sendResponseNotification($newDeliverer->user_id);

        Log::info('Response transferred successfully', [
            'old_response_id' => $oldResponse->id,
            'new_response_id' => $newResponse->id,
            'old_deliverer_id' => $oldResponse->user_id,
            'new_deliverer_id' => $newDeliverer->user_id,
            'send_request_id' => $sendRequest->id
        ]);
    }

    /**
     * Auto-reject a response with reason
     */
    private function autoRejectResponse(Response $response, string $reason): void
    {
        $response->update([
            'deliverer_status' => DualStatus::REJECTED->value,
            'overall_status' => ResponseStatus::REJECTED->value,
            'message' => "Auto-rejected: {$reason}"
        ]);

        Log::info('Response auto-rejected during rebalancing', [
            'response_id' => $response->id,
            'deliverer_id' => $response->user_id,
            'sender_id' => $response->responder_id,
            'reason' => $reason
        ]);

        // Optionally notify the sender about the rejection
        $this->notificationService->sendResponseNotification($response->responder_id);
    }

    /**
     * Get send request from response based on offer type
     */
    private function getSendRequestFromResponse(Response $response): ?SendRequest
    {
        if ($response->offer_type === 'send') {
            return SendRequest::find($response->offer_id);
        } else {
            return SendRequest::find($response->request_id);
        }
    }

    /**
     * Check if deliverer is over capacity and needs rebalancing
     */
    public function isDelivererOverCapacity(int $delivererId): bool
    {
        $capacityInfo = $this->capacityMatchingService->getDelivererCapacityInfo($delivererId);
        return $capacityInfo['is_at_capacity'];
    }

    /**
     * Get system-wide capacity statistics
     */
    public function getSystemCapacityStats(): array
    {
        $allActiveResponses = Response::whereIn('overall_status', ['pending', 'partial'])->get();
        
        $delivererStats = $allActiveResponses->groupBy('user_id')->map(function($responses, $delivererId) {
            return $this->capacityMatchingService->getDelivererCapacityInfo($delivererId);
        });

        $overCapacityCount = $delivererStats->where('is_at_capacity', true)->count();
        $totalDeliverers = $delivererStats->count();
        $totalActiveResponses = $allActiveResponses->count();

        return [
            'total_deliverers_with_responses' => $totalDeliverers,
            'deliverers_over_capacity' => $overCapacityCount,
            'total_active_responses' => $totalActiveResponses,
            'capacity_utilization_rate' => $totalDeliverers > 0 
                ? round($totalActiveResponses / ($totalDeliverers * config('capacity.max_deliverer_capacity', 3)) * 100, 2) 
                : 0,
            'deliverer_details' => $delivererStats->values()
        ];
    }
}