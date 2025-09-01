<?php

namespace App\Services;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Services\Matching\CapacityAwareMatchingService;
use App\Services\Matching\RequestMatchingService;
use App\Services\Matching\ResponseCreationService;
use App\Services\Matching\ResponseStatusService;
use Illuminate\Support\Facades\Log;

class Matcher
{
    public function __construct(
        protected NotificationService $notificationService,
        protected RequestMatchingService $matchingService,
        protected CapacityAwareMatchingService $capacityMatchingService,
        protected ResponseCreationService $creationService,
        protected ResponseStatusService $statusService
    ) {}

    public function matchSendRequest(SendRequest $sendRequest): void
    {
        // Use capacity-aware matching to find only available deliverers
        $matchedDeliveries = $this->capacityMatchingService->findMatchingDeliveryRequestsWithCapacity($sendRequest);

        foreach ($matchedDeliveries as $delivery) {
            $this->creationService->createMatchingResponse(
                $delivery->user_id,        // deliverer receives the match
                $sendRequest->user_id,     // sender offered the match
                'send',                    // type of offer
                $delivery->id,             // deliverer's request ID
                $sendRequest->id          // sender's request ID
            );

            $this->notifyDeliveryUserAboutNewSend($sendRequest, $delivery);
        }

        Log::info('Capacity-aware send request matching completed', [
            'send_request_id' => $sendRequest->id,
            'matches_found' => $matchedDeliveries->count()
        ]);
    }

    public function matchDeliveryRequest(DeliveryRequest $deliveryRequest): void
    {
        // Use capacity-aware matching to respect deliverer's capacity limits
        $matchedSends = $this->capacityMatchingService->findMatchingSendRequestsWithCapacity($deliveryRequest);

        foreach ($matchedSends as $send) {
            $this->creationService->createMatchingResponse(
                $deliveryRequest->user_id, // deliverer receives the match notification (always deliverer first!)
                $send->user_id,            // sender offered the match (they created the send request)
                'send',                    // type of offer (send request is being offered to deliverer)
                $deliveryRequest->id,      // deliverer's request ID (receiving request)
                $send->id                  // sender's request ID (offered request)
            );

            $this->notifyDeliveryUserAboutNewSend($send, $deliveryRequest);
        }

        Log::info('Capacity-aware delivery request matching completed', [
            'delivery_request_id' => $deliveryRequest->id,
            'matches_found' => $matchedSends->count()
        ]);
    }

    public function handleUserResponse(int $responseId, int $userId, string $action): bool
    {
        $response = Response::find($responseId);

        if (!$response) {
            Log::warning('Response not found', ['response_id' => $responseId]);
            return false;
        }

        $status = $action === 'accept' ? 'accepted' : 'rejected';
        $updated = $this->statusService->updateUserStatus($response, $userId, $status);

        if (!$updated) {
            Log::warning('Failed to update user status', [
                'response_id' => $responseId,
                'user_id' => $userId,
                'action' => $action
            ]);
            return false;
        }

        Log::info('User response handled successfully', [
            'response_id' => $responseId,
            'user_id' => $userId,
            'action' => $action,
            'overall_status' => $response->fresh()->overall_status
        ]);

        return true;
    }

    private function notifyDeliveryUserAboutNewSend(SendRequest $sendRequest, DeliveryRequest $delivery): void
    {
        $user = $delivery->user;
        if (!$user || !$user->telegramUser) {
            Log::warning("No telegram_id for user of delivery ID {$delivery->id}");
            return;
        }

        $this->notificationService->sendResponseNotification($user->id);
    }

    private function notifyDeliveryUserAboutExistingSend(SendRequest $sendRequest, DeliveryRequest $delivery): void
    {
        $user = $delivery->user;
        if (!$user || !$user->telegramUser) {
            Log::warning("No telegram_id for user of delivery ID {$delivery->id}");
            return;
        }

        $this->notificationService->sendResponseNotification($user->id);
    }
}
