<?php

namespace App\Repositories\Contracts;

use App\Models\Response;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface ResponseRepositoryInterface extends BaseRepositoryInterface
{
    public function findByUser(User $user): Collection;

    public function findActiveByUser(User $user): Collection;

    public function findByUserWithRelations(User $user, array $relations = []): Collection;

    public function findMatchingResponse(int $sendRequestId, int $deliveryRequestId): ?Response;

    public function findActiveManualResponse(
        int $targetUserId,
        int $responderId,
        string $offerType,
        int $offerId
    ): ?Response;

    public function findRejectedManualResponse(
        int $targetUserId,
        int $responderId,
        string $offerType,
        int $offerId
    ): ?Response;

    public function updateUserStatus(int $responseId, int $userId, string $status): bool;

    public function createManualResponse(array $data): Response;

    public function createMatchingResponse(array $data): Response;

    public function deleteByRequestId(int $requestId, string $requestType): int;

    public function closeByRequestId(int $requestId): int;

    public function findByOfferTypeAndId(string $offerType, int $offerId): Collection;

    public function updateOrCreateMatching(array $conditions, array $data): Response;
}