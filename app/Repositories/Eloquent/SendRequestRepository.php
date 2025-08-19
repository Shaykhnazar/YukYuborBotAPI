<?php

namespace App\Repositories\Eloquent;

use App\Enums\RequestStatus;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Models\User;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class SendRequestRepository extends BaseRepository implements SendRequestRepositoryInterface
{
    public function __construct(SendRequest $model)
    {
        parent::__construct($model);
    }

    public function findByUser(User $user): Collection
    {
        return $this->model->where('user_id', $user->id)->get();
    }

    public function findActiveByUser(User $user): Collection
    {
        return $this->model->where('user_id', $user->id)
            ->whereNotIn('status', [RequestStatus::CLOSED->value])
            ->get();
    }

    public function findByUserAndId(User $user, int $id): ?SendRequest
    {
        return $this->model->where('id', $id)
            ->where('user_id', $user->id)
            ->first();
    }

    public function findMatchingForDelivery(DeliveryRequest $deliveryRequest): Collection
    {
        return $this->model->where('from_location_id', $deliveryRequest->from_location_id)
            ->where('to_location_id', $deliveryRequest->to_location_id)
            ->where(function($query) use ($deliveryRequest) {
                $query->where('from_date', '<=', $deliveryRequest->to_date)
                    ->where('to_date', '>=', $deliveryRequest->from_date);
            })
            ->where(function($query) use ($deliveryRequest) {
                $query->where('size_type', $deliveryRequest->size_type)
                    ->orWhere('size_type', 'Не указана')
                    ->orWhere('size_type', null);
            })
            ->whereIn('status', [
                RequestStatus::OPEN->value,
                RequestStatus::HAS_RESPONSES->value
            ])
            ->where('user_id', '!=', $deliveryRequest->user_id)
            ->get();
    }

    public function findOpenRequests(): Collection
    {
        return $this->model->whereIn('status', [
            RequestStatus::OPEN->value,
            RequestStatus::HAS_RESPONSES->value
        ])->get();
    }

    public function findWithRelations(int $id, array $relations = []): ?SendRequest
    {
        return $this->model->with($relations)->find($id);
    }

    public function updateStatus(int $id, string $status): bool
    {
        return $this->model->where('id', $id)->update(['status' => $status]);
    }

    public function countActiveByUser(User $user): int
    {
        return $this->model->where('user_id', $user->id)
            ->whereNotIn('status', [RequestStatus::CLOSED->value])
            ->count();
    }

    public function findByStatus(string $status): Collection
    {
        return $this->model->where('status', $status)->get();
    }

    public function findByLocationAndDateRange(
        int $fromLocationId,
        int $toLocationId,
        string $fromDate,
        string $toDate
    ): Collection {
        return $this->model->where('from_location_id', $fromLocationId)
            ->where('to_location_id', $toLocationId)
            ->where(function($query) use ($fromDate, $toDate) {
                $query->where('from_date', '<=', $toDate)
                    ->where('to_date', '>=', $fromDate);
            })
            ->get();
    }
}