<?php

namespace App\Jobs;

use App\Services\GoogleSheetsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CloseRequestInGoogleSheets implements ShouldQueue
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

    /**
     * The name of the connection the job should be sent to.
     */
    public $connection = 'redis';

    public function __construct(
        private readonly string $requestType, // 'send' or 'delivery'
        private readonly int $requestId
    ) {}

    public function handle(GoogleSheetsService $googleSheetsService): void
    {
        try {
            $result = $this->requestType === 'send'
                ? $googleSheetsService->recordCloseSendRequest($this->requestId)
                : $googleSheetsService->recordCloseDeliveryRequest($this->requestId);

            Log::info('Request closed in Google Sheets via job', [
                'request_type' => $this->requestType,
                'request_id' => $this->requestId,
                'success' => $result
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to close request in Google Sheets via job', [
                'request_type' => $this->requestType,
                'request_id' => $this->requestId,
                'error' => $e->getMessage()
            ]);

            // Re-throw to mark job as failed and potentially retry
            throw $e;
        }
    }
}
