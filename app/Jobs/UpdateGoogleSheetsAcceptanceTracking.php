<?php

namespace App\Jobs;

use App\Models\Response;
use App\Services\GoogleSheetsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateGoogleSheetsAcceptanceTracking implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120;

    public function __construct(
        private readonly int $responseId,
        private readonly ?string $acceptanceType = null
    ) {}

    public function handle(GoogleSheetsService $googleSheetsService): void
    {
        try {
            $response = Response::find($this->responseId);

            if (! $response) {
                Log::warning('Response not found for Google Sheets acceptance tracking', [
                    'response_id' => $this->responseId,
                ]);
                return;
            }

            Log::info('Processing acceptance tracking for response (new system)', [
                'response_id' => $response->id,
                'response_type' => $response->response_type,
                'deliverer_status' => $response->deliverer_status,
                'sender_status' => $response->sender_status,
                'overall_status' => $response->overall_status,
                'offer_type' => $response->offer_type
            ]);

            // NEW SYSTEM: Use individual status columns for proper sequential tracking
            if ($response->deliverer_status || $response->sender_status) {
                $this->handleNewSystemTracking($response, $googleSheetsService, $this->acceptanceType);
            }

        } catch (\Exception $e) {
            Log::error('Failed to update acceptance tracking via job', [
                'response_id' => $this->responseId,
                'error' => $e->getMessage(),
            ]);

            // Re-throw to mark job as failed and potentially retry
            throw $e;
        }
    }

    /**
     * Handle tracking using the new single response system with dual acceptance
     */
    private function handleNewSystemTracking(Response $response, GoogleSheetsService $googleSheetsService, ?string $acceptanceType = null): void
    {
        // In the new system, we track acceptances sequentially:
        // 1. Deliverer accepts first → update deliverer's request
        // 2. Sender accepts second → update sender's request

        $userRole = null;
        $shouldUpdate = false;

        // Use acceptance type from observer if provided (more accurate)
        if ($acceptanceType) {
            if ($acceptanceType === 'manual') {
                // For manual responses, handle directly without user role logic
                Log::info('Processing manual response acceptance', [
                    'response_id' => $response->id
                ]);
                $this->updateSingleRequestForManualResponse($response, $googleSheetsService);
                return;
            }
            $userRole = $acceptanceType;
            $shouldUpdate = true;
            Log::info('Using provided acceptance type', [
                'response_id' => $response->id,
                'acceptance_type' => $acceptanceType
            ]);
        } else {
            // Fallback: determine which user just accepted based on status change
            if ($response->deliverer_status === 'accepted' && $response->sender_status !== 'accepted') {
                $userRole = 'deliverer';
                $shouldUpdate = true;
            } elseif ($response->sender_status === 'accepted' && $response->deliverer_status !== 'accepted') {
                $userRole = 'sender';
                $shouldUpdate = true;
            } elseif ($response->deliverer_status === 'accepted' && $response->sender_status === 'accepted') {
                // Both are accepted, this should only happen if acceptanceType wasn't provided
                // Default to sender (last to accept) for backwards compatibility
                $userRole = 'sender';
                $shouldUpdate = true;
                Log::warning('Both users accepted but no acceptanceType provided, defaulting to sender', [
                    'response_id' => $response->id
                ]);
            }
        }

        if (!$shouldUpdate) {
            Log::info('No acceptance to track in new system', [
                'response_id' => $response->id,
                'deliverer_status' => $response->deliverer_status,
                'sender_status' => $response->sender_status,
                'acceptance_type' => $acceptanceType
            ]);
            return;
        }

        Log::info('Tracking acceptance for role in new system', [
            'response_id' => $response->id,
            'user_role' => $userRole,
            'overall_status' => $response->overall_status,
            'offer_type' => $response->offer_type,
            'request_id' => $response->request_id,
            'offer_id' => $response->offer_id,
            'response_type' => $response->response_type
        ]);

        // Handle manual vs matching responses separately
        if ($response->response_type === Response::TYPE_MANUAL) {
            // For manual responses, only track final acceptance (single acceptance system)
            if ($response->overall_status === 'accepted') {
                $this->updateSingleRequestForManualResponse($response, $googleSheetsService);
            }
        } else {
            // For matching responses, use dual acceptance tracking
            // Update the appropriate request based on user role for timing tracking:
            // - When deliverer accepts → update deliverer's own delivery request (for timing)
            // - When sender accepts → update sender's own send request (for timing)
            if ($userRole === 'deliverer') {
                // Deliverer accepted → update as "received" (first step)
                $this->updateDelivererRequestInNewSystem($response, $googleSheetsService, $response->created_at->toISOString(), 'received');
            } elseif ($userRole === 'sender') {
                // Sender accepted → update as "accepted" (final step)
                $delivererAcceptanceTime = $this->getDelivererAcceptanceTime($response);
                $this->updateSenderRequestInNewSystem($response, $googleSheetsService, $delivererAcceptanceTime, 'accepted');
            }
        }


    }

    /**
     * Get the time when deliverer accepted (for sender's waiting time calculation)
     */
    private function getDelivererAcceptanceTime(Response $response): string
    {
        // For matching responses in the new system, the timing logic is:
        // 1. Response created = deliverer got match notification
        // 2. Deliverer accepts = response status changes to 'partial'
        // 3. Sender gets notified about deliverer acceptance
        // 4. Sender accepts = response status changes to 'accepted'

        // Since we don't track individual acceptance timestamps, use logical approximations:
        // For sender's waiting time calculation, they get notified when deliverer accepts
        // Use response creation time as the best available proxy for when deliverer accepted
        // (In matching system, deliverer acceptance happens quickly after creation)

        return $response->created_at->toISOString();
    }

    /**
     * Update deliverer's request in new system
     */
    private function updateDelivererRequestInNewSystem(Response $response, GoogleSheetsService $googleSheetsService, string $responseReceivedTime = null, string $updateType = 'accepted'): void
    {
        // Find deliverer's delivery request using getUserRole logic
        $delivererUser = $response->getDelivererUser();
        if (!$delivererUser) {
            Log::warning('Deliverer user not found for response', [
                'response_id' => $response->id
            ]);
            return;
        }

        // For matching responses, deliverer is always user_id (receives notification)
        // Find the delivery request owned by the deliverer
        $deliveryRequest = null;

        if ($response->offer_type === 'send') {
            // Send request offered to deliverer, find deliverer's delivery request
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->request_id);
        } elseif ($response->offer_type === 'delivery') {
            // Delivery request offered by deliverer
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->offer_id);
        }

        // Verify this delivery request actually belongs to the deliverer
        if ($deliveryRequest && $deliveryRequest->user_id === $delivererUser->id) {
            if ($updateType === 'received') {
                $googleSheetsService->updateRequestResponseReceived('delivery', $deliveryRequest->id, true);
            } else {
                $googleSheetsService->updateRequestResponseAccepted('delivery', $deliveryRequest->id, $responseReceivedTime ?: now()->toISOString());
            }
            Log::info('Updated deliverer request in Google Sheets (new system)', [
                'response_id' => $response->id,
                'delivery_request_id' => $deliveryRequest->id,
                'deliverer_user_id' => $delivererUser->id,
                'update_type' => $updateType,
            ]);
        } else {
            Log::warning('Deliverer\'s delivery request not found or ownership mismatch (new system)', [
                'response_id' => $response->id,
                'deliverer_user_id' => $delivererUser->id,
                'delivery_request_user_id' => $deliveryRequest?->user_id,
                'delivery_request_id' => $deliveryRequest?->id,
                'offer_type' => $response->offer_type,
                'request_id' => $response->request_id,
                'offer_id' => $response->offer_id
            ]);
        }
    }

    /**
     * Update sender's request in new system
     */
    private function updateSenderRequestInNewSystem(Response $response, GoogleSheetsService $googleSheetsService, string $responseReceivedTime = null, string $updateType = 'accepted'): void
    {
        // Find sender's send request using getUserRole logic
        $senderUser = $response->getSenderUser();
        if (!$senderUser) {
            Log::warning('Sender user not found for response', [
                'response_id' => $response->id
            ]);
            return;
        }

        // For matching responses, sender is always responder_id (owns the send request)
        // Find the send request owned by the sender
        $sendRequest = null;

        if ($response->offer_type === 'send') {
            // Send request offered by sender
            $sendRequest = \App\Models\SendRequest::find($response->offer_id);
        } elseif ($response->offer_type === 'delivery') {
            // Delivery request offered, sender receives the offer
            $sendRequest = \App\Models\SendRequest::find($response->request_id);
        }

        // Verify this send request actually belongs to the sender
        if ($sendRequest && $sendRequest->user_id === $senderUser->id) {
            if ($updateType === 'received') {
                $googleSheetsService->updateRequestResponseReceived('send', $sendRequest->id, true);
            } else {
                $googleSheetsService->updateRequestResponseAccepted('send', $sendRequest->id, $responseReceivedTime ?: now()->toISOString());
            }
            Log::info('Updated sender request in Google Sheets (new system)', [
                'response_id' => $response->id,
                'send_request_id' => $sendRequest->id,
                'sender_user_id' => $senderUser->id,
                'update_type' => $updateType,
            ]);
        } else {
            Log::warning('Sender\'s send request not found or ownership mismatch (new system)', [
                'response_id' => $response->id,
                'sender_user_id' => $senderUser->id,
                'send_request_user_id' => $sendRequest?->user_id,
                'send_request_id' => $sendRequest?->id,
                'offer_type' => $response->offer_type,
                'request_id' => $response->request_id,
                'offer_id' => $response->offer_id
            ]);
        }
    }

    /**
     * Handle legacy system tracking (old dual response system)
     */
    private function handleLegacySystemTracking(Response $response, GoogleSheetsService $googleSheetsService): void
    {
        // For matching responses, behavior depends on response status
        if ($response->response_type === Response::TYPE_MATCHING) {
            if ($response->overall_status === Response::OVERALL_STATUS_PARTIAL) {
                // Deliverer responded - only update the deliverer's request
                $this->updateDelivererRequestOnly($response, $googleSheetsService);
            } elseif ($response->overall_status === Response::OVERALL_STATUS_ACCEPTED) {
                // Final acceptance by sender - only update the sender's request
                // (deliverer was already updated in the previous step)
                $this->updateSenderRequestOnly($response, $googleSheetsService);
            } else {
                // Other statuses - handle manually or log
                Log::warning('Unexpected response status for acceptance tracking (legacy)', [
                    'response_id' => $response->id,
                    'overall_status' => $response->overall_status
                ]);
            }
        } else {
            // For manual responses, update only the target request
            $this->updateSingleRequestForManualResponse($response, $googleSheetsService);
        }
    }

    /**
     * Update only the deliverer's request when they respond (not final acceptance yet) - LEGACY
     */
    private function updateDelivererRequestOnly(Response $response, $googleSheetsService): void
    {
        // When deliverer responds, only update their delivery request
        // The send request should only be updated when sender finally accepts

        if ($response->offer_type === 'send') {
            // SendRequest is being offered, DeliveryRequest received the offer
            // The deliverer (owner of delivery request) is responding
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->request_id);
            if ($deliveryRequest) {
                $googleSheetsService->updateRequestResponseAccepted('delivery', $deliveryRequest->id, $response->created_at->toISOString());
                Log::info('Deliverer request updated in Google Sheets (deliverer responded)', [
                    'response_id' => $response->id,
                    'delivery_request_id' => $deliveryRequest->id,
                ]);
            } else {
                Log::warning('DeliveryRequest not found for deliverer response tracking', [
                    'response_id' => $response->id,
                    'expected_delivery_request_id' => $response->request_id
                ]);
            }
        } else {
            // DeliveryRequest is being offered, SendRequest received the offer
            // The deliverer (owner of delivery request) is responding
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->offer_id);
            if ($deliveryRequest) {
                $googleSheetsService->updateRequestResponseAccepted('delivery', $deliveryRequest->id, $response->created_at->toISOString());
                Log::info('Deliverer request updated in Google Sheets (deliverer responded)', [
                    'response_id' => $response->id,
                    'delivery_request_id' => $deliveryRequest->id,
                ]);
            } else {
                Log::warning('DeliveryRequest not found for deliverer response tracking', [
                    'response_id' => $response->id,
                    'expected_delivery_request_id' => $response->offer_id
                ]);
            }
        }
    }

    /**
     * Update only the sender's request when they finally accept the deliverer's response
     * @throws \Exception
     */
    private function updateSenderRequestOnly(Response $response, GoogleSheetsService $googleSheetsService): void
    {
        // When sender finally accepts, only update their send request
        // The delivery request was already updated when deliverer responded

        if ($response->offer_type === 'send') {
            // SendRequest is being offered, DeliveryRequest received the offer
            // The sender (owner of send request) is now accepting
            $sendRequest = \App\Models\SendRequest::find($response->offer_id);
            if ($sendRequest) {
                $googleSheetsService->updateRequestResponseAccepted('send', $sendRequest->id, $response->created_at->toISOString());
                Log::info('Sender request updated in Google Sheets (sender accepted)', [
                    'response_id' => $response->id,
                    'send_request_id' => $sendRequest->id,
                ]);
            } else {
                Log::warning('SendRequest not found for sender acceptance tracking', [
                    'response_id' => $response->id,
                    'expected_send_request_id' => $response->offer_id
                ]);
            }
        } else {
            // DeliveryRequest is being offered, SendRequest received the offer
            // The sender (owner of send request) is now accepting
            $sendRequest = \App\Models\SendRequest::find($response->request_id);
            if ($sendRequest) {
                $googleSheetsService->updateRequestResponseAccepted('send', $sendRequest->id, $response->created_at->toISOString());
                Log::info('Sender request updated in Google Sheets (sender accepted)', [
                    'response_id' => $response->id,
                    'send_request_id' => $sendRequest->id,
                ]);
            } else {
                Log::warning('SendRequest not found for sender acceptance tracking', [
                    'response_id' => $response->id,
                    'expected_send_request_id' => $response->request_id
                ]);
            }
        }
    }

    /**
     * Update single request for manual responses
     * @throws \Exception
     */
    private function updateSingleRequestForManualResponse(Response $response, GoogleSheetsService $googleSheetsService): void
    {
        $targetRequest = $googleSheetsService->getTargetRequest($response);

        if (!$targetRequest) {
            Log::warning('Could not determine target request for acceptance tracking', [
                'response_id' => $response->id,
            ]);

            return;
        }

        $requestType = ($targetRequest instanceof \App\Models\SendRequest) ? 'send' : 'delivery';
        $responseReceivedTime = $response->created_at->toISOString();
        $googleSheetsService->updateRequestResponseAccepted($requestType, $targetRequest->id, $responseReceivedTime);

//        Log::info('Manual response acceptance tracking updated via job', [
//            'response_id' => $response->id,
//            'target_request_id' => $targetRequest->id,
//            'request_type' => $requestType,
//        ]);
    }

}
