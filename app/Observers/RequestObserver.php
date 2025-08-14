<?php

namespace App\Observers;

use App\Models\SendRequest;
use App\Models\DeliveryRequest;
use App\Services\RouteCacheService;
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

//        Log::info('RequestObserver: Request created', [
//            'request_type' => $requestType,
//            'request_id' => $request->id,
//            'status' => $request->status
//        ]);

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

        // Only invalidate if status changed (affects request counts)
        if (isset($changes['status'])) {
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
     * Get request type name
     */
    private function getRequestType($request): string
    {
        return $request instanceof SendRequest ? 'send' : 'delivery';
    }
}
