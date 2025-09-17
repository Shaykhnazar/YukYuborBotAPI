<?php

namespace App\Observers;

use App\Models\Response;
use App\Jobs\UpdateDeliveryRequestReceived;
use App\Jobs\UpdateDeliveryRequestAccepted;
use App\Jobs\UpdateSendRequestReceived;
use App\Jobs\UpdateSendRequestAccepted;
use App\Services\NotificationService;
use App\Services\Matching\RedistributionService;
use Illuminate\Support\Facades\Log;

class ResponseObserver
{
    public function __construct(
        private NotificationService $notificationService,
        private RedistributionService $redistributionService
    ) {}

    /**
     * Handle the Response "created" event.
     */
    public function created(Response $response): void
    {
        // For matching responses, mark the target request as "received" 
        if ($response->response_type === 'matching') {
            // NEW LOGIC: Use offer_type to determine which request receives the response
            // For matching responses, offer_type is ALWAYS 'send' (send request offered to deliverer)
            if ($response->offer_type === 'send') {
                // Send request is offered to deliverer
                // - request_id = deliverer's delivery request (mark as received)
                UpdateDeliveryRequestReceived::dispatch($response->request_id)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');

                Log::info('ResponseObserver: Dispatched UpdateDeliveryRequestReceived for send->delivery matching', [
                    'response_id' => $response->id,
                    'deliverer_delivery_request_id' => $response->request_id,
                    'offer_type' => $response->offer_type
                ]);
            } else {
                // Delivery request is offered to sender (rare case)
                // - request_id = sender's send request (mark as received)
                UpdateSendRequestReceived::dispatch($response->request_id)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');

                Log::info('ResponseObserver: Dispatched UpdateSendRequestReceived for delivery->send matching', [
                    'response_id' => $response->id,
                    'sender_send_request_id' => $response->request_id,
                    'offer_type' => $response->offer_type
                ]);
            }
        } else {
            // For manual responses, mark the target request as "received"
            if ($response->offer_type === 'send') {
                // Someone responded to a send request
                UpdateSendRequestReceived::dispatch($response->offer_id)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');

                Log::info('ResponseObserver: Dispatched UpdateSendRequestReceived for manual response', [
                    'response_id' => $response->id,
                    'send_request_id' => $response->offer_id
                ]);
            } else {
                // Someone responded to a delivery request
                UpdateDeliveryRequestReceived::dispatch($response->offer_id)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');

                Log::info('ResponseObserver: Dispatched UpdateDeliveryRequestReceived for manual response', [
                    'response_id' => $response->id,
                    'delivery_request_id' => $response->offer_id
                ]);
            }
        }
    }

    /**
     * Handle the Response "updated" event.
     */
    public function updated(Response $response): void
    {
        // Check for changes in the new dual acceptance status columns
        $statusFieldsChanged = $response->wasChanged(['deliverer_status', 'sender_status', 'overall_status']);

        if ($statusFieldsChanged) {
            $previousDelivererStatus = $response->getOriginal('deliverer_status');
            $currentDelivererStatus = $response->deliverer_status;
            $previousSenderStatus = $response->getOriginal('sender_status');
            $currentSenderStatus = $response->sender_status;
            $previousOverallStatus = $response->getOriginal('overall_status');
            $currentOverallStatus = $response->overall_status;

            Log::info('ResponseObserver: Response status changed (simplified ID system)', [
                'response_id' => $response->id,
                'deliverer_status' => "$previousDelivererStatus → $currentDelivererStatus",
                'sender_status' => "$previousSenderStatus → $currentSenderStatus",
                'overall_status' => "$previousOverallStatus → $currentOverallStatus",
                'response_type' => $response->response_type,
                'offer_type' => $response->offer_type
            ]);

            // Handle different response types differently
            if ($response->response_type === 'manual') {
                // For manual responses, only track overall_status change to 'accepted'
                $manualJustAccepted = ($previousOverallStatus === 'pending' && $currentOverallStatus === 'accepted');

                if ($manualJustAccepted) {
                    Log::info('ResponseObserver: Manual response acceptance detected', [
                        'response_id' => $response->id,
                        'overall_status' => $currentOverallStatus,
                        'offer_type' => $response->offer_type,
                        'offer_id' => $response->offer_id
                    ]);

                    // Mark the target request as "accepted"
                    if ($response->offer_type === 'send') {
                        // Request owner accepted a response to their send request
                        UpdateSendRequestAccepted::dispatch($response->offer_id, $response->updated_at->toISOString())
                            ->delay(now()->addSeconds(3))
                            ->onQueue('gsheets');

                        Log::info('ResponseObserver: Dispatched UpdateSendRequestAccepted for manual response', [
                            'response_id' => $response->id,
                            'send_request_id' => $response->offer_id
                        ]);
                    } else {
                        // Request owner accepted a response to their delivery request
                        UpdateDeliveryRequestAccepted::dispatch($response->offer_id, $response->updated_at->toISOString())
                            ->delay(now()->addSeconds(3))
                            ->onQueue('gsheets');

                        Log::info('ResponseObserver: Dispatched UpdateDeliveryRequestAccepted for manual response', [
                            'response_id' => $response->id,
                            'delivery_request_id' => $response->offer_id
                        ]);
                    }
                }
            } else {
                // For matching responses, use focused jobs for each step
                $delivererJustAccepted = ($previousDelivererStatus === 'pending' && $currentDelivererStatus === 'accepted');
                $senderJustAccepted = ($previousSenderStatus === 'pending' && $currentSenderStatus === 'accepted');

                if ($delivererJustAccepted) {
                    // Deliverer accepted → Mark deliverer's request as "accepted" + Mark sender's request as "received"
                    Log::info('ResponseObserver: Deliverer acceptance detected', [
                        'response_id' => $response->id,
                        'offer_type' => $response->offer_type,
                        'request_id' => $response->request_id,
                        'offer_id' => $response->offer_id
                    ]);

                    $acceptedNotificationTime = $this->getAcceptedNotificationTime($response);
                    
                    // NEW LOGIC: Use offer_type to determine which requests to update
                    // For matching responses, offer_type is ALWAYS 'send' (send request offered to deliverer)
                    if ($response->offer_type === 'send') {
                        // Send request is offered to deliverer
                        // - request_id = deliverer's delivery request (mark as accepted)
                        // - offer_id = sender's send request (mark as received)
                        UpdateDeliveryRequestAccepted::dispatch($response->request_id, $acceptedNotificationTime)
                            ->delay(now()->addSeconds(3))
                            ->onQueue('gsheets');

                        UpdateSendRequestReceived::dispatch($response->offer_id)
                            ->delay(now()->addSeconds(4))
                            ->onQueue('gsheets');
                            
                        Log::info('ResponseObserver: Dispatched jobs for send->delivery matching', [
                            'response_id' => $response->id,
                            'deliverer_delivery_request_id' => $response->request_id,
                            'sender_send_request_id' => $response->offer_id
                        ]);
                    } else {
                        // Delivery request is offered to sender (rare case)
                        // - request_id = sender's send request (mark as accepted) 
                        // - offer_id = deliverer's delivery request (mark as received)
                        UpdateSendRequestAccepted::dispatch($response->request_id, $acceptedNotificationTime)
                            ->delay(now()->addSeconds(3))
                            ->onQueue('gsheets');

                        UpdateDeliveryRequestReceived::dispatch($response->offer_id)
                            ->delay(now()->addSeconds(4))
                            ->onQueue('gsheets');
                            
                        Log::info('ResponseObserver: Dispatched jobs for delivery->send matching', [
                            'response_id' => $response->id,
                            'sender_send_request_id' => $response->request_id,
                            'deliverer_delivery_request_id' => $response->offer_id
                        ]);
                    }

                    // Send notification to sender about deliverer acceptance
                    $senderUser = $response->getSenderUser();
                    if ($senderUser) {
                        $this->notificationService->sendResponseNotification($senderUser->id);
                        Log::info('ResponseObserver: Sent notification to sender about deliverer acceptance', [
                            'response_id' => $response->id,
                            'sender_user_id' => $senderUser->id,
                            'deliverer_notification_time' => $acceptedNotificationTime
                        ]);
                    }
                } elseif ($senderJustAccepted) {
                    // Sender accepted → Mark sender's request as "accepted"
                    Log::info('ResponseObserver: Sender acceptance detected', [
                        'response_id' => $response->id,
                        'offer_type' => $response->offer_type,
                        'request_id' => $response->request_id,
                        'offer_id' => $response->offer_id
                    ]);

                    // For matching responses, sender's waiting time should be calculated from when they received notification
                    // This happens when deliverer accepted (status changed to partial), not from original response creation
                    $acceptedNotificationTime = $this->getAcceptedNotificationTime($response);

                    // NEW LOGIC: Use offer_type to determine which request the sender owns
                    // For matching responses, offer_type is ALWAYS 'send' (send request offered to deliverer)
                    if ($response->offer_type === 'send') {
                        // Send request is offered to deliverer
                        // - offer_id = sender's send request (mark as accepted)
                        UpdateSendRequestAccepted::dispatch($response->offer_id, $acceptedNotificationTime)
                            ->delay(now()->addSeconds(3))
                            ->onQueue('gsheets');

                        Log::info('ResponseObserver: Dispatched SendRequestAccepted job for send->delivery matching', [
                            'response_id' => $response->id,
                            'sender_send_request_id' => $response->offer_id,
                            'sender_notification_time' => $acceptedNotificationTime
                        ]);
                    } else {
                        // Delivery request is offered to sender (rare case)
                        // - request_id = sender's send request (mark as accepted)
                        UpdateSendRequestAccepted::dispatch($response->request_id, $acceptedNotificationTime)
                            ->delay(now()->addSeconds(3))
                            ->onQueue('gsheets');

                        Log::info('ResponseObserver: Dispatched SendRequestAccepted job for delivery->send matching', [
                            'response_id' => $response->id,
                            'sender_send_request_id' => $response->request_id,
                            'sender_notification_time' => $acceptedNotificationTime
                        ]);
                    }

                    // Send acceptance notification to deliverer
                    $delivererUser = $response->getDelivererUser();
                    if ($delivererUser) {
                        $this->notificationService->sendAcceptanceNotification($delivererUser->id);
                        Log::info('ResponseObserver: Sent acceptance notification to deliverer', [
                            'response_id' => $response->id,
                            'deliverer_user_id' => $delivererUser->id
                        ]);
                    }
                }
            }

            // Handle rejections - need to track in Google Sheets when responses are rejected
            if ($currentOverallStatus === 'rejected' && $previousOverallStatus !== 'rejected') {
                $this->handleResponseRejection($response);
            }

            // CRITICAL FIX: Handle individual response closures (new for multiple response support)
            if ($currentOverallStatus === 'closed' && $previousOverallStatus !== 'closed') {
                $this->handleResponseClosure($response);
            }

            // Handle automatic redistribution when deliverer declines matching response
            $this->handleRedistributionOnDecline($response, $previousOverallStatus, $currentOverallStatus);
        }
    }

    /**
     * Handle the Response "deleted" event.
     */
    public function deleted(Response $response): void
    {
        Log::info('ResponseObserver: Response deleted, handling Google Sheets cleanup', [
            'response_id' => $response->id,
            'response_type' => $response->response_type,
            'offer_type' => $response->offer_type,
            'offer_id' => $response->offer_id,
            'request_id' => $response->request_id
        ]);

        // When a response is deleted (cancelled), we need to reset the "received" status
        // in Google Sheets if this was the only active response for the request
        $this->handleResponseCancellation($response);
    }

    /**
     * Handle response rejection - reset Google Sheets status if no other active responses
     */
    private function handleResponseRejection(Response $response): void
    {
        Log::info('ResponseObserver: Handling response rejection for Google Sheets', [
            'response_id' => $response->id,
            'response_type' => $response->response_type,
            'offer_type' => $response->offer_type
        ]);

        // Check if there are other active responses for this request
        // If not, reset the "received" status in Google Sheets
        if ($response->response_type === 'manual') {
            $this->resetRequestStatusIfNoActiveResponses($response->offer_type, $response->offer_id, $response->id);
        } else {
            // For matching responses, check both the offer and the request
            $this->resetRequestStatusIfNoActiveResponses($response->offer_type, $response->offer_id, $response->id);

            // Also check the other request involved in matching
            $otherOfferType = $response->offer_type === 'send' ? 'delivery' : 'send';
            $otherRequestId = $response->offer_type === 'send' ? $response->request_id : $response->offer_id;
            $this->resetRequestStatusIfNoActiveResponses($otherOfferType, $otherRequestId, $response->id);
        }
    }

    /**
     * Handle response cancellation (deletion) - reset Google Sheets status if needed
     */
    private function handleResponseCancellation(Response $response): void
    {
        Log::info('ResponseObserver: Handling response cancellation for Google Sheets', [
            'response_id' => $response->id,
            'response_type' => $response->response_type,
            'offer_type' => $response->offer_type
        ]);

        // Check if there are other active responses for this request
        // If not, reset the "received" status in Google Sheets
        if ($response->response_type === 'manual') {
            $this->resetRequestStatusIfNoActiveResponses($response->offer_type, $response->offer_id, $response->id);
        } else {
            // For matching responses, check both the offer and the request
            $this->resetRequestStatusIfNoActiveResponses($response->offer_type, $response->offer_id, $response->id);

            // Also check the other request involved in matching
            $otherOfferType = $response->offer_type === 'send' ? 'delivery' : 'send';
            $otherRequestId = $response->offer_type === 'send' ? $response->request_id : $response->offer_id;
            $this->resetRequestStatusIfNoActiveResponses($otherOfferType, $otherRequestId, $response->id);
        }
    }

    /**
     * CRITICAL FIX: Handle individual response closure (new for multiple response support)
     * When a response is closed via chat completion, check if request should be closed too
     */
    private function handleResponseClosure(Response $response): void
    {
        Log::info('ResponseObserver: Individual response closed, checking if requests should be closed', [
            'response_id' => $response->id,
            'response_type' => $response->response_type,
            'offer_type' => $response->offer_type,
            'previous_status' => $response->getOriginal('overall_status'),
            'current_status' => $response->overall_status
        ]);

        // Note: The ChatController already handles request closure logic
        // This observer just logs the event for tracking purposes
        // The actual request closure and Google Sheets integration
        // is triggered by RequestObserver when request status changes to 'completed'

        Log::info('ResponseObserver: Response closure logged, request closure handled by ChatController', [
            'response_id' => $response->id,
            'closure_method' => 'individual_response_completion'
        ]);
    }

    /**
     * Reset request status in Google Sheets if no other active responses exist
     */
    private function resetRequestStatusIfNoActiveResponses(string $offerType, int $requestId, int $excludeResponseId): void
    {
        // Check if there are any other active responses for this request
        // CRITICAL FIX: Exclude 'closed' status from active responses (new for multiple response support)
        $activeResponsesCount = \App\Models\Response::where('offer_type', $offerType)
            ->where('offer_id', $requestId)
            ->where('id', '!=', $excludeResponseId)
            ->whereIn('overall_status', ['pending', 'partial', 'accepted'])
            ->count();

        if ($activeResponsesCount === 0) {
            Log::info('ResponseObserver: No other active responses found, resetting Google Sheets status', [
                'offer_type' => $offerType,
                'request_id' => $requestId,
                'excluded_response_id' => $excludeResponseId
            ]);

            // Reset the "received" status in Google Sheets by passing false
            if ($offerType === 'send') {
                // Create a reset job that will clear the received status
                \App\Jobs\ResetSendRequestReceived::dispatch($requestId)
                    ->delay(now()->addSeconds(2))
                    ->onQueue('gsheets');
            } else {
                // Create a reset job that will clear the received status
                \App\Jobs\ResetDeliveryRequestReceived::dispatch($requestId)
                    ->delay(now()->addSeconds(2))
                    ->onQueue('gsheets');
            }
        }
    }

    /**
     * Handle automatic redistribution when a deliverer declines a matching response
     */
    private function handleRedistributionOnDecline(Response $response, string $previousStatus, string $currentStatus): void
    {
        // Check if redistribution is disabled
        if (!config('capacity.rebalancing.enabled', true)) {
            return;
        }

        // Only handle matching responses that just got rejected
        if ($response->response_type !== 'matching' || $currentStatus !== 'rejected' || $previousStatus === 'rejected') {
            return;
        }

        // CRITICAL FIX: Only redistribute when DELIVERER rejects (pending → rejected)
        // Do NOT redistribute when SENDER rejects (partial → rejected)
        if ($previousStatus !== 'pending') {
            Log::info('Skipping redistribution - sender rejected, not deliverer', [
                'response_id' => $response->id,
                'previous_status' => $previousStatus,
                'current_status' => $currentStatus,
                'rejection_type' => $previousStatus === 'partial' ? 'sender_rejection' : 'unknown'
            ]);
            return;
        }

        Log::info('Deliverer declined matching response, attempting redistribution', [
            'response_id' => $response->id,
            'declined_by' => $response->user_id,
            'send_request_id' => $response->offer_id,
            'previous_status' => $previousStatus,
            'current_status' => $currentStatus
        ]);

        // Attempt redistribution
        $redistributed = $this->redistributionService->redistributeOnDecline($response);
        
        if ($redistributed) {
            Log::info('Response successfully redistributed after decline', [
                'original_response_id' => $response->id,
                'send_request_id' => $response->offer_id
            ]);
        } else {
            Log::warning('Failed to redistribute response after decline - no available deliverers', [
                'response_id' => $response->id,
                'send_request_id' => $response->offer_id
            ]);
        }
    }

    /**
     * Get the time when sender was notified about a matching response
     * For matching responses, sender gets notified when deliverer accepts (partial status)
     */
    private function getAcceptedNotificationTime(Response $response): string
    {
        // For matching responses, sender is notified when deliverer accepts
        if ($response->response_type === 'matching') {
            // The key insight: in dual acceptance system, sender gets notified when deliverer accepts
            // Since we're processing sender acceptance now, we can estimate when deliverer accepted
            // by looking at when the response status became 'partial'

            // For better accuracy, use the response's updated_at time as approximation
            // This represents when the deliverer accepted (making it partial)
            // Subtract a small buffer since sender acceptance happens after deliverer acceptance
            $delivererAcceptanceTime = $response->updated_at;

            Log::info('Calculated sender notification time for matching response', [
                'response_id' => $response->id,
                'original_created_at' => $response->created_at->toISOString(),
                'estimated_deliverer_accepted_at' => $delivererAcceptanceTime->toISOString(),
                'current_updated_at' => $response->updated_at->toISOString()
            ]);

            return $delivererAcceptanceTime->toISOString();
        }

        // For manual responses, use the response creation time (immediate notification)
        return $response->created_at->toISOString();
    }

}
