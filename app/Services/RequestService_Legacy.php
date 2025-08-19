<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RequestService_Legacy
{
    public function checkActiveRequestsLimit(User $user, int $maxActiveRequests = 3): void
    {
        $activeDeliveryCount = DeliveryRequest::where('user_id', $user->id)
            ->whereNotIn('status', [RequestStatus::CLOSED->value])
            ->count();

        $activeSendCount = SendRequest::where('user_id', $user->id)
            ->whereNotIn('status', [RequestStatus::CLOSED->value])
            ->count();

        $totalActiveRequests = $activeDeliveryCount + $activeSendCount;

        if ($totalActiveRequests >= $maxActiveRequests) {
            throw new \Exception('Удалите либо завершите одну из активных заявок, чтобы создать новую.');
        }
    }

    public function canDeleteRequest($request): bool
    {
        return !in_array($request->status, [
            RequestStatus::MATCHED->value,
            RequestStatus::MATCHED_MANUALLY->value,
            RequestStatus::COMPLETED->value
        ], true);
    }

    public function canCloseRequest($request): bool
    {
        return in_array($request->status, [
            RequestStatus::MATCHED->value,
            RequestStatus::MATCHED_MANUALLY->value
        ], true);
    }

    public function deleteRequest($request): void
    {
        if (!$this->canDeleteRequest($request)) {
            throw new \Exception('Cannot delete completed or matched request');
        }

        DB::beginTransaction();

        try {
            $this->deleteRelatedResponses($request);
            $request->delete();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function closeRequest($request): void
    {
        if (!$this->canCloseRequest($request)) {
            throw new \Exception('Can only close matched requests');
        }

        DB::beginTransaction();

        try {
            $request->update(['status' => RequestStatus::CLOSED->value]);
            $this->closeRelatedResponses($request);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function deleteRelatedResponses($request): void
    {
        $requestType = $request instanceof SendRequest ? 'send' : 'delivery';
        $oppositeType = $requestType === 'send' ? 'delivery' : 'send';

        Response::where(function($query) use ($request, $requestType, $oppositeType) {
            $query->where(function($subQuery) use ($request, $requestType) {
                $subQuery->where('offer_type', $requestType)
                         ->where('request_id', $request->id);
            })->orWhere(function($subQuery) use ($request, $oppositeType) {
                $subQuery->where('offer_type', $oppositeType)
                         ->where('offer_id', $request->id);
            });
        })->delete();
    }

    private function closeRelatedResponses($request): void
    {
        Response::where(function($query) use ($request) {
            $query->where('request_id', $request->id)
                ->orWhere('offer_id', $request->id);
        })
        ->whereIn('overall_status', [
            RequestStatus::MATCHED->value,
            RequestStatus::MATCHED_MANUALLY->value,
            'waiting',
            'responded',
            'pending'
        ])
        ->update(['overall_status' => RequestStatus::CLOSED->value]);
    }
}
