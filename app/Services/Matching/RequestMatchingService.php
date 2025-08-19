<?php

namespace App\Services\Matching;

use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class RequestMatchingService
{
    public function __construct(
        private SendRequestRepositoryInterface $sendRequestRepository,
        private DeliveryRequestRepositoryInterface $deliveryRequestRepository
    ) {}

    public function findMatchingDeliveryRequests(SendRequest $sendRequest): Collection
    {
        return $this->deliveryRequestRepository->findMatchingForSend($sendRequest);
    }

    public function findMatchingSendRequests(DeliveryRequest $deliveryRequest): Collection
    {
        return $this->sendRequestRepository->findMatchingForDelivery($deliveryRequest);
    }
}