<?php

namespace App\Jobs;

use App\Models\SendRequest;
use App\Services\GoogleSheetsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ResetSendRequestReceived implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        private readonly int $sendRequestId
    ) {}

    public function handle(GoogleSheetsService $googleSheetsService): void
    {
        try {
            $sendRequest = SendRequest::find($this->sendRequestId);

            if (!$sendRequest) {
                Log::warning('SendRequest not found for Google Sheets reset', [
                    'send_request_id' => $this->sendRequestId,
                ]);
                return;
            }

            Log::info('Resetting SendRequest received status in Google Sheets', [
                'send_request_id' => $sendRequest->id,
                'user_id' => $sendRequest->user_id
            ]);

            // Call the new reset method to clear the status completely
            $googleSheetsService->resetRequestResponseReceived('send', $sendRequest->id);

            Log::info('Successfully reset SendRequest received status in Google Sheets', [
                'send_request_id' => $sendRequest->id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reset SendRequest received status in Google Sheets', [
                'send_request_id' => $this->sendRequestId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}