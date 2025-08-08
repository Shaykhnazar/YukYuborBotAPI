<?php

namespace App\Http\Controllers\User\Request;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Parcel\ParcelRequest;
use App\Http\Resources\Parcel\IndexRequestResource;
use App\Models\Review;
use App\Service\TelegramUserService;
use App\Models\User;
use App\Models\Chat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserRequestController extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    )
    {}

    public function index(ParcelRequest $request)
    {
        $filters = $request->getFilters();
        $user = $this->tgService->getUserByTelegramId($request);

        Log::info('Request filters', $filters);

        // Load both matching and manual responses where user is either the request owner or responder
        $user->load([
            'sendRequests.responses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id);
                });
            },
            'sendRequests.manualResponses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'sendRequests.responses.chat',
            'sendRequests.responses.responder.telegramUser',
            'sendRequests.responses.user.telegramUser',
            'sendRequests.manualResponses.chat',
            'sendRequests.manualResponses.responder.telegramUser',
            'sendRequests.manualResponses.user.telegramUser',
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id);
                });
            },
            'deliveryRequests.manualResponses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'deliveryRequests.responses.chat',
            'deliveryRequests.responses.responder.telegramUser',
            'deliveryRequests.responses.user.telegramUser',
            'deliveryRequests.manualResponses.chat',
            'deliveryRequests.manualResponses.responder.telegramUser',
            'deliveryRequests.manualResponses.user.telegramUser'
        ]);

        $delivery = collect();
        $send = collect();

        // Apply request type filter
        if ($filters['filter'] !== 'delivery') {
            Log::info('Processing send requests');
            $send = $this->processRequestsWithResponses($user->sendRequests, $user, 'send');
        }

        if ($filters['filter'] !== 'send') {
            Log::info('Processing delivery requests');
            $delivery = $this->processRequestsWithResponses($user->deliveryRequests, $user, 'delivery');
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        // Apply status filter
        if ($filters['status']) {
            $requests = $this->applyStatusFilter($requests, $filters['status']);
        }

        // Apply search filter
        if ($filters['search']) {
            $requests = $this->applySearchFilter($requests, $filters['search']);
        }

        Log::info('Final filtered requests count', ['count' => $requests->count()]);

        return IndexRequestResource::collection($requests);
    }

    public function show(ParcelRequest $request, int $id)
    {
        $filters = $request->getFilters();
        $user = $this->tgService->getUserByTelegramId($request);

        // Eager load relationships including responder data
        $user->load([
            'sendRequests.responses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'sendRequests.manualResponses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'sendRequests.responses.chat',
            'sendRequests.responses.responder.telegramUser',
            'sendRequests.responses.user.telegramUser',
            'sendRequests.manualResponses.chat',
            'sendRequests.manualResponses.responder.telegramUser',
            'sendRequests.manualResponses.user.telegramUser',
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'deliveryRequests.manualResponses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'deliveryRequests.responses.chat',
            'deliveryRequests.responses.responder.telegramUser',
            'deliveryRequests.responses.user.telegramUser',
            'deliveryRequests.manualResponses.chat',
            'deliveryRequests.manualResponses.responder.telegramUser',
            'deliveryRequests.manualResponses.user.telegramUser'
        ]);

        $delivery = collect();
        $send = collect();

        if ($filters['filter'] !== 'delivery') {
            $send = $this->processRequestsWithResponses($user->sendRequests->where('id', $id), $user, 'send');
        }

        if ($filters['filter'] !== 'send') {
            $delivery = $this->processRequestsWithResponses($user->deliveryRequests->where('id', $id), $user, 'delivery');
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        return IndexRequestResource::collection($requests);
    }

    public function userRequests(ParcelRequest $request, User $user)
    {
        $filters = $request->getFilters();
        $currentUser = $this->tgService->getUserByTelegramId($request);

        // Eager load relationships including responder data
        $user->load([
            'sendRequests.responses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'sendRequests.manualResponses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'sendRequests.responses.chat',
            'sendRequests.responses.responder.telegramUser',
            'sendRequests.responses.user.telegramUser',
            'sendRequests.manualResponses.chat',
            'sendRequests.manualResponses.responder.telegramUser',
            'sendRequests.manualResponses.user.telegramUser',
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'deliveryRequests.manualResponses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'deliveryRequests.responses.chat',
            'deliveryRequests.responses.responder.telegramUser',
            'deliveryRequests.responses.user.telegramUser',
            'deliveryRequests.manualResponses.chat',
            'deliveryRequests.manualResponses.responder.telegramUser',
            'deliveryRequests.manualResponses.user.telegramUser'
        ]);

        $delivery = collect();
        $send = collect();

        if ($filters['filter'] !== 'delivery') {
            $send = $this->processRequestsWithResponses($user->sendRequests, $currentUser, 'send');
        }

        if ($filters['filter'] !== 'send') {
            $delivery = $this->processRequestsWithResponses($user->deliveryRequests, $currentUser, 'delivery');
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        // Apply status filter
        if ($filters['status']) {
            $requests = $this->applyStatusFilter($requests, $filters['status']);
        }

        // Apply search filter
        if ($filters['search']) {
            $requests = $this->applySearchFilter($requests, $filters['search']);
        }

        return IndexRequestResource::collection($requests);
    }

    /**
     * Apply status filter to requests
     */
    private function applyStatusFilter($requests, string $status)
    {
        return match ($status) {
            'active' => $requests->filter(function ($request) {
                return in_array($request->status, ['open', 'has_responses', 'matched', 'matched_manually']);
            }),
            'closed' => $requests->filter(function ($request) {
                return in_array($request->status, ['completed', 'closed']);
            }),
            default => $requests,
        };
    }

    /**
     * Apply search filter to requests
     */
    private function applySearchFilter($requests, string $search)
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

            // Search in user name (for the request owner)
            if (isset($request->user) && $request->user->name &&
                stripos($request->user->name, $searchLower) !== false) {
                return true;
            }

            // Search in responder name (if applicable)
            if (isset($request->responder_user) && $request->responder_user->name &&
                stripos($request->responder_user->name, $searchLower) !== false) {
                return true;
            }

            return false;
        });
    }

    /**
     * Process requests and create separate items for each response
     */
    private function processRequestsWithResponses($requests, $currentUser, $type)
    {
        $processedRequests = collect();

        Log::info('Processing requests', ['count' => $requests->count(), 'type' => $type, 'requests' => $requests]);
        foreach ($requests as $request) {
            // FIX: For closed requests, include 'closed' status in the filter
            $statusFilter = ['accepted', 'waiting', 'pending', 'responded'];

            // If request is closed or completed, also include closed responses
            if (in_array($request->status, ['closed', 'completed'])) {
                $statusFilter[] = 'closed';
            }

            // Merge both matching responses and manual responses
            $allResponses = $request->responses->merge($request->manualResponses ?? collect());

            // Filter responses where current user is involved (either as request owner or responder)
            $relevantResponses = $allResponses->filter(function($response) use ($currentUser, $statusFilter) {
                return in_array($response->status, $statusFilter) &&
                       ($response->user_id == $currentUser->id || $response->responder_id == $currentUser->id) &&
                       $response->responder_id != null;
            });

            if ($relevantResponses->isNotEmpty()) {
                // Create separate item for each response
                foreach ($relevantResponses as $response) {
                    $requestCopy = clone $request;
                    $requestCopy->type = $type;
                    $requestCopy->response_id = $response->id;
                    $requestCopy->chat_id = $response->chat_id;
                    $requestCopy->response_status = $response->status;
                    $requestCopy->response_type = $response->response_type;
                    $requestCopy->responder_user = $response->responder; // This is the other party
                    // Keep original user relation - it should show the request owner (current user)

                    // FIX: Check if original request is closed or completed first
                    if (in_array($request->status, ['closed', 'completed'])) {
                        $requestCopy->status = $request->status; // Keep original status
                    } else if ($response->status === 'closed') {
                        $requestCopy->status = 'closed'; // Keep closed status from response
                    } else if ($response->status === 'accepted' || $response->status === 'waiting') {
                        // If chat exists, both parties have confirmed - show final status
                        if ($response->chat_id) {
                            $requestCopy->status = 'matched'; // "Принят отклик от пользователя"
                        } else {
                            $requestCopy->status = 'has_responses'; // "Получен отклик"
                        }
                    } else if ($response->status === 'pending') {
                        $requestCopy->status = 'has_responses'; // "Получен отклик"
                    }

                    $requestCopy->has_reviewed = $this->hasUserReviewedOtherParty($currentUser, $requestCopy);
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

    /**
     * Check if the current user has reviewed the other party involved in this specific request
     */
    private function hasUserReviewedOtherParty($currentUser, $request): bool
    {
        // Only check for closed/completed requests
        if (!in_array($request->status, ['completed', 'closed'])) {
            return false;
        }

        // Get the other user ID from the request object
        $otherUserId = $request->responder_user->id ?? null;

        if (!$otherUserId) {
            Log::info('No other user found in request object, returning false');
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
