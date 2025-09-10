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

            // For pending status: check for competing partial responses
            if ($response->overall_status === ResponseStatus::PENDING->value) {
                if ($userRole === 'deliverer') {
                    // Check if there's another partial response for the same send request
                    if ($this->hasCompetingPartialResponse($response, $userId)) {
                        return false; // Hide this deliverer's pending response when another deliverer has partial
                    }
                    return true; // Deliverers can see pending matches if no competing partial
                } elseif ($userRole === 'sender') {
                    // Senders can see pending only
                    return false;
                }
            }

            // For partial status: check if this user is involved in the partial response
            if ($response->overall_status === ResponseStatus::PARTIAL->value) {
                // Only the users directly involved in this partial response can see it
                $delivererUser = $response->getDelivererUser();
                $senderUser = $response->getSenderUser();
                
                return $delivererUser && $delivererUser->id === $userId || 
                       $senderUser && $senderUser->id === $userId;
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

    /**
     * Check if there's a competing partial response for the same send request
     * This hides pending responses from other deliverers when one deliverer has partial acceptance
     *
     * @param $response
     * @param int $userId
     * @return bool
     */
    private function hasCompetingPartialResponse($response, int $userId): bool
    {
        // Only apply this logic to matching responses for send requests
        if ($response->response_type !== 'matching' || $response->offer_type !== 'send') {
            return false;
        }

        // Find other responses for the same send request that are partial
        $competingPartialResponses = $this->responseRepository->findWhere([
            'offer_id' => $response->offer_id,
            'offer_type' => 'send',
            'response_type' => 'matching',
            'overall_status' => ResponseStatus::PARTIAL->value
        ]);

        // Check if any of these partial responses involve a different deliverer
        foreach ($competingPartialResponses as $partialResponse) {
            $partialDelivererUser = $partialResponse->getDelivererUser();
            if ($partialDelivererUser && $partialDelivererUser->id !== $userId) {
                return true; // Found a competing partial response from another deliverer
            }
        }

        return false;
    }
}
