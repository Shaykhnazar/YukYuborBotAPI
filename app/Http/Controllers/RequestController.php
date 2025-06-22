<?php

namespace App\Http\Controllers;

use App\Http\Requests\Parcel\ParcelRequest;
use App\Models\DeliveryRequest;
use App\Models\SendRequest;
use App\Http\Resources\Parcel\IndexRequestResource;
use App\Service\TelegramUserService;

class RequestController extends Controller
{
    public function __construct(
        protected TelegramUserService $userService
    ) {}

    public function index(ParcelRequest $request)
    {
        // Get current user to exclude their requests
        $currentUser = $this->userService->getUserByTelegramId($request);

        if (!$currentUser) {
            return response()->json(['error' => 'User not found'], 401);
        }

        $filter = $request->getFilter();
        $delivery = collect();
        $send = collect();

        if ($filter !== 'send') {
            $delivery = DeliveryRequest::with('user.telegramUser')
                ->whereIn('status', ['open', 'has_responses'])
                ->where('user_id', '!=', $currentUser->id) // Exclude current user's requests
                ->get()
                ->map(function ($item) {
                    $item->type = 'delivery';
                    return $item;
                });
        }

        if ($filter !== 'delivery') {
            $send = SendRequest::with('user.telegramUser')
                ->whereIn('status', ['open', 'has_responses'])
                ->where('user_id', '!=', $currentUser->id) // Exclude current user's requests
                ->get()
                ->map(function ($item) {
                    $item->type = 'send';
                    return $item;
                });
        }

        $requests = $delivery->concat($send)->sortByDesc('created_at')->values();

        return IndexRequestResource::collection($requests);
    }
}
