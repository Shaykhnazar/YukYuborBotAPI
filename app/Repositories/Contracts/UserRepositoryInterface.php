<?php

namespace App\Repositories\Contracts;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface UserRepositoryInterface extends BaseRepositoryInterface
{
    public function findWithRequestsAndResponses(int $userId, array $relationshipConstraints = []): ?User;
    
    public function loadUserRequestsWithResponses(User $user, array $relationshipConstraints = []): User;
    
    public function findUserSendRequestsWithResponses(int $userId): Collection;
    
    public function findUserDeliveryRequestsWithResponses(int $userId): Collection;
    
    public function findByTelegramId(string $telegramId): ?User;
}