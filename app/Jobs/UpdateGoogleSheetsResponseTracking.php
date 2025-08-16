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

class UpdateGoogleSheetsResponseTracking implements ShouldQueue
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
        private readonly int $responseId,
        private readonly bool $isFirstResponse = false
    ) {}

    /**
     * @throws \Exception
     */
    public function handle(): void
    {
        try {
            // Use the simplified service
            $googleSheetsService = app(GoogleSheetsService::class);

            $response = Response::find($this->responseId);

            if (! $response) {
                Log::warning('Response not found for Google Sheets tracking', [
                    'response_id' => $this->responseId,
                ]);

                return;
            }

            $targetRequest = $this->getTargetRequest($response);

            if (! $targetRequest) {
                Log::warning('Could not determine target request for response tracking', [
                    'response_id' => $response->id,
                    'response_type' => $response->response_type,
                    'offer_type' => $response->offer_type,
                    'offer_id' => $response->offer_id,
                    'request_id' => $response->request_id,
                ]);

                return;
            }

            $requestType = ($targetRequest instanceof \App\Models\SendRequest) ? 'send' : 'delivery';

            // Check if this is actually the first response for this request
            $isActuallyFirstResponse = $this->isFirstResponseForRequest($response, $targetRequest);

            $googleSheetsService->updateRequestResponseReceived(
                $requestType,
                $targetRequest->id,
                $isActuallyFirstResponse
            );

        } catch (\Exception $e) {
            Log::error('Failed to update response tracking via job', [
                'response_id' => $this->responseId,
                'error' => $e->getMessage(),
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
        // The target request is always the one identified by request_id (the one that received the response)
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
        Log::warning('Target request not found for response', [
            'response_id' => $response->id,
            'request_id' => $response->request_id,
            'offer_id' => $response->offer_id,
            'offer_type' => $response->offer_type
        ]);

        return null;
    }

    /**
     * Check if this is the first response for the target request
     */
    private function isFirstResponseForRequest(Response $currentResponse, $targetRequest): bool
    {
        $targetRequestId = $targetRequest->id;

        // Count all responses that target this specific request (excluding the current one)
        // This includes both manual and matching responses where request_id matches the target
        $existingResponsesCount = Response::where('request_id', $targetRequestId)
            ->where('id', '!=', $currentResponse->id)
            ->where('created_at', '<', $currentResponse->created_at)
            ->count();

        Log::info('Checking if first response for target request', [
            'current_response_id' => $currentResponse->id,
            'target_request_id' => $targetRequestId,
            'target_request_type' => get_class($targetRequest),
            'existing_responses_count' => $existingResponsesCount,
            'is_first' => $existingResponsesCount === 0
        ]);

        return $existingResponsesCount === 0;
    }
}
