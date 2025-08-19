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
    public function handle(GoogleSheetsService $googleSheetsService): void
    {
        try {
            $response = Response::find($this->responseId);

            if (! $response) {
                Log::warning('Response not found for Google Sheets tracking', [
                    'response_id' => $this->responseId,
                ]);
                return;
            }

            Log::info('Processing Google Sheets response tracking', [
                'response_id' => $response->id,
                'response_type' => $response->response_type,
                'offer_type' => $response->offer_type,
                'overall_status' => $response->overall_status,
                'is_first_response_flag' => $this->isFirstResponse
            ]);

            // NEW SYSTEM: Handle based on response type and structure
            if ($response->response_type === Response::TYPE_MATCHING) {
                $this->handleMatchingResponseTracking($response, $googleSheetsService);
            } elseif ($response->response_type === Response::TYPE_MANUAL) {
                $this->handleManualResponseTracking($response, $googleSheetsService);
            }

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
     * Handle tracking for matching responses (automatic system matches)
     */
    private function handleMatchingResponseTracking(Response $response, GoogleSheetsService $googleSheetsService): void
    {
        // For matching responses, the target is always the receiving request (request_id)
        $targetRequest = $googleSheetsService->getTargetRequest($response);
        
        if (!$targetRequest) {
            Log::warning('Could not find target request for matching response', [
                'response_id' => $response->id,
                'request_id' => $response->request_id,
                'offer_type' => $response->offer_type
            ]);
            return;
        }

        $requestType = ($targetRequest instanceof \App\Models\SendRequest) ? 'send' : 'delivery';
        
        // Check if this is the first response for this target request
        $isFirstResponse = $this->isFirstResponseForRequest($response, $targetRequest);
        
        Log::info('Updating Google Sheets for matching response', [
            'response_id' => $response->id,
            'target_request_type' => $requestType,
            'target_request_id' => $targetRequest->id,
            'is_first_response' => $isFirstResponse
        ]);

        $googleSheetsService->updateRequestResponseReceived(
            $requestType,
            $targetRequest->id,
            $isFirstResponse
        );
    }

    /**
     * Handle tracking for manual responses
     */
    private function handleManualResponseTracking(Response $response, GoogleSheetsService $googleSheetsService): void
    {
        // For manual responses, the target is the request being responded to (offer_id)
        $targetRequest = $googleSheetsService->getTargetRequest($response);
        
        if (!$targetRequest) {
            Log::warning('Could not find target request for manual response', [
                'response_id' => $response->id,
                'offer_id' => $response->offer_id,
                'offer_type' => $response->offer_type
            ]);
            return;
        }

        $requestType = ($targetRequest instanceof \App\Models\SendRequest) ? 'send' : 'delivery';
        
        // For manual responses, always treat as first response since user manually creates them
        $isFirstResponse = true;
        
        Log::info('Updating Google Sheets for manual response', [
            'response_id' => $response->id,
            'target_request_type' => $requestType,
            'target_request_id' => $targetRequest->id,
            'is_first_response' => $isFirstResponse
        ]);

        $googleSheetsService->updateRequestResponseReceived(
            $requestType,
            $targetRequest->id,
            $isFirstResponse
        );
    }

    /**
     * Check if this is the first response for the target request
     */
    private function isFirstResponseForRequest(Response $currentResponse, $targetRequest): bool
    {
        $targetRequestId = $targetRequest->id;
        $targetRequestType = ($targetRequest instanceof \App\Models\SendRequest) ? 'send' : 'delivery';

        // NEW SYSTEM: Count responses that target this specific request
        // For matching responses, request_id points to the target
        // For manual responses, offer_id points to the target
        $query = Response::where('id', '!=', $currentResponse->id)
            ->where('created_at', '<', $currentResponse->created_at);

        // Add conditions based on response type and target
        $query->where(function($q) use ($targetRequestId, $targetRequestType) {
            // Matching responses targeting this request
            $q->where(function($subQ) use ($targetRequestId) {
                $subQ->where('response_type', Response::TYPE_MATCHING)
                     ->where('request_id', $targetRequestId);
            })
            // Manual responses targeting this request  
            ->orWhere(function($subQ) use ($targetRequestId, $targetRequestType) {
                $subQ->where('response_type', Response::TYPE_MANUAL)
                     ->where('offer_id', $targetRequestId)
                     ->where('offer_type', $targetRequestType);
            });
        });

        $existingResponsesCount = $query->count();

        Log::info('Checking if first response for target request (new system)', [
            'current_response_id' => $currentResponse->id,
            'target_request_id' => $targetRequestId,
            'target_request_type' => $targetRequestType,
            'existing_responses_count' => $existingResponsesCount,
            'is_first' => $existingResponsesCount === 0
        ]);

        return $existingResponsesCount === 0;
    }
}
