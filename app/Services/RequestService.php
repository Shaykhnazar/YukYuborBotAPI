<?php

namespace App\Services;

use App\Enums\RequestStatus;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Models\User;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\ResponseRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use Illuminate\Support\Facades\DB;

class RequestService
{
    public function __construct(
        private SendRequestRepositoryInterface $sendRequestRepository,
        private DeliveryRequestRepositoryInterface $deliveryRequestRepository,
        private ResponseRepositoryInterface $responseRepository
    ) {}

    public function checkActiveRequestsLimit(User $user, int $maxActiveRequests = 3): void
    {
        $activeDeliveryCount = $this->deliveryRequestRepository->countActiveByUser($user);
        $activeSendCount = $this->sendRequestRepository->countActiveByUser($user);
        $totalActiveRequests = $activeDeliveryCount + $activeSendCount;

        if ($totalActiveRequests >= $maxActiveRequests) {
            throw new \Exception('Удалите либо завершите одну из активных заявок, чтобы создать новую.');
        }
    }

    /**
     * Check if user already has an active request for this route and date combination
     * 
     * @param User $user
     * @param int $fromLocationId
     * @param int $toLocationId
     * @param string $date
     * @param string $requestType 'send' or 'delivery'
     * @return void
     * @throws \Exception
     */
    public function checkDuplicateRoute(User $user, int $fromLocationId, int $toLocationId, string $date, string $requestType): void
    {
        $existingRequest = null;

        if ($requestType === 'send') {
            $existingRequest = $this->sendRequestRepository->findActiveByUserAndRoute($user, $fromLocationId, $toLocationId, $date);
        } else {
            $existingRequest = $this->deliveryRequestRepository->findActiveByUserAndRoute($user, $fromLocationId, $toLocationId, $date);
        }

        if ($existingRequest) {
            throw new \Exception('У вас уже есть активная заявка для этого маршрута и даты. Удалите существующую заявку, чтобы создать новую.');
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
            $requestType = $request instanceof SendRequest ? 'send' : 'delivery';
            $this->responseRepository->deleteByRequestId($request->id, $requestType);

            if ($requestType === 'send') {
                $this->sendRequestRepository->delete($request->id);
            } else {
                $this->deliveryRequestRepository->delete($request->id);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function closeRequest(DeliveryRequest|SendRequest $request): void
    {
        // Refresh the request to get the latest status from database
        $request->refresh();

        if (!$this->canCloseRequest($request)) {
            throw new \Exception('Can only close matched requests');
        }

        DB::beginTransaction();

        try {
            $requestType = $request instanceof SendRequest ? 'send' : 'delivery';

            if ($requestType === 'send') {
                $this->sendRequestRepository->updateStatus($request->id, RequestStatus::CLOSED->value);
                $this->deliveryRequestRepository->updateMatchingRequestStatusOnClose($request->matched_delivery_id);
            } else {
                $this->deliveryRequestRepository->updateStatus($request->id, RequestStatus::CLOSED->value);
                $this->sendRequestRepository->updateMatchingRequestStatusOnClose($request->matched_send_id);
            }

            $this->responseRepository->closeByRequestId($request->id);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
