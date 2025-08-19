<?php

namespace App\Observers;

use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Services\RouteCacheService;
use App\Jobs\RecordSendRequestToGoogleSheets;
use App\Jobs\RecordDeliveryRequestToGoogleSheets;
use App\Jobs\UpdateRequestInGoogleSheets;
use Illuminate\Support\Facades\Log;

class RequestObserver
{
    public function __construct(
        private RouteCacheService $routeCacheService
    ) {}

    /**
     * Handle the SendRequest/DeliveryRequest "created" event.
     */
    public function created($request): void
    {
        $requestType = $this->getRequestType($request);

        // Handle Google Sheets integration for new requests
        $this->handleGoogleSheetsCreation($request, $requestType);
        
        $this->invalidateRequestCountsCache($request, 'created');
    }

    /**
     * Handle the SendRequest/DeliveryRequest "updated" event.
     */
    public function updated($request): void
    {
        $requestType = $this->getRequestType($request);
        $changes = $request->getChanges();

        Log::info('RequestObserver: Request updated', [
            'request_type' => $requestType,
            'request_id' => $request->id,
            'changes' => $changes
        ]);

        // Handle Google Sheets integration for status changes
        if (isset($changes['status'])) {
            $this->handleGoogleSheetsUpdate($request, $requestType);
            $this->invalidateRequestCountsCache($request, 'updated');
        }
    }

    /**
     * Handle the SendRequest/DeliveryRequest "deleted" event.
     */
    public function deleted($request): void
    {
        $requestType = $this->getRequestType($request);

        Log::info('RequestObserver: Request deleted', [
            'request_type' => $requestType,
            'request_id' => $request->id
        ]);

        // Clean up associated responses and update matched request statuses
        $this->handleRequestDeletionCleanup($request, $requestType);

        $this->invalidateRequestCountsCache($request, 'deleted');
    }

    /**
     * Handle the SendRequest/DeliveryRequest "restored" event.
     */
    public function restored($request): void
    {
        $requestType = $this->getRequestType($request);

        Log::info('RequestObserver: Request restored', [
            'request_type' => $requestType,
            'request_id' => $request->id
        ]);

        $this->invalidateRequestCountsCache($request, 'restored');
    }

    /**
     * Handle the SendRequest/DeliveryRequest "force deleted" event.
     */
    public function forceDeleted($request): void
    {
        $requestType = $this->getRequestType($request);

        Log::info('RequestObserver: Request force deleted', [
            'request_type' => $requestType,
            'request_id' => $request->id
        ]);

        $this->invalidateRequestCountsCache($request, 'force_deleted');
    }

    /**
     * Handle cleanup when a request is deleted
     */
    private function handleRequestDeletionCleanup($deletedRequest, string $requestType): void
    {
        try {
            $deletedRequestId = $deletedRequest->id;
            
            Log::info('RequestObserver: Starting deletion cleanup', [
                'request_type' => $requestType,
                'request_id' => $deletedRequestId
            ]);

            // Find all responses where this request was involved
            $responsesToDelete = \App\Models\Response::where(function($query) use ($deletedRequestId, $requestType) {
                // Cases where deleted request was the offering request
                $query->where('offer_id', $deletedRequestId)
                      ->where('offer_type', $requestType);
            })
            ->orWhere(function($query) use ($deletedRequestId) {
                // Cases where deleted request was the receiving request
                $query->where('request_id', $deletedRequestId);
            })->get();

            Log::info('RequestObserver: Found responses to clean up', [
                'request_id' => $deletedRequestId,
                'responses_count' => $responsesToDelete->count(),
                'response_ids' => $responsesToDelete->pluck('id')->toArray()
            ]);

            foreach ($responsesToDelete as $response) {
                $this->cleanupMatchedRequestStatus($response, $deletedRequestId, $requestType);
            }

            // Delete the responses
            if ($responsesToDelete->isNotEmpty()) {
                \App\Models\Response::whereIn('id', $responsesToDelete->pluck('id'))->delete();
                
                Log::info('RequestObserver: Deleted responses', [
                    'deleted_request_id' => $deletedRequestId,
                    'deleted_response_count' => $responsesToDelete->count()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('RequestObserver: Failed to cleanup deleted request', [
                'request_type' => $requestType,
                'request_id' => $deletedRequest->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update the status of the other matched request if it has no more responses
     */
    private function cleanupMatchedRequestStatus(\App\Models\Response $response, int $deletedRequestId, string $deletedRequestType): void
    {
        // Determine which request needs status cleanup (the one that wasn't deleted)
        $otherRequestId = null;
        $otherRequestType = null;

        if ($response->offer_id == $deletedRequestId && $response->offer_type == $deletedRequestType) {
            // Deleted request was the offering request → cleanup receiving request
            $otherRequestId = $response->request_id;
            $otherRequestType = $response->offer_type === 'send' ? 'delivery' : 'send';
        } elseif ($response->request_id == $deletedRequestId) {
            // Deleted request was the receiving request → cleanup offering request  
            $otherRequestId = $response->offer_id;
            $otherRequestType = $response->offer_type;
        }

        if (!$otherRequestId || !$otherRequestType) {
            Log::warning('RequestObserver: Could not determine other request for cleanup', [
                'response_id' => $response->id,
                'deleted_request_id' => $deletedRequestId
            ]);
            return;
        }

        // Check if the other request will have any remaining responses after this cleanup
        $remainingResponsesCount = \App\Models\Response::where('id', '!=', $response->id)
            ->where(function($query) use ($otherRequestId, $otherRequestType) {
                // Responses where other request is receiving
                $query->where('request_id', $otherRequestId)
                    // Or responses where other request is offering
                    ->orWhere(function($subQuery) use ($otherRequestId, $otherRequestType) {
                        $subQuery->where('offer_id', $otherRequestId)
                                 ->where('offer_type', $otherRequestType);
                    });
            })->count();

        Log::info('RequestObserver: Checking remaining responses for other request', [
            'other_request_id' => $otherRequestId,
            'other_request_type' => $otherRequestType,
            'remaining_responses_count' => $remainingResponsesCount
        ]);

        // If no remaining responses, update status back to 'open'
        if ($remainingResponsesCount === 0) {
            $otherRequestModel = $otherRequestType === 'send' 
                ? \App\Models\SendRequest::find($otherRequestId)
                : \App\Models\DeliveryRequest::find($otherRequestId);

            if ($otherRequestModel && $otherRequestModel->status === 'has_responses') {
                $otherRequestModel->update(['status' => 'open']);
                
                Log::info('RequestObserver: Updated other request status back to open', [
                    'other_request_type' => $otherRequestType,
                    'other_request_id' => $otherRequestId,
                    'previous_status' => 'has_responses',
                    'new_status' => 'open'
                ]);
            }
        }
    }

    /**
     * Invalidate route request counts cache
     */
    private function invalidateRequestCountsCache($request, string $action): void
    {
        try {
            $this->routeCacheService->invalidateRequestCountsCache();

            Log::info('RequestObserver: Route request counts cache invalidated successfully', [
                'request_type' => $this->getRequestType($request),
                'request_id' => $request->id,
                'action' => $action
            ]);
        } catch (\Exception $e) {
            Log::error('RequestObserver: Failed to invalidate route request counts cache', [
                'request_type' => $this->getRequestType($request),
                'request_id' => $request->id,
                'action' => $action,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle Google Sheets integration for new requests
     */
    private function handleGoogleSheetsCreation($request, string $requestType): void
    {
        try {
            if ($requestType === 'send') {
                RecordSendRequestToGoogleSheets::dispatch($request->id)
                    ->delay(now()->addSeconds(2))
                    ->onQueue('gsheets');
                    
                Log::info('RequestObserver: Dispatched RecordSendRequestToGoogleSheets job', [
                    'request_type' => $requestType,
                    'request_id' => $request->id
                ]);
            } else {
                RecordDeliveryRequestToGoogleSheets::dispatch($request->id)
                    ->delay(now()->addSeconds(2))
                    ->onQueue('gsheets');
                    
                Log::info('RequestObserver: Dispatched RecordDeliveryRequestToGoogleSheets job', [
                    'request_type' => $requestType,
                    'request_id' => $request->id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to dispatch Google Sheets creation job', [
                'request_type' => $requestType,
                'request_id' => $request->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle Google Sheets integration for status updates
     */
    private function handleGoogleSheetsUpdate($request, string $requestType): void
    {
        try {
            $oldStatus = $request->getOriginal('status');
            $newStatus = $request->status;
            
            Log::info('RequestObserver: Status change detected', [
                'request_type' => $requestType,
                'request_id' => $request->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus
            ]);
            
            // Only update Google Sheets for meaningful status changes that affect the request itself
            // Note: 'has_responses' changes are triggered by response creation, but we want to update 
            // the request status in sheets to show it has responses available
            if (in_array($newStatus, ['has_responses', 'matched', 'matched_manually', 'closed', 'completed'])) {
                UpdateRequestInGoogleSheets::dispatch($requestType, $request->id)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');
                    
                Log::info('RequestObserver: Dispatched UpdateRequestInGoogleSheets job', [
                    'request_type' => $requestType,
                    'request_id' => $request->id,
                    'status_change' => "$oldStatus → $newStatus",
                    'reason' => 'Status change requires Google Sheets update'
                ]);
            } else {
                Log::info('RequestObserver: Status change ignored for Google Sheets', [
                    'request_type' => $requestType,
                    'request_id' => $request->id,
                    'status_change' => "$oldStatus → $newStatus",
                    'reason' => 'Status change does not require Google Sheets update'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to dispatch Google Sheets update job', [
                'request_type' => $requestType,
                'request_id' => $request->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get request type name
     */
    private function getRequestType($request): string
    {
        return $request instanceof SendRequest ? 'send' : 'delivery';
    }
}
