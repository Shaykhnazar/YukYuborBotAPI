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
        Log::info('ResponseObserver: Response created', [
            'response_id' => $response->id,
            'response_type' => $response->response_type,
            'request_type' => $response->request_type,
            'offer_id' => $response->offer_id,
            'request_id' => $response->request_id,
            'status' => $response->status
        ]);

        // Dispatch queued job to update Google Sheets tracking
        UpdateGoogleSheetsResponseTracking::dispatch($response->id, true);
        
        Log::info('ResponseObserver: Dispatched UpdateGoogleSheetsResponseTracking job', [
            'response_id' => $response->id
        ]);
    }

    /**
     * Handle the Response "updated" event.
     */
    public function updated(Response $response): void
    {
        // Check if status changed to accepted
        if ($response->wasChanged('status') && $response->status === Response::STATUS_ACCEPTED) {
            Log::info('ResponseObserver: Response accepted', [
                'response_id' => $response->id,
                'response_type' => $response->response_type
            ]);
            
            // Dispatch queued job to update Google Sheets acceptance tracking
            UpdateGoogleSheetsAcceptanceTracking::dispatch($response->id);
            
            Log::info('ResponseObserver: Dispatched UpdateGoogleSheetsAcceptanceTracking job', [
                'response_id' => $response->id
            ]);
        }
    }

}