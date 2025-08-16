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
            
            // When sender accepts deliverer's response (waiting → accepted OR responded → accepted)
            // Update acceptance tracking in Google Sheets (показать "принят")
            if ($currentStatus === Response::STATUS_ACCEPTED && 
                    in_array($previousStatus, [Response::STATUS_WAITING, Response::STATUS_RESPONDED])) {

                Log::info('ResponseObserver: Sender accepted deliverer response', [
                    'response_id' => $response->id,
                    'response_type' => $response->response_type,
                    'offer_type' => $response->offer_type,
                    'previous_status' => $previousStatus
                ]);

                // Dispatch job to update Google Sheets acceptance tracking
                UpdateGoogleSheetsAcceptanceTracking::dispatch($response->id)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');

                Log::info('ResponseObserver: Dispatched UpdateGoogleSheetsAcceptanceTracking job for sender acceptance', [
                    'response_id' => $response->id,
                    'previous_status' => $previousStatus,
                    'current_status' => $currentStatus
                ]);
            }
        }
    }

}
