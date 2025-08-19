<?php

namespace App\Services\UserRequest;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Collection;

class UserRequestService
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private UserRequestQueryService $queryService,
        private UserRequestFormatterService $formatterService
    ) {}

    /**
     * Get formatted user requests with responses and filters applied
     */
    public function getUserRequests(User $user, array $filters = []): Collection
    {
        $requests = $this->queryService->getUserRequestsWithResponses($user, $filters);
        return $this->formatterService->formatRequestCollection($requests);
    }

    /**
     * Get raw user requests with responses and filters applied (for resources)
     */
    public function getUserRequestsRaw(User $user, array $filters = []): Collection
    {
        return $this->queryService->getUserRequestsWithResponses($user, $filters);
    }

    /**
     * Get a specific user request by ID
     */
    public function getUserRequest(User $user, int $id, array $filters = []): Collection
    {
        $requests = $this->queryService->getUserRequestById($user, $id, $filters);
        return $this->formatterService->formatRequestCollection($requests);
    }

    /**
     * Get raw user request by ID (for resources)
     */
    public function getUserRequestRaw(User $user, int $id, array $filters = []): Collection
    {
        return $this->queryService->getUserRequestById($user, $id, $filters);
    }

    /**
     * Get another user's requests (for viewing other user profiles)
     */
    public function getOtherUserRequests(User $targetUser, User $currentUser, array $filters = []): Collection
    {
        $requests = $this->queryService->getOtherUserRequests($targetUser, $currentUser, $filters);
        return $this->formatterService->formatRequestCollection($requests);
    }

    /**
     * Get raw other user requests (for resources)
     */
    public function getOtherUserRequestsRaw(User $targetUser, User $currentUser, array $filters = []): Collection
    {
        return $this->queryService->getOtherUserRequests($targetUser, $currentUser, $filters);
    }

    /**
     * Get user statistics
     */
    public function getUserRequestStats(User $user): array
    {
        $user = $this->userRepository->loadUserRequestsWithResponses($user);

        return [
            'total_send_requests' => $user->sendRequests->count(),
            'total_delivery_requests' => $user->deliveryRequests->count(),
            'completed_send_requests' => $user->sendRequests->where('status', 'closed')->count(),
            'completed_delivery_requests' => $user->deliveryRequests->where('status', 'closed')->count(),
            'active_send_requests' => $user->sendRequests->whereNotIn('status', ['closed', 'completed'])->count(),
            'active_delivery_requests' => $user->deliveryRequests->whereNotIn('status', ['closed', 'completed'])->count(),
        ];
    }
}