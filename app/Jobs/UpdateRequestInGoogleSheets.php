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
            } elseif (in_array($request->status, ['matched', 'matched_manually'])) {
                // When request is matched, update both acceptance tracking and status
                // This ensures both send and delivery requests show as "matched" and "принят"
                $result = $googleSheetsService->updateRequestResponseAccepted(
                    $this->requestType, 
                    $this->requestId,
                    null // No specific response time available here, will use fallback logic
                );
                
                Log::info('Request marked as matched/matched_manually, updated acceptance tracking', [
                    'request_type' => $this->requestType,
                    'request_id' => $this->requestId,
                    'status' => $request->status
                ]);
            } else {
                // Use the status update methods for other statuses
                $result = $this->requestType === 'send'
                    ? $googleSheetsService->updateSendRequestStatus($this->requestId)
                    : $googleSheetsService->updateDeliveryRequestStatus($this->requestId);
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
