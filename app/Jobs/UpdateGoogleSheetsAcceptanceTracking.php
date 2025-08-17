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

    public function handle(GoogleSheetsService $googleSheetsService): void
    {
        try {

            $response = Response::find($this->responseId);

            if (! $response) {
                Log::warning('Response not found for Google Sheets acceptance tracking', [
                    'response_id' => $this->responseId,
                ]);

                return;
            }

//            Log::info('Processing acceptance tracking for response', [
//                'response_id' => $response->id,
//                'response_type' => $response->response_type,
//                'request_type' => $response->request_type,
//            ]);

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
                'error' => $e->getMessage(),
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

        if ($response->offer_type === 'send') {
            // SendRequest is being offered, DeliveryRequest received the offer
            $sendRequest = \App\Models\SendRequest::find($response->offer_id);
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->request_id);
        } else {
            // DeliveryRequest is being offered, SendRequest received the offer
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->offer_id);
            $sendRequest = \App\Models\SendRequest::find($response->request_id);
        }

        Log::info('UpdateGoogleSheetsAcceptanceTracking: Found requests for matching response', [
            'response_id' => $response->id,
            'offer_type' => $response->offer_type,
            'offer_id' => $response->offer_id,
            'request_id' => $response->request_id,
            'send_request_found' => $sendRequest ? $sendRequest->id : 'NOT_FOUND',
            'delivery_request_found' => $deliveryRequest ? $deliveryRequest->id : 'NOT_FOUND'
        ]);

        // Pass the response created_at time as the time when the response was received
        $responseReceivedTime = $response->created_at->toISOString();

        // Update both worksheets
        if ($sendRequest) {
            $googleSheetsService->updateRequestResponseAccepted('send', $sendRequest->id, $responseReceivedTime);
            Log::info('Send request acceptance updated in Google Sheets', [
                'response_id' => $response->id,
                'send_request_id' => $sendRequest->id,
            ]);
        } else {
            Log::warning('SendRequest not found for acceptance tracking', [
                'response_id' => $response->id,
                'expected_send_request_id' => $response->offer_type === 'send' ? $response->offer_id : $response->request_id
            ]);
        }

        if ($deliveryRequest) {
            $googleSheetsService->updateRequestResponseAccepted('delivery', $deliveryRequest->id, $responseReceivedTime);
            Log::info('Delivery request acceptance updated in Google Sheets', [
                'response_id' => $response->id,
                'delivery_request_id' => $deliveryRequest->id,
            ]);
        } else {
            Log::warning('DeliveryRequest not found for acceptance tracking', [
                'response_id' => $response->id,
                'expected_delivery_request_id' => $response->offer_type === 'delivery' ? $response->offer_id : $response->request_id
            ]);
        }
    }

    /**
     * Update only the deliverer's request when they respond (not final acceptance yet)
     */
    private function updateDelivererRequestOnly(Response $response, $googleSheetsService): void
    {
        // When deliverer responds, only update their delivery request
        // The send request should only be updated when sender finally accepts

        if ($response->offer_type === 'send') {
            // Deliverer owns a delivery request and is responding to a send request
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->request_id);
            if ($deliveryRequest) {
                $googleSheetsService->updateRequestResponseAccepted('delivery', $deliveryRequest->id, $response->created_at->toISOString());
                Log::info('Deliverer request updated in Google Sheets (deliverer responded)', [
                    'response_id' => $response->id,
                    'delivery_request_id' => $deliveryRequest->id,
                ]);
            }
        } else {
            // This case shouldn't happen in normal flow, but handle it for completeness
            $sendRequest = \App\Models\SendRequest::find($response->request_id);
            if ($sendRequest) {
                $googleSheetsService->updateRequestResponseAccepted('send', $sendRequest->id, $response->created_at->toISOString());
                Log::info('Send request updated in Google Sheets (deliverer responded)', [
                    'response_id' => $response->id,
                    'send_request_id' => $sendRequest->id,
                ]);
            }
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
                'response_id' => $response->id,
            ]);

            return;
        }

        $requestType = ($targetRequest instanceof \App\Models\SendRequest) ? 'send' : 'delivery';
        $responseReceivedTime = $response->created_at->toISOString();
        $googleSheetsService->updateRequestResponseAccepted($requestType, $targetRequest->id, $responseReceivedTime);

//        Log::info('Manual response acceptance tracking updated via job', [
//            'response_id' => $response->id,
//            'target_request_id' => $targetRequest->id,
//            'request_type' => $requestType,
//        ]);
    }

    /**
     * Get the target request that received the response
     */
    private function getTargetRequest(Response $response): \App\Models\SendRequest|\App\Models\DeliveryRequest|null
    {
        // The target request is always identified by request_id (the one that received the response)
        // First try to find it as a SendRequest
        $sendRequest = \App\Models\SendRequest::find($response->request_id);
        if ($sendRequest) {
            return $sendRequest;
        }

        // If not found as SendRequest, try DeliveryRequest
        $deliveryRequest = \App\Models\DeliveryRequest::find($response->request_id);
        if ($deliveryRequest) {
            return $deliveryRequest;
        }

        // If neither found, log the issue for debugging
        Log::warning('Target request not found for acceptance tracking', [
            'response_id' => $response->id,
            'request_id' => $response->request_id,
            'offer_id' => $response->offer_id,
            'offer_type' => $response->offer_type
        ]);

        return null;
    }
}
