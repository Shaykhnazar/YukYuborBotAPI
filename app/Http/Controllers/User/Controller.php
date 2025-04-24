<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\Review\IndexResource;
use App\Models\User;
use App\Service\TelegramUserService;
use Illuminate\Http\Request;

class Controller extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    ) {}

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);
        $averageRating = $user->reviews->avg('rating');
        return response()->json([
            'telegram' => $user->telegramUser,
            'user' => collect($user)->except('telegram_user'),
            'reviews' => IndexResource::collection($user->reviews),
            'average_rating' => round($averageRating, 2),
        ]);
    }

    public function show(Request $request, User $user): \Illuminate\Http\JsonResponse
    {
        $averageRating = $user->reviews->avg('rating');

        return response()->json([
            'telegram' => $user->telegramUser,
            'user' => collect($user)->except('telegram_user'),
            'reviews' => IndexResource::collection($user->reviews),
            'average_rating' => round($averageRating, 2),
        ]);
    }
}
