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
        // Check if status changed to accepted OR responded (for deliverer responses)
        if ($response->status === Response::STATUS_ACCEPTED || $response->status === Response::STATUS_RESPONDED
        ) {
            if ($response->wasChanged('status')
            ) {

                Log::info('ResponseObserver: Response accepted/responded', [
                    'response_id' => $response->id,
                    'response_type' => $response->response_type,
                    'status' => $response->status
                ]);

                // Dispatch queued job to update Google Sheets acceptance tracking with a short delay
                UpdateGoogleSheetsAcceptanceTracking::dispatch($response->id)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');

                Log::info('ResponseObserver: Dispatched UpdateGoogleSheetsAcceptanceTracking job', [
                    'response_id' => $response->id,
                    'status' => $response->status
                ]);
            }
        }
    }

}
