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
        if ($response->wasChanged('status')) {
            $previousStatus = $response->getOriginal('status');
            $currentStatus = $response->status;

            Log::info('ResponseObserver: Response status changed', [
                'response_id' => $response->id,
                'previous_status' => $previousStatus,
                'current_status' => $currentStatus,
                'response_type' => $response->response_type,
                'offer_type' => $response->offer_type
            ]);

            // Note: We don't need to call UpdateGoogleSheetsResponseTracking on status changes
            // because response tracking (counts) should only happen once when the response is created
            // Status changes should only trigger acceptance tracking, not response count tracking

            // Trigger acceptance tracking in two scenarios:
            // 1. When SENDER accepts the DELIVERER's offer (final step): waiting → accepted 
            // 2. When DELIVERER accepts the SEND request (intermediate step): pending → responded
            
            if (($currentStatus === Response::STATUS_ACCEPTED && $previousStatus === Response::STATUS_WAITING) ||
                ($currentStatus === Response::STATUS_RESPONDED && $previousStatus === Response::STATUS_PENDING)) {

                $isDelivererAcceptance = $currentStatus === Response::STATUS_RESPONDED;
                $isFinalAcceptance = $currentStatus === Response::STATUS_ACCEPTED;

                Log::info('ResponseObserver: Response acceptance detected', [
                    'response_id' => $response->id,
                    'response_type' => $response->response_type,
                    'offer_type' => $response->offer_type,
                    'previous_status' => $previousStatus,
                    'current_status' => $currentStatus,
                    'is_deliverer_acceptance' => $isDelivererAcceptance,
                    'is_final_acceptance' => $isFinalAcceptance
                ]);

                // For traveller acceptance (responded status), only update their own request status initially
                // For final acceptance, update both requests
                UpdateGoogleSheetsAcceptanceTracking::dispatch($response->id)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');

                Log::info('ResponseObserver: Dispatched UpdateGoogleSheetsAcceptanceTracking job', [
                    'response_id' => $response->id,
                    'previous_status' => $previousStatus,
                    'current_status' => $currentStatus,
                    'acceptance_type' => $isDelivererAcceptance ? 'deliverer' : 'final'
                ]);
            }
        }
    }

}
