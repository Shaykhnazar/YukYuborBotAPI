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

            if (!$response) {
                Log::warning('Response not found for Google Sheets tracking', [
                    'response_id' => $this->responseId
                ]);
                return;
            }

            $targetRequest = $this->getTargetRequest($response);

            if (!$targetRequest) {
                Log::warning('Could not determine target request for response tracking', [
                    'response_id' => $response->id,
                    'response_type' => $response->response_type,
                    'request_type' => $response->request_type,
                    'offer_id' => $response->offer_id,
                    'request_id' => $response->request_id
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

            Log::info('Response tracking updated via job', [
                'response_id' => $response->id,
                'target_request_id' => $targetRequest->id,
                'request_type' => $requestType,
                'is_first_response' => $isActuallyFirstResponse
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update response tracking via job', [
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
        return $response->request_type === 'send'
            ? \App\Models\DeliveryRequest::find($response->request_id)
            : \App\Models\SendRequest::find($response->request_id);
    }

    /**
     * Check if this is the first response for the target request
     */
    private function isFirstResponseForRequest(Response $currentResponse, $targetRequest): bool
    {
        $targetRequestType = ($targetRequest instanceof \App\Models\SendRequest) ? 'send' : 'delivery';
        $targetRequestId = $targetRequest->id;

        // Count all responses for this target request (excluding the current one)
        $existingResponsesCount = Response::where(function($query) use ($targetRequestId, $targetRequestType, $currentResponse) {
            if ($targetRequestType === 'send') {
                // For send requests, look for responses where offer_id matches (manual) or request_id matches (matching)
                $query->where(function($subQuery) use ($targetRequestId) {
                    $subQuery->where('request_type', 'send')
                            ->where('offer_id', $targetRequestId);
                })->orWhere(function($subQuery) use ($targetRequestId) {
                    $subQuery->where('request_type', 'delivery')
                            ->where('request_id', $targetRequestId);
                });
            } else {
                // For delivery requests, look for responses where offer_id matches (manual) or request_id matches (matching)
                $query->where(function($subQuery) use ($targetRequestId) {
                    $subQuery->where('request_type', 'delivery')
                            ->where('offer_id', $targetRequestId);
                })->orWhere(function($subQuery) use ($targetRequestId) {
                    $subQuery->where('request_type', 'send')
                            ->where('request_id', $targetRequestId);
                });
            }
        })
        ->where('id', '!=', $currentResponse->id)
        ->where('created_at', '<', $currentResponse->created_at)
        ->count();

        return $existingResponsesCount === 0;
    }
}
