<?php

namespace App\Services\UserRequest;

use App\Models\Review;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Collection;

class UserRequestQueryService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {}

    public function getUserRequestsWithResponses(User $user, array $filters = []): Collection
    {
        $user = $this->userRepository->loadUserRequestsWithResponses($user, ['user' => $user]);

        $delivery = collect();
        $send = collect();

        // Apply request type filter
        if ($filters['filter'] !== 'delivery') {
            $send = $this->processRequestsWithResponses($user->sendRequests, $user, 'send');
        }

        if ($filters['filter'] !== 'send') {
            $delivery = $this->processRequestsWithResponses($user->deliveryRequests, $user, 'delivery');
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        // Apply additional filters
        if ($filters['status']) {
            $requests = $this->applyStatusFilter($requests, $filters['status']);
        }

        if ($filters['search']) {
            $requests = $this->applySearchFilter($requests, $filters['search']);
        }

        return $requests;
    }

    public function getUserRequestById(User $user, int $id, array $filters = []): Collection
    {
        $user = $this->userRepository->loadUserRequestsWithResponses($user, ['user' => $user]);

        $delivery = collect();
        $send = collect();

        if ($filters['filter'] !== 'delivery') {
            $send = $this->processRequestsWithResponses($user->sendRequests->where('id', $id), $user, 'send');
        }

        if ($filters['filter'] !== 'send') {
            $delivery = $this->processRequestsWithResponses($user->deliveryRequests->where('id', $id), $user, 'delivery');
        }

        return $delivery->concat($send)->sortByDesc('created_at')->values();
    }

    public function getOtherUserRequests(User $targetUser, User $currentUser, array $filters = []): Collection
    {
        $targetUser = $this->userRepository->loadUserRequestsWithResponses($targetUser, ['user' => $targetUser]);

        $delivery = collect();
        $send = collect();

        if ($filters['filter'] !== 'delivery') {
            $send = $this->processRequestsWithResponses($targetUser->sendRequests, $currentUser, 'send');
        }

        if ($filters['filter'] !== 'send') {
            $delivery = $this->processRequestsWithResponses($targetUser->deliveryRequests, $currentUser, 'delivery');
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        // Apply filters
        if ($filters['status']) {
            $requests = $this->applyStatusFilter($requests, $filters['status']);
        }

        if ($filters['search']) {
            $requests = $this->applySearchFilter($requests, $filters['search']);
        }

        return $requests;
    }

    private function processRequestsWithResponses($requests, $currentUser, $type): Collection
    {
        $processedRequests = collect();

        foreach ($requests as $request) {
            $statusFilter = ['accepted', 'partial', 'pending'];

            // If request is closed or completed, also include closed responses
            if (in_array($request->status, ['closed', 'completed'])) {
                $statusFilter[] = 'closed';
            }

            // Merge both matching responses and manual responses
            // For send requests, also check offerResponses (when send request is offered to delivery requests)
            $responsesList = $request->responses ?? collect();
            $manualResponsesList = $request->manualResponses ?? collect();
            $offerResponsesList = $request->offerResponses ?? collect();

            $allResponses = $responsesList->merge($manualResponsesList)->merge($offerResponsesList); // Remove duplicates by ID

            // Filter responses where current user is involved
            $relevantResponses = $allResponses->filter(function($response) use ($currentUser, $statusFilter) {
                return in_array($response->overall_status, $statusFilter) &&
                       ($response->user_id == $currentUser->id || $response->responder_id == $currentUser->id) &&
                       $response->responder_id != null;
            });

            if ($relevantResponses->isNotEmpty()) {
                // Create separate item for each response
                foreach ($relevantResponses as $response) {
                    $requestCopy = clone $request;
                    $requestCopy = $this->enrichRequestWithResponseData($requestCopy, $response, $currentUser, $type);
                    $processedRequests->push($requestCopy);
                }
            } else {
                // No responses, show original request
                $request->type = $type;
                $request->chat_id = null;
                $request->response_id = null;
                $request->response_status = null;
                $request->response_type = null;
                $request->responder_user = null;
                $request->has_reviewed = $this->hasUserReviewedOtherParty($currentUser, $request);
                $processedRequests->push($request);
            }
        }

        return $processedRequests;
    }

    private function enrichRequestWithResponseData($request, $response, $currentUser, $type)
    {
        $request->type = $type;
        $request->response_id = $response->id;
        $request->chat_id = $response->chat_id;
        $request->response_status = $response->overall_status;
        $request->response_type = $response->response_type;
        $request->user_role = $response->getUserRole($currentUser->id);
        $request->user_status = $response->getUserStatus($currentUser->id);

        // Set responder user as the "other party" from current user's perspective
        $otherUser = null;
        if ($response->user_id === $currentUser->id) {
            // Current user is the user (deliverer), other party is responder (sender)
            $otherUser = $response->responder;
        } elseif ($response->responder_id === $currentUser->id) {
            // Current user is the responder (sender), other party is user (deliverer)
            $otherUser = $response->user;
        }

        if ($otherUser) {
            $otherUser->closed_send_requests_count = $otherUser->sendRequests()->where('status', 'closed')->count();
            $otherUser->closed_delivery_requests_count = $otherUser->deliveryRequests()->where('status', 'closed')->count();
            $request->responder_user = $otherUser;
        } else {
            $request->responder_user = null;
        }

        // Set status based on response
        $request->status = $this->determineRequestStatus($request, $response);
        $request->has_reviewed = $this->hasUserReviewedOtherParty($currentUser, $request);

        return $request;
    }

    private function determineRequestStatus($request, $response): string
    {
        // For closed/completed requests, keep the original status
        if (in_array($request->status, ['closed', 'completed'])) {
            return $request->status;
        }

        $overallStatus = $response->overall_status;

        // Only override status for final states
        return match ($overallStatus) {
            'accepted' => 'matched',
            'closed' => 'closed',
            default => $request->status, // Respect the actual database status
        };
    }

    private function applyStatusFilter($requests, string $status): Collection
    {
        return match ($status) {
            'active' => $requests->filter(fn($request) =>
                in_array($request->status, ['open', 'has_responses', 'matched', 'matched_manually'])
            ),
            'closed' => $requests->filter(fn($request) =>
                in_array($request->status, ['completed', 'closed'])
            ),
            default => $requests,
        };
    }

    private function applySearchFilter($requests, string $search): Collection
    {
        $searchLower = strtolower($search);

        return $requests->filter(function($request) use ($searchLower) {
            // Search in locations
            if (stripos($request->from_location, $searchLower) !== false ||
                stripos($request->to_location, $searchLower) !== false) {
                return true;
            }

            // Search in description
            if ($request->description && stripos($request->description, $searchLower) !== false) {
                return true;
            }

            // Search in user name
            if (isset($request->user) && $request->user->name &&
                stripos($request->user->name, $searchLower) !== false) {
                return true;
            }

            // Search in responder name
            if (isset($request->responder_user) && $request->responder_user->name &&
                stripos($request->responder_user->name, $searchLower) !== false) {
                return true;
            }

            return false;
        });
    }

    private function hasUserReviewedOtherParty($currentUser, $request): bool
    {
        // Only check for closed/completed requests
        if (!in_array($request->status, ['completed', 'closed'])) {
            return false;
        }

        $otherUserId = $request->responder_user->id ?? null;

        if (!$otherUserId) {
            return false;
        }

        // Check if current user has reviewed the other party for this specific request
        return Review::where('user_id', $otherUserId)
                    ->where('owner_id', $currentUser->id)
                    ->where('request_id', $request->id)
                    ->where('request_type', $request->type)
                    ->exists();
    }
}
