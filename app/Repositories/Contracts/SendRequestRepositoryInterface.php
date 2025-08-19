<?php

namespace App\Repositories\Contracts;

use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface SendRequestRepositoryInterface extends BaseRepositoryInterface
{
    public function findByUser(User $user): Collection;

    public function findActiveByUser(User $user): Collection;

    public function findByUserAndId(User $user, int $id): ?SendRequest;

    public function findMatchingForDelivery(DeliveryRequest $deliveryRequest): Collection;

    public function findOpenRequests(): Collection;

    public function findWithRelations(int $id, array $relations = []): ?SendRequest;

    public function updateStatus(int $id, string $status): bool;

    public function countActiveByUser(User $user): int;

    public function findByStatus(string $status): Collection;

    public function findByLocationAndDateRange(
        int $fromLocationId,
        int $toLocationId,
        string $fromDate,
        string $toDate
    ): Collection;
}