<?php

namespace App\Observers;

use App\Models\Response;
use App\Jobs\UpdateGoogleSheetsResponseTracking;
use App\Jobs\UpdateGoogleSheetsAcceptanceTracking;
use Illuminate\Support\Facades\Log;

class ResponseObserver
{

    /**
     * Handle the Response "created" event.
     */
    public function created(Response $response): void
    {
        // Dispatch queued job to update Google Sheets tracking with a short delay
        UpdateGoogleSheetsResponseTracking::dispatch($response->id, true)
            ->delay(now()->addSeconds(3))
            ->onQueue('gsheets');

        Log::info('ResponseObserver: Dispatched UpdateGoogleSheetsResponseTracking job', [
            'response_id' => $response->id
        ]);
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

            Log::info('ResponseObserver: Response status changed (new system)', [
                'response_id' => $response->id,
                'deliverer_status' => "$previousDelivererStatus → $currentDelivererStatus",
                'sender_status' => "$previousSenderStatus → $currentSenderStatus", 
                'overall_status' => "$previousOverallStatus → $currentOverallStatus",
                'response_type' => $response->response_type,
                'offer_type' => $response->offer_type
            ]);

            // Trigger Google Sheets tracking when someone accepts (moves from pending to accepted)
            $delivererJustAccepted = ($previousDelivererStatus === 'pending' && $currentDelivererStatus === 'accepted');
            $senderJustAccepted = ($previousSenderStatus === 'pending' && $currentSenderStatus === 'accepted');
            
            if ($delivererJustAccepted || $senderJustAccepted) {
                $acceptanceType = $delivererJustAccepted ? 'deliverer' : 'sender';
                
                Log::info('ResponseObserver: Acceptance detected in new system', [
                    'response_id' => $response->id,
                    'acceptance_type' => $acceptanceType,
                    'overall_status' => $currentOverallStatus,
                    'deliverer_accepted' => $delivererJustAccepted,
                    'sender_accepted' => $senderJustAccepted
                ]);

                UpdateGoogleSheetsAcceptanceTracking::dispatch($response->id, $acceptanceType)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');

                Log::info('ResponseObserver: Dispatched Google Sheets job for new system', [
                    'response_id' => $response->id,
                    'acceptance_type' => $acceptanceType
                ]);
            }
        }
    }

}
