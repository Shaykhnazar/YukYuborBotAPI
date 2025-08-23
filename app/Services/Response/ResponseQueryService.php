<?php

namespace App\Services\Response;

use App\Enums\ResponseStatus;
use App\Models\User;
use App\Repositories\Contracts\ResponseRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ResponseQueryService
{
    public function __construct(
        private ResponseRepositoryInterface $responseRepository
    ) {}

    public function getUserResponses(User $user): Collection
    {
        return $this->responseRepository->findByUserWithRelations($user, [
            'user.telegramUser',
            'responder.telegramUser',
            'chat'
        ]);
    }

    public function canUserSeeResponse($response, int $userId): bool
    {
        if ($response->response_type === 'manual') {
            return true;
        }

        $userRole = $response->getUserRole($userId);

        if ($userRole === 'unknown') {
            return false;
        }

        // For matching responses, implement proper dual acceptance visibility logic
        if ($response->response_type === 'matching') {
            // Always show accepted responses to both parties
            if ($response->overall_status === ResponseStatus::ACCEPTED->value) {
                return true;
            }

            // For pending status: deliverer can always see, sender only if they can act
            if ($response->overall_status === ResponseStatus::PENDING->value) {
                if ($userRole === 'deliverer') {
                    return true; // Deliverers can always see pending matches
                } elseif ($userRole === 'sender') {
                    // Senders can see pending only
                    return false;
                }
            }

            // For partial status: both parties can see (one accepted, waiting for other)
            if ($response->overall_status === ResponseStatus::PARTIAL->value) {
                return true; // Both can see partial responses
            }
        }

        // Fallback to original logic for backward compatibility
        return in_array($response->overall_status, [
            ResponseStatus::PENDING->value,
            ResponseStatus::PARTIAL->value,
            ResponseStatus::ACCEPTED->value
        ]);
    }

    public function hasActiveResponse($targetRequest, User $user, string $requestType, int $requestId): bool
    {
        return $this->responseRepository->findActiveManualResponse(
            $targetRequest->user_id,
            $user->id,
            $requestType,
            $requestId
        ) !== null;
    }

    public function findRejectedResponse($targetRequest, User $user, string $requestType, int $requestId)
    {
        return $this->responseRepository->findRejectedManualResponse(
            $targetRequest->user_id,
            $user->id,
            $requestType,
            $requestId
        );
    }
}
