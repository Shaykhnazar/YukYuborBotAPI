<?php

namespace App\Jobs;

use App\Models\Response;
use App\Services\GoogleSheetsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateGoogleSheetsAcceptanceTracking implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $responseId
    ) {}

    public function handle(GoogleSheetsService $googleSheetsService): void
    {
        try {
            $response = Response::find($this->responseId);

            if (!$response) {
                Log::warning('Response not found for Google Sheets acceptance tracking', [
                    'response_id' => $this->responseId
                ]);
                return;
            }

            $targetRequest = $this->getTargetRequest($response);

            if (!$targetRequest) {
                Log::warning('Could not determine target request for acceptance tracking', [
                    'response_id' => $response->id
                ]);
                return;
            }

            $requestType = ($targetRequest instanceof \App\Models\SendRequest) ? 'send' : 'delivery';

            $googleSheetsService->updateRequestResponseAccepted($requestType, $targetRequest->id);

            Log::info('Acceptance tracking updated via job', [
                'response_id' => $response->id,
                'target_request_id' => $targetRequest->id,
                'request_type' => $requestType
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update acceptance tracking via job', [
                'response_id' => $this->responseId,
                'error' => $e->getMessage()
            ]);

            // Re-throw to mark job as failed and potentially retry
            throw $e;
        }
    }

    /**
     * Get the target request that received the response
     */
    private function getTargetRequest(Response $response): \App\Models\SendRequest|\App\Models\DeliveryRequest|null
    {
        if ($response->response_type === Response::TYPE_MANUAL) {
            // For manual responses, the target request is the one being responded to
            return $response->request_type === 'send'
                ? \App\Models\SendRequest::find($response->offer_id)
                : \App\Models\DeliveryRequest::find($response->offer_id);
        }

        // For matching responses, the logic is more complex
        return $response->request_type === 'send' ?
            \App\Models\DeliveryRequest::find($response->request_id)
            : \App\Models\SendRequest::find($response->request_id);
    }
}
