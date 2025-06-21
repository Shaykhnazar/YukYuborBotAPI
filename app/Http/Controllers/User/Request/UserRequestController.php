<?php

namespace App\Http\Controllers\User\Request;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Parcel\ParcelRequest;
use App\Http\Resources\Parcel\IndexRequestResource;
use App\Service\TelegramUserService;
use App\Models\User;

class UserRequestController extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    )
    {}

    public function index(ParcelRequest $request)
    {
        $filter = $request->getFilter();
        $user = $this->tgService->getUserByTelegramId($request);

        // Eager load relationships including chat through responses and reviews
        $user->load([
            'sendRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'sendRequests.responses.chat',
            'sendRequests' => function ($query) use ($user) {
                $query->with(['user.reviews' => function ($reviewQuery) use ($user) {
                    $reviewQuery->where('owner_id', $user->id);
                }]);
            },
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'deliveryRequests.responses.chat',
            'deliveryRequests' => function ($query) use ($user) {
                $query->with(['user.reviews' => function ($reviewQuery) use ($user) {
                    $reviewQuery->where('owner_id', $user->id);
                }]);
            }
        ]);

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->map(function ($item) use ($user) {
                $item->type = 'send';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if user has already reviewed this request's user
                $item->has_reviewed = $item->user->reviews->where('owner_id', $user->id)->isNotEmpty();
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->map(function ($item) use ($user) {
                $item->type = 'delivery';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if user has already reviewed this request's user
                $item->has_reviewed = $item->user->reviews->where('owner_id', $user->id)->isNotEmpty();
                return $item;
            });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        return IndexRequestResource::collection($requests);
    }

    public function show(ParcelRequest $request, int $id)
    {
        $filter = $request->getFilter();
        $user = $this->tgService->getUserByTelegramId($request);

        // Eager load relationships including chat through responses and reviews
        $user->load([
            'sendRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'sendRequests.responses.chat',
            'sendRequests' => function ($query) use ($user) {
                $query->with(['user.reviews' => function ($reviewQuery) use ($user) {
                    $reviewQuery->where('owner_id', $user->id);
                }]);
            },
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'deliveryRequests.responses.chat',
            'deliveryRequests' => function ($query) use ($user) {
                $query->with(['user.reviews' => function ($reviewQuery) use ($user) {
                    $reviewQuery->where('owner_id', $user->id);
                }]);
            }
        ]);

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->where('id', $id)->map(function ($item) use ($user) {
                $item->type = 'send';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if user has already reviewed this request's user
                $item->has_reviewed = $item->user->reviews->where('owner_id', $user->id)->isNotEmpty();
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->where('id', $id)->map(function ($item) use ($user) {
                $item->type = 'delivery';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if user has already reviewed this request's user
                $item->has_reviewed = $item->user->reviews->where('owner_id', $user->id)->isNotEmpty();
                return $item;
            });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        return IndexRequestResource::collection($requests);
    }

    public function userRequests(ParcelRequest $request, User $user)
    {
        $filter = $request->getFilter();
        $currentUser = $this->tgService->getUserByTelegramId($request);

        // Eager load relationships including chat through responses and reviews
        $user->load([
            'sendRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'sendRequests.responses.chat',
            'sendRequests' => function ($query) use ($currentUser) {
                $query->with(['user.reviews' => function ($reviewQuery) use ($currentUser) {
                    $reviewQuery->where('owner_id', $currentUser->id);
                }]);
            },
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'deliveryRequests.responses.chat',
            'deliveryRequests' => function ($query) use ($currentUser) {
                $query->with(['user.reviews' => function ($reviewQuery) use ($currentUser) {
                    $reviewQuery->where('owner_id', $currentUser->id);
                }]);
            }
        ]);

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->map(function ($item) use ($currentUser) {
                $item->type = 'send';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if current user has already reviewed this request's user
                $item->has_reviewed = $item->user->reviews->where('owner_id', $currentUser->id)->isNotEmpty();
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->map(function ($item) use ($currentUser) {
                $item->type = 'delivery';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if current user has already reviewed this request's user
                $item->has_reviewed = $item->user->reviews->where('owner_id', $currentUser->id)->isNotEmpty();
                return $item;
            });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        return IndexRequestResource::collection($requests);
    }
}
