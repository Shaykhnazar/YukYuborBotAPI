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

        // Load responses where user is either the request owner or responder
        $user->load([
            'sendRequests.responses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'sendRequests.responses.chat',
            'sendRequests.responses.responder.telegramUser',
            'sendRequests.responses.user.telegramUser',
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'deliveryRequests.responses.chat',
            'deliveryRequests.responses.responder.telegramUser',
            'deliveryRequests.responses.user.telegramUser'
        ]);

        // ADD THIS DEBUG CODE
//
//// Check ALL responses in the database
//        $allResponses = \App\Models\Response::all();
//        Log::info('ALL responses in database', [
//            'total_count' => $allResponses->count(),
//            'responses' => $allResponses->map(function($resp) {
//                return [
//                    'id' => $resp->id,
//                    'user_id' => $resp->user_id,
//                    'responder_id' => $resp->responder_id,
//                    'request_type' => $resp->request_type,
//                    'request_id' => $resp->request_id,
//                    'offer_id' => $resp->offer_id,
//                    'status' => $resp->status
//                ];
//            })
//        ]);
//
//// Check responses where user 3 is involved (either as user or responder)
//        $userResponses = \App\Models\Response::where('user_id', $user->id)
//            ->orWhere('responder_id', $user->id)
//            ->get();
//
//        Log::info('Responses involving current user', [
//            'user_id' => $user->id,
//            'count' => $userResponses->count(),
//            'responses' => $userResponses->map(function($resp) {
//                return [
//                    'id' => $resp->id,
//                    'user_id' => $resp->user_id,
//                    'responder_id' => $resp->responder_id,
//                    'request_type' => $resp->request_type,
//                    'request_id' => $resp->request_id,
//                    'offer_id' => $resp->offer_id,
//                    'status' => $resp->status
//                ];
//            })
//        ]);
//
//// Check if there are responses where request_id = 2 but request_type = 'send'
//        $mixedResponses = \App\Models\Response::where('request_id', 2)->get();
//        Log::info('All responses with request_id = 2 (any type)', [
//            'responses' => $mixedResponses->map(function($resp) {
//                return [
//                    'id' => $resp->id,
//                    'user_id' => $resp->user_id,
//                    'responder_id' => $resp->responder_id,
//                    'request_type' => $resp->request_type,
//                    'request_id' => $resp->request_id,
//                    'offer_id' => $resp->offer_id,
//                    'status' => $resp->status
//                ];
//            })
//        ]);
//
//// Check responses where offer_id = 2
//        $offerResponses = \App\Models\Response::where('offer_id', 2)->get();
//        Log::info('All responses with offer_id = 2', [
//            'responses' => $offerResponses->map(function($resp) {
//                return [
//                    'id' => $resp->id,
//                    'user_id' => $resp->user_id,
//                    'responder_id' => $resp->responder_id,
//                    'request_type' => $resp->request_type,
//                    'request_id' => $resp->request_id,
//                    'offer_id' => $resp->offer_id,
//                    'status' => $resp->status
//                ];
//            })
//        ]);

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
            'sendRequests.responses.chat',
            'sendRequests.responses.responder.telegramUser',
            'sendRequests.responses.user.telegramUser',
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'deliveryRequests.responses.chat',
            'deliveryRequests.responses.responder.telegramUser',
            'deliveryRequests.responses.user.telegramUser'
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
            'sendRequests.responses.chat',
            'sendRequests.responses.responder.telegramUser',
            'sendRequests.responses.user.telegramUser',
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where(function($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('responder_id', $user->id);
                });
            },
            'deliveryRequests.responses.chat',
            'deliveryRequests.responses.responder.telegramUser',
            'deliveryRequests.responses.user.telegramUser'
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
                return in_array($request->status, ['open', 'has_responses', 'matched']);
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
            $statusFilter = ['accepted', 'waiting', 'pending'];

            // If request is closed, also include closed responses
            if ($request->status === 'closed') {
                $statusFilter[] = 'closed';
            }

            // Filter responses where current user is the request owner (should see responders)
            $relevantResponses = $request->responses->filter(function($response) use ($currentUser, $statusFilter) {
                return in_array($response->status, $statusFilter) &&
                       $response->user_id == $currentUser->id && // Current user is the request owner
                       $response->responder_id != null &&
                       $response->responder_id != $currentUser->id; // Don't show self as responder
            });

            if ($relevantResponses->isNotEmpty()) {
                // Create separate item for each response
                foreach ($relevantResponses as $response) {
                    $requestCopy = clone $request;
                    $requestCopy->type = $type;
                    $requestCopy->response_id = $response->id;
                    $requestCopy->chat_id = $response->chat_id;
                    $requestCopy->response_status = $response->status;
                    $requestCopy->responder_user = $response->responder; // This is the other party
                    $requestCopy->setRelation('user', $response->responder); // FIX: Override user relation to show other party

                    // FIX: Check if original request is closed first
                    if ($request->status === 'closed') {
                        $requestCopy->status = 'closed'; // Keep closed status
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

                    $requestCopy->has_reviewed = $this->hasUserReviewedOtherParty($currentUser, $request);
                    $processedRequests->push($requestCopy);
                }
            } else {
                // No responses, show original request
                $request->type = $type;
                $request->chat_id = null;
                $request->response_id = null;
                $request->responder_user = null;
                $request->has_reviewed = false;
                $processedRequests->push($request);
            }
        }

        return $processedRequests;
    }

    /**
     * Check if the current user has reviewed the other party involved in this request
     */
    private function hasUserReviewedOtherParty($currentUser, $request): bool
    {
        // Only check for closed/completed requests
        if (!in_array($request->status, ['completed', 'closed'])) {
            return false;
        }

        // Find the chat associated with this request
        $chat = null;

        if ($request->type === 'send') {
            $chat = Chat::where('send_request_id', $request->id)
                       ->where(function($query) use ($currentUser) {
                           $query->where('sender_id', $currentUser->id)
                                 ->orWhere('receiver_id', $currentUser->id);
                       })
                       ->first();
        } else {
            $chat = Chat::where('delivery_request_id', $request->id)
                       ->where(function($query) use ($currentUser) {
                           $query->where('sender_id', $currentUser->id)
                                 ->orWhere('receiver_id', $currentUser->id);
                       })
                       ->first();
        }

        if (!$chat) {
            return false;
        }

        // Determine the other party
        $otherUserId = ($chat->sender_id === $currentUser->id)
                      ? $chat->receiver_id
                      : $chat->sender_id;

        // Check if current user has reviewed the other party
        return Review::where('user_id', $otherUserId)
                                ->where('owner_id', $currentUser->id)
                                ->exists();
    }

    public function debug(Request $request)
    {
        $user = $this->tgService->getUserByTelegramId($request);

        $debug = [
            'user_id' => $user->id,
            'send_requests' => $user->sendRequests()->with('responses.responder')->get(),
            'delivery_requests' => $user->deliveryRequests()->with('responses.responder')->get(),
            'all_responses' => \App\Models\Response::with(['user', 'responder'])->get(),
            'all_chats' => \App\Models\Chat::all(),
        ];

        return response()->json($debug);
    }
}
