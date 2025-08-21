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

class UpdateSendRequestReceived implements ShouldQueue
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
                Log::warning('SendRequest not found for Google Sheets received update', [
                    'send_request_id' => $this->sendRequestId,
                ]);
                return;
            }

            Log::info('Updating SendRequest as received in Google Sheets', [
                'send_request_id' => $sendRequest->id,
                'user_id' => $sendRequest->user_id
            ]);

            $googleSheetsService->updateRequestResponseReceived('send', $sendRequest->id, true);

            Log::info('Successfully updated SendRequest as received in Google Sheets', [
                'send_request_id' => $sendRequest->id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update SendRequest as received in Google Sheets', [
                'send_request_id' => $this->sendRequestId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}