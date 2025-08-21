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
                        'overall_status' => $currentOverallStatus
                    ]);

                    UpdateGoogleSheetsAcceptanceTracking::dispatch($response->id, 'manual')
                        ->delay(now()->addSeconds(3))
                        ->onQueue('gsheets');

                    Log::info('ResponseObserver: Dispatched Google Sheets job for manual response', [
                        'response_id' => $response->id
                    ]);
                }
            } else {
                // For matching responses, track individual user acceptances
                $delivererJustAccepted = ($previousDelivererStatus === 'pending' && $currentDelivererStatus === 'accepted');
                $senderJustAccepted = ($previousSenderStatus === 'pending' && $currentSenderStatus === 'accepted');
                
                if ($delivererJustAccepted || $senderJustAccepted) {
                    // Prioritize the actual change - if both changed simultaneously, something's wrong
                    if ($delivererJustAccepted && $senderJustAccepted) {
                        Log::warning('Both deliverer and sender accepted simultaneously in matching response', [
                            'response_id' => $response->id,
                            'this_should_not_happen' => 'matching responses should have sequential acceptance'
                        ]);
                        // Default to deliverer for safety
                        $acceptanceType = 'deliverer';
                    } else {
                        $acceptanceType = $delivererJustAccepted ? 'deliverer' : 'sender';
                    }
                    
                    Log::info('ResponseObserver: Matching response acceptance detected', [
                        'response_id' => $response->id,
                        'acceptance_type' => $acceptanceType,
                        'overall_status' => $currentOverallStatus,
                        'deliverer_accepted' => $delivererJustAccepted,
                        'sender_accepted' => $senderJustAccepted
                    ]);

                    UpdateGoogleSheetsAcceptanceTracking::dispatch($response->id, $acceptanceType)
                        ->delay(now()->addSeconds(3))
                        ->onQueue('gsheets');

                    Log::info('ResponseObserver: Dispatched Google Sheets job for matching response', [
                        'response_id' => $response->id,
                        'acceptance_type' => $acceptanceType
                    ]);
                }
            }
        }
    }

}
