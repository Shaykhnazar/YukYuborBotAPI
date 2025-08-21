<?php

namespace App\Repositories\Eloquent;

use App\Enums\ResponseStatus;
use App\Enums\ResponseType;
use App\Models\Response;
use App\Models\User;
use App\Repositories\Contracts\ResponseRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ResponseRepository extends BaseRepository implements ResponseRepositoryInterface
{
    public function __construct(Response $model)
    {
        parent::__construct($model);
    }

    public function findByUser(User $user): Collection
    {
        return $this->model->where(function($query) use ($user) {
            $query->where('user_id', $user->id)
                  ->orWhere('responder_id', $user->id);
        })->get();
    }

    public function findActiveByUser(User $user): Collection
    {
        return $this->model->where(function($query) use ($user) {
            $query->where('user_id', $user->id)
                  ->orWhere('responder_id', $user->id);
        })
        ->whereIn('overall_status', [
            ResponseStatus::PENDING->value,
            ResponseStatus::PARTIAL->value,
            ResponseStatus::ACCEPTED->value
        ])
        ->orderByDesc('created_at')
        ->get();
    }

    public function findByUserWithRelations(User $user, array $relations = []): Collection
    {
        return $this->model->where(function($query) use ($user) {
            $query->where('user_id', $user->id)
                  ->orWhere('responder_id', $user->id);
        })
        ->whereIn('overall_status', [
            ResponseStatus::PENDING->value,
            ResponseStatus::PARTIAL->value,
            ResponseStatus::ACCEPTED->value
        ])
        ->with($relations)
        ->orderByDesc('created_at')
        ->get();
    }

    public function findMatchingResponse(int $sendRequestId, int $deliveryRequestId): ?Response
    {
        return $this->model->where(function($query) use ($sendRequestId, $deliveryRequestId) {
            $query->where('request_id', $sendRequestId)
                  ->where('offer_id', $deliveryRequestId)
                  ->where('offer_type', 'delivery');
        })
        ->orWhere(function($query) use ($sendRequestId, $deliveryRequestId) {
            $query->where('request_id', $deliveryRequestId)
                  ->where('offer_id', $sendRequestId)
                  ->where('offer_type', 'send');
        })
        ->where('response_type', ResponseType::MATCHING->value)
        ->whereIn('overall_status', [ResponseStatus::PENDING->value, ResponseStatus::PARTIAL->value])
        ->first();
    }

    public function findActiveManualResponse(
        int $targetUserId,
        int $responderId,
        string $offerType,
        int $offerId
    ): ?Response {
        return $this->model->where(function($query) use ($targetUserId, $responderId, $offerType, $offerId) {
            $query->where(function($subQuery) use ($targetUserId, $responderId, $offerType, $offerId) {
                $subQuery->where('user_id', $targetUserId)
                        ->where('responder_id', $responderId)
                        ->where('offer_type', $offerType)
                        ->where('offer_id', $offerId);
            })->orWhere(function($subQuery) use ($targetUserId, $responderId, $offerType, $offerId) {
                $subQuery->where('user_id', $responderId)
                        ->where('responder_id', $targetUserId)
                        ->where('offer_type', $offerType)
                        ->where('offer_id', $offerId);
            });
        })
        ->whereIn('overall_status', [
            ResponseStatus::PENDING->value,
            ResponseStatus::PARTIAL->value,
            ResponseStatus::ACCEPTED->value
        ])
        ->where('response_type', ResponseType::MANUAL->value)
        ->first();
    }

    public function findRejectedManualResponse(
        int $targetUserId,
        int $responderId,
        string $offerType,
        int $offerId
    ): ?Response {
        return $this->model->where('user_id', $targetUserId)
            ->where('responder_id', $responderId)
            ->where('offer_type', $offerType)
            ->where('offer_id', $offerId)
            ->where('overall_status', ResponseStatus::REJECTED->value)
            ->where('response_type', ResponseType::MANUAL->value)
            ->first();
    }

    public function updateUserStatus(int $responseId, int $userId, string $status): bool
    {
        $response = $this->find($responseId);
        if (!$response) {
            return false;
        }

        return $response->updateUserStatus($userId, $status);
    }

    public function createManualResponse(array $data): Response
    {
        return $this->create(array_merge($data, [
            'response_type' => ResponseType::MANUAL->value,
            'overall_status' => ResponseStatus::PENDING->value,
        ]));
    }

    public function createMatchingResponse(array $data): Response
    {
        return $this->create(array_merge($data, [
            'response_type' => ResponseType::MATCHING->value,
            'overall_status' => ResponseStatus::PENDING->value,
        ]));
    }

    public function deleteByRequestId(int $requestId, string $requestType): int
    {
        $oppositeType = $requestType === 'send' ? 'delivery' : 'send';

        return $this->model->where(function($query) use ($requestId, $requestType, $oppositeType) {
            $query->where(function($subQuery) use ($requestId, $requestType) {
                $subQuery->where('offer_type', $requestType)
                         ->where('request_id', $requestId);
            })->orWhere(function($subQuery) use ($requestId, $oppositeType) {
                $subQuery->where('offer_type', $oppositeType)
                         ->where('offer_id', $requestId);
            });
        })->delete();
    }

    public function closeByRequestId(int $requestId): int
    {
        return $this->model->where(function($query) use ($requestId) {
            $query->where('request_id', $requestId)
                ->orWhere('offer_id', $requestId);
        })
        ->whereIn('overall_status', [
            ResponseStatus::ACCEPTED->value,
            'waiting',
            'responded',
            ResponseStatus::PENDING->value
        ])
        ->update(['overall_status' => ResponseStatus::CLOSED->value]);
    }

    public function findByOfferTypeAndId(string $offerType, int $offerId): Collection
    {
        return $this->model->where('offer_type', $offerType)
            ->where('offer_id', $offerId)
            ->get();
    }

    public function updateOrCreateMatching(array $conditions, array $data): Response
    {
        return $this->model->updateOrCreate($conditions, array_merge($data, [
            'response_type' => ResponseType::MATCHING->value,
        ]));
    }
}
