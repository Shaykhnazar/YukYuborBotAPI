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

            // Only trigger acceptance tracking when the SENDER accepts the DELIVERER's offer
            // This is the final step that should show "принят" for both requests
            // Look for: waiting → accepted (sender accepting deliverer's delivery offer)
            if ($currentStatus === Response::STATUS_ACCEPTED && 
                    $previousStatus === Response::STATUS_WAITING) {

                Log::info('ResponseObserver: Final acceptance - sender accepted deliverer offer', [
                    'response_id' => $response->id,
                    'response_type' => $response->response_type,
                    'offer_type' => $response->offer_type,
                    'previous_status' => $previousStatus
                ]);

                // Dispatch job to update Google Sheets acceptance tracking for BOTH requests
                UpdateGoogleSheetsAcceptanceTracking::dispatch($response->id)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');

                Log::info('ResponseObserver: Dispatched UpdateGoogleSheetsAcceptanceTracking job for final acceptance', [
                    'response_id' => $response->id,
                    'previous_status' => $previousStatus,
                    'current_status' => $currentStatus
                ]);
            }
        }
    }

}
