<?php

namespace App\Observers;

use App\Models\Response;
use App\Jobs\UpdateDeliveryRequestReceived;
use App\Jobs\UpdateDeliveryRequestAccepted;
use App\Jobs\UpdateSendRequestReceived;
use App\Jobs\UpdateSendRequestAccepted;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class ResponseObserver
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    /**
     * Handle the Response "created" event.
     */
    public function created(Response $response): void
    {
        // For matching responses, mark the DeliveryRequest as "received"
        if ($response->response_type === 'matching') {
            UpdateDeliveryRequestReceived::dispatch($response->request_id)
                ->delay(now()->addSeconds(3))
                ->onQueue('gsheets');

            Log::info('ResponseObserver: Dispatched UpdateDeliveryRequestReceived job', [
                'response_id' => $response->id,
                'delivery_request_id' => $response->request_id
            ]);
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
                        UpdateSendRequestAccepted::dispatch($response->offer_id, $response->created_at->toISOString())
                            ->delay(now()->addSeconds(3))
                            ->onQueue('gsheets');

                        Log::info('ResponseObserver: Dispatched UpdateSendRequestAccepted for manual response', [
                            'response_id' => $response->id,
                            'send_request_id' => $response->offer_id
                        ]);
                    } else {
                        // Request owner accepted a response to their delivery request
                        UpdateDeliveryRequestAccepted::dispatch($response->offer_id, $response->created_at->toISOString())
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
                    // Deliverer accepted → DeliveryRequest marked as "accepted" + SendRequest marked as "received"
                    Log::info('ResponseObserver: Deliverer acceptance detected', [
                        'response_id' => $response->id,
                        'delivery_request_id' => $response->request_id,
                        'send_request_id' => $response->offer_id
                    ]);

                    UpdateDeliveryRequestAccepted::dispatch($response->request_id, $response->created_at->toISOString())
                        ->delay(now()->addSeconds(3))
                        ->onQueue('gsheets');

                    UpdateSendRequestReceived::dispatch($response->offer_id)
                        ->delay(now()->addSeconds(4))
                        ->onQueue('gsheets');

                    Log::info('ResponseObserver: Dispatched DeliveryRequestAccepted and SendRequestReceived jobs', [
                        'response_id' => $response->id,
                        'delivery_request_id' => $response->request_id,
                        'send_request_id' => $response->offer_id
                    ]);

                    // Send notification to sender about deliverer acceptance
                    $senderUser = $response->getSenderUser();
                    if ($senderUser) {
                        $this->notificationService->sendResponseNotification($senderUser->id);
                        Log::info('ResponseObserver: Sent notification to sender about deliverer acceptance', [
                            'response_id' => $response->id,
                            'sender_user_id' => $senderUser->id
                        ]);
                    }
                } elseif ($senderJustAccepted) {
                    // Sender accepted → SendRequest marked as "accepted"
                    Log::info('ResponseObserver: Sender acceptance detected', [
                        'response_id' => $response->id,
                        'send_request_id' => $response->offer_id
                    ]);

                    // For matching responses, sender's waiting time should be calculated from when they received notification
                    // This happens when deliverer accepted (status changed to partial), not from original response creation
                    $senderNotificationTime = $this->getSenderNotificationTime($response);

                    UpdateSendRequestAccepted::dispatch($response->offer_id, $senderNotificationTime)
                        ->delay(now()->addSeconds(3))
                        ->onQueue('gsheets');

                    Log::info('ResponseObserver: Dispatched SendRequestAccepted job', [
                        'response_id' => $response->id,
                        'send_request_id' => $response->offer_id,
                        'sender_notification_time' => $senderNotificationTime
                    ]);

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
        }
    }

    /**
     * Get the time when sender was notified about a matching response
     * For matching responses, sender gets notified when deliverer accepts (partial status)
     */
    private function getSenderNotificationTime(Response $response): string
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
