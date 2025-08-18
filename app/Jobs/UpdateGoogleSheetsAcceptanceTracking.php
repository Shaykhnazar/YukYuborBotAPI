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

            // For matching responses, behavior depends on response status
            if ($response->response_type === Response::TYPE_MATCHING) {
                if ($response->status === Response::STATUS_RESPONDED) {
                    // Deliverer responded - only update the deliverer's request
                    $this->updateDelivererRequestOnly($response, $googleSheetsService);
                } elseif ($response->status === Response::STATUS_ACCEPTED) {
                    // Final acceptance by sender - only update the sender's request
                    // (deliverer was already updated in the previous step)
                    $this->updateSenderRequestOnly($response, $googleSheetsService);
                } else {
                    // Other statuses - handle manually or log
                    Log::warning('Unexpected response status for acceptance tracking', [
                        'response_id' => $response->id,
                        'status' => $response->status
                    ]);
                }
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
     * Update only the deliverer's request when they respond (not final acceptance yet)
     */
    private function updateDelivererRequestOnly(Response $response, $googleSheetsService): void
    {
        // When deliverer responds, only update their delivery request
        // The send request should only be updated when sender finally accepts

        if ($response->offer_type === 'send') {
            // SendRequest is being offered, DeliveryRequest received the offer
            // The deliverer (owner of delivery request) is responding
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->request_id);
            if ($deliveryRequest) {
                $googleSheetsService->updateRequestResponseAccepted('delivery', $deliveryRequest->id, $response->created_at->toISOString());
                Log::info('Deliverer request updated in Google Sheets (deliverer responded)', [
                    'response_id' => $response->id,
                    'delivery_request_id' => $deliveryRequest->id,
                ]);
            } else {
                Log::warning('DeliveryRequest not found for deliverer response tracking', [
                    'response_id' => $response->id,
                    'expected_delivery_request_id' => $response->request_id
                ]);
            }
        } else {
            // DeliveryRequest is being offered, SendRequest received the offer
            // The deliverer (owner of delivery request) is responding
            $deliveryRequest = \App\Models\DeliveryRequest::find($response->offer_id);
            if ($deliveryRequest) {
                $googleSheetsService->updateRequestResponseAccepted('delivery', $deliveryRequest->id, $response->created_at->toISOString());
                Log::info('Deliverer request updated in Google Sheets (deliverer responded)', [
                    'response_id' => $response->id,
                    'delivery_request_id' => $deliveryRequest->id,
                ]);
            } else {
                Log::warning('DeliveryRequest not found for deliverer response tracking', [
                    'response_id' => $response->id,
                    'expected_delivery_request_id' => $response->offer_id
                ]);
            }
        }
    }

    /**
     * Update only the sender's request when they finally accept the deliverer's response
     * @throws \Exception
     */
    private function updateSenderRequestOnly(Response $response, GoogleSheetsService $googleSheetsService): void
    {
        // When sender finally accepts, only update their send request
        // The delivery request was already updated when deliverer responded

        if ($response->offer_type === 'send') {
            // SendRequest is being offered, DeliveryRequest received the offer
            // The sender (owner of send request) is now accepting
            $sendRequest = \App\Models\SendRequest::find($response->offer_id);
            if ($sendRequest) {
                $googleSheetsService->updateRequestResponseAccepted('send', $sendRequest->id, $response->created_at->toISOString());
                Log::info('Sender request updated in Google Sheets (sender accepted)', [
                    'response_id' => $response->id,
                    'send_request_id' => $sendRequest->id,
                ]);
            } else {
                Log::warning('SendRequest not found for sender acceptance tracking', [
                    'response_id' => $response->id,
                    'expected_send_request_id' => $response->offer_id
                ]);
            }
        } else {
            // DeliveryRequest is being offered, SendRequest received the offer
            // The sender (owner of send request) is now accepting
            $sendRequest = \App\Models\SendRequest::find($response->request_id);
            if ($sendRequest) {
                $googleSheetsService->updateRequestResponseAccepted('send', $sendRequest->id, $response->created_at->toISOString());
                Log::info('Sender request updated in Google Sheets (sender accepted)', [
                    'response_id' => $response->id,
                    'send_request_id' => $sendRequest->id,
                ]);
            } else {
                Log::warning('SendRequest not found for sender acceptance tracking', [
                    'response_id' => $response->id,
                    'expected_send_request_id' => $response->request_id
                ]);
            }
        }
    }

    /**
     * Update single request for manual responses
     * @throws \Exception
     */
    private function updateSingleRequestForManualResponse(Response $response, GoogleSheetsService $googleSheetsService): void
    {
        $targetRequest = $googleSheetsService->getTargetRequest($response);

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

}
