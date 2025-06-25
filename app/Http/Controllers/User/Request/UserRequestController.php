<?php

namespace App\Http\Controllers\User\Request;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Parcel\ParcelRequest;
use App\Http\Resources\Parcel\IndexRequestResource;
use App\Models\Review;
use App\Service\TelegramUserService;
use App\Models\User;
use App\Models\Chat;

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

        // Eager load relationships including chat through responses
        $user->load([
            'sendRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'sendRequests.responses.chat',
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'deliveryRequests.responses.chat'
        ]);

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->map(function ($item) use ($user) {
                $item->type = 'send';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if user has reviewed the other party
                $item->has_reviewed = $this->hasUserReviewedOtherParty($user, $item);
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->map(function ($item) use ($user) {
                $item->type = 'delivery';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if user has reviewed the other party
                $item->has_reviewed = $this->hasUserReviewedOtherParty($user, $item);
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

        // Eager load relationships including chat through responses
        $user->load([
            'sendRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'sendRequests.responses.chat',
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'deliveryRequests.responses.chat'
        ]);

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->where('id', $id)->map(function ($item) use ($user) {
                $item->type = 'send';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if user has reviewed the other party
                $item->has_reviewed = $this->hasUserReviewedOtherParty($user, $item);
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->where('id', $id)->map(function ($item) use ($user) {
                $item->type = 'delivery';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if user has reviewed the other party
                $item->has_reviewed = $this->hasUserReviewedOtherParty($user, $item);
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

        // Eager load relationships including chat through responses
        $user->load([
            'sendRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'sendRequests.responses.chat',
            'deliveryRequests.responses' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                      ->whereNotNull('chat_id')
                      ->whereIn('status', ['accepted', 'waiting']);
            },
            'deliveryRequests.responses.chat'
        ]);

        $delivery = collect();
        $send = collect();

        if ($filter !== 'delivery') {
            $send = $user->sendRequests->map(function ($item) use ($currentUser) {
                $item->type = 'send';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if current user has reviewed the other party
                $item->has_reviewed = $this->hasUserReviewedOtherParty($currentUser, $item);
                return $item;
            });
        }

        if ($filter !== 'send') {
            $delivery = $user->deliveryRequests->map(function ($item) use ($currentUser) {
                $item->type = 'delivery';
                // Get chat_id from the loaded relationship
                $item->chat_id = $item->responses->first()?->chat_id;
                // Check if current user has reviewed the other party
                $item->has_reviewed = $this->hasUserReviewedOtherParty($currentUser, $item);
                return $item;
            });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        return IndexRequestResource::collection($requests);
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
}
