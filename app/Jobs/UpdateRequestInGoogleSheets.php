<?php

namespace App\Jobs;

use App\Services\GoogleSheetsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateRequestInGoogleSheets implements ShouldQueue
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
        private readonly string $requestType, // 'send' or 'delivery'
        private readonly int $requestId
    ) {}

    public function handle(GoogleSheetsService $googleSheetsService): void
    {
        try {
            // Get the current request to check its status
            $request = $this->requestType === 'send' 
                ? \App\Models\SendRequest::find($this->requestId)
                : \App\Models\DeliveryRequest::find($this->requestId);

            if (!$request) {
                Log::warning('Request not found for Google Sheets update', [
                    'request_type' => $this->requestType,
                    'request_id' => $this->requestId
                ]);
                return;
            }

            // Use appropriate method based on status
            if (in_array($request->status, ['closed', 'completed'])) {
                // Use the existing close methods for closed/completed status
                $result = $this->requestType === 'send'
                    ? $googleSheetsService->recordCloseSendRequest($this->requestId)
                    : $googleSheetsService->recordCloseDeliveryRequest($this->requestId);
            } else {
                // For all other statuses (open, has_responses, matched, matched_manually), 
                // just update the request status in Google Sheets
                // NOTE: Do NOT call acceptance tracking here for matched status!
                // Acceptance tracking is handled by ResponseObserver when individual responses are accepted
                $result = $this->requestType === 'send'
                    ? $googleSheetsService->updateSendRequestStatus($this->requestId)
                    : $googleSheetsService->updateDeliveryRequestStatus($this->requestId);
                    
                Log::info('Request status updated in Google Sheets (status only)', [
                    'request_type' => $this->requestType,
                    'request_id' => $this->requestId,
                    'status' => $request->status,
                    'note' => 'Acceptance tracking handled separately by ResponseObserver'
                ]);
            }

            Log::info('Request status updated in Google Sheets via job', [
                'request_type' => $this->requestType,
                'request_id' => $this->requestId,
                'status' => $request->status,
                'success' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update request status in Google Sheets via job', [
                'request_type' => $this->requestType,
                'request_id' => $this->requestId,
                'error' => $e->getMessage()
            ]);

            // Re-throw to mark job as failed and potentially retry
            throw $e;
        }
    }
}
