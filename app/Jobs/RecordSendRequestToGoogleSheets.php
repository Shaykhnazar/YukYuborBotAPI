<?php

namespace App\Jobs;

use App\Services\GoogleSheetsService;
use App\Models\SendRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RecordSendRequestToGoogleSheets implements ShouldQueue
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
        private readonly int $requestId
    ) {}

    public function handle(GoogleSheetsService $googleSheetsService): void
    {
        try {
            $request = SendRequest::with(['user', 'fromLocation', 'toLocation'])->find($this->requestId);

            if (!$request) {
                Log::warning('Send request not found for Google Sheets recording', [
                    'request_id' => $this->requestId
                ]);
                return;
            }

            $result = $googleSheetsService->recordAddSendRequest($request);

            Log::info('Send request recorded to Google Sheets via job', [
                'request_id' => $request->id,
                'success' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to record send request to Google Sheets via job', [
                'request_id' => $this->requestId,
                'error' => $e->getMessage()
            ]);

            // Re-throw to mark job as failed and potentially retry
            throw $e;
        }
    }
}
