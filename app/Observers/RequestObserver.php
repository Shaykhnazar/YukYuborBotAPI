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
            } else {
                RecordDeliveryRequestToGoogleSheets::dispatch($request->id)
                    ->delay(now()->addSeconds(2))
                    ->onQueue('gsheets');
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
            // Update Google Sheets when request status changes to specific statuses
            if (in_array($request->status, ['closed', 'completed', 'matched_manually', 'matched', 'has_responses'])) {
                UpdateRequestInGoogleSheets::dispatch($requestType, $request->id)
                    ->delay(now()->addSeconds(3))
                    ->onQueue('gsheets');
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
