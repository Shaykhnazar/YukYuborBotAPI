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

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120;





    public function __construct(
        private readonly int $responseId
    ) {}

    public function handle(): void
    {
        try {
            // Use the simplified service
            $googleSheetsService = app(GoogleSheetsService::class);

            $response = Response::find($this->responseId);

            if (!$response) {
                Log::warning('Response not found for Google Sheets acceptance tracking', [
                    'response_id' => $this->responseId
                ]);
                return;
            }

            Log::info('Processing acceptance tracking for response', [
                'response_id' => $response->id,
                'response_type' => $response->response_type,
                'request_type' => $response->request_type
            ]);

            // For matching responses, update BOTH related requests
            if ($response->response_type === Response::TYPE_MATCHING) {
                $this->updateBothRequestsForMatchingResponse($response, $googleSheetsService);
            } else {
                // For manual responses, update only the target request
                $this->updateSingleRequestForManualResponse($response, $googleSheetsService);
            }

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
     * Update both send and delivery requests for matching responses
     */
    private function updateBothRequestsForMatchingResponse(Response $response, $googleSheetsService): void
    {
        // Get both requests involved in the match
        $sendRequest = null;
        $deliveryRequest = null;

        if ($response->request_type === 'send') {
            // Send request is the offer, delivery request is the target
            $sendRequest = \App\Models\SendRequest::find($response->offer_id);
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->request_id);
        } else {
            // Delivery request is the offer, send request is the target
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->offer_id);
            $sendRequest = \App\Models\SendRequest::find($response->request_id);
        }

        // Update both worksheets
        if ($sendRequest) {
            $googleSheetsService->updateRequestResponseAccepted('send', $sendRequest->id);
            Log::info('Send request acceptance updated in Google Sheets', [
                'response_id' => $response->id,
                'send_request_id' => $sendRequest->id
            ]);
        }

        if ($deliveryRequest) {
            $googleSheetsService->updateRequestResponseAccepted('delivery', $deliveryRequest->id);
            Log::info('Delivery request acceptance updated in Google Sheets', [
                'response_id' => $response->id,
                'delivery_request_id' => $deliveryRequest->id
            ]);
        }
    }

    /**
     * Update single request for manual responses
     */
    private function updateSingleRequestForManualResponse(Response $response, $googleSheetsService): void
    {
        $targetRequest = $this->getTargetRequest($response);

        if (!$targetRequest) {
            Log::warning('Could not determine target request for acceptance tracking', [
                'response_id' => $response->id
            ]);
            return;
        }

        $requestType = ($targetRequest instanceof \App\Models\SendRequest) ? 'send' : 'delivery';
        $googleSheetsService->updateRequestResponseAccepted($requestType, $targetRequest->id);

        Log::info('Manual response acceptance tracking updated via job', [
            'response_id' => $response->id,
            'target_request_id' => $targetRequest->id,
            'request_type' => $requestType
        ]);
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
