<?php

namespace App\Observers;

use App\Models\Response;
use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Services\GoogleSheetsService;
use Illuminate\Support\Facades\Log;

class ResponseObserver
{
    public function __construct(
        protected GoogleSheetsService $googleSheetsService
    ) {}

    /**
     * Handle the Response "created" event.
     */
    public function created(Response $response): void
    {
        // Dispatch after response to avoid blocking the user request
        dispatch(function () use ($response) {
            $this->updateResponseTracking($response, true);
        })->afterResponse();
    }

    /**
     * Handle the Response "updated" event.
     */
    public function updated(Response $response): void
    {
        // Check if status changed to accepted
        if ($response->wasChanged('status') && $response->status === Response::STATUS_ACCEPTED) {
            // Dispatch after response to avoid blocking the user request
            dispatch(function () use ($response) {
                $this->updateAcceptanceTracking($response);
            })->afterResponse();
        }
    }

    /**
     * Update Google Sheets response tracking when a response is received
     */
    private function updateResponseTracking(Response $response, bool $isFirstResponse): void
    {
        try {
            // Determine which request received the response
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

            $requestType = ($targetRequest instanceof SendRequest) ? 'send' : 'delivery';
            
            // Check if this is actually the first response for this request
            $isActuallyFirstResponse = $this->isFirstResponseForRequest($response, $targetRequest);
            
            $this->googleSheetsService->updateRequestResponseReceived(
                $requestType,
                $targetRequest->id,
                $isActuallyFirstResponse
            );

            Log::info('Response tracking updated via observer', [
                'response_id' => $response->id,
                'target_request_id' => $targetRequest->id,
                'request_type' => $requestType,
                'is_first_response' => $isActuallyFirstResponse
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update response tracking via observer', [
                'response_id' => $response->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update Google Sheets acceptance tracking when a response is accepted
     */
    private function updateAcceptanceTracking(Response $response): void
    {
        try {
            // Determine which request had its response accepted
            $targetRequest = $this->getTargetRequest($response);
            
            if (!$targetRequest) {
                Log::warning('Could not determine target request for acceptance tracking', [
                    'response_id' => $response->id
                ]);
                return;
            }

            $requestType = ($targetRequest instanceof SendRequest) ? 'send' : 'delivery';
            
            $this->googleSheetsService->updateRequestResponseAccepted($requestType, $targetRequest->id);

            Log::info('Acceptance tracking updated via observer', [
                'response_id' => $response->id,
                'target_request_id' => $targetRequest->id,
                'request_type' => $requestType
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update acceptance tracking via observer', [
                'response_id' => $response->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get the target request that received the response
     */
    private function getTargetRequest(Response $response): SendRequest|DeliveryRequest|null
    {
        if ($response->response_type === Response::TYPE_MANUAL) {
            // For manual responses, the target request is the one being responded to
            if ($response->request_type === 'send') {
                return SendRequest::find($response->offer_id);
            } else {
                return DeliveryRequest::find($response->offer_id);
            }
        } else {
            // For matching responses, the logic is more complex
            // The request that receives the response is determined by who gets notified
            if ($response->request_type === 'send') {
                // This is a deliverer seeing a send request - the deliverer's request receives the response
                return DeliveryRequest::find($response->request_id);
            } else {
                // This is a sender seeing a deliverer response - the sender's request receives the response  
                return SendRequest::find($response->request_id);
            }
        }
    }

    /**
     * Check if this is the first response for the target request
     */
    private function isFirstResponseForRequest(Response $currentResponse, $targetRequest): bool
    {
        $targetRequestType = ($targetRequest instanceof SendRequest) ? 'send' : 'delivery';
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