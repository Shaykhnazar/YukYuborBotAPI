<?php

namespace App\Repositories\Eloquent;

use App\Enums\RequestStatus;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Models\User;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class DeliveryRequestRepository extends BaseRepository implements DeliveryRequestRepositoryInterface
{
    public function __construct(DeliveryRequest $model)
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

    public function findByUserAndId(User $user, int $id): ?DeliveryRequest
    {
        return $this->model->where('id', $id)
            ->where('user_id', $user->id)
            ->first();
    }

    public function findMatchingForSend(SendRequest $sendRequest): Collection
    {
        return $this->model->where('from_location_id', $sendRequest->from_location_id)
            ->where('to_location_id', $sendRequest->to_location_id)
            ->where(function($query) use ($sendRequest) {
                $query->where('from_date', '<=', $sendRequest->to_date)
                    ->where('to_date', '>=', $sendRequest->from_date);
            })
            ->where(function($query) use ($sendRequest) {
                $query->where('size_type', $sendRequest->size_type)
                    ->orWhere('size_type', 'Не указана')
                    ->orWhere('size_type', null);
            })
            ->whereIn('status', [
                RequestStatus::OPEN->value,
                RequestStatus::HAS_RESPONSES->value
            ])
            ->where('user_id', '!=', $sendRequest->user_id)
            ->get();
    }

    public function findOpenRequests(): Collection
    {
        return $this->model->whereIn('status', [
            RequestStatus::OPEN->value,
            RequestStatus::HAS_RESPONSES->value
        ])->get();
    }

    public function findWithRelations(int $id, array $relations = []): ?DeliveryRequest
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

    public function updateMatchingRequestStatusOnClose(int|null $matchedDeliveryId): bool
    {
        return !$matchedDeliveryId
            ? false
            : $this->model->where('id', $matchedDeliveryId)
            ->update(['status' => RequestStatus::CLOSED->value]);
    }

    public function findActiveByUserAndRoute(User $user, int $fromLocationId, int $toLocationId, string $date): ?DeliveryRequest
    {
        return $this->model->where('user_id', $user->id)
            ->where('from_location_id', $fromLocationId)
            ->where('to_location_id', $toLocationId)
            ->where(function($query) use ($date) {
                $query->where('from_date', '<=', $date)
                    ->where('to_date', '>=', $date);
            })
            ->whereIn('status', [
                RequestStatus::OPEN->value,
                RequestStatus::HAS_RESPONSES->value,
                RequestStatus::MATCHED->value,
                RequestStatus::MATCHED_MANUALLY->value
            ])
            ->first();
    }
}
