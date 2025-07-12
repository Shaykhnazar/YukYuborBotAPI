<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Resources\Review\ReviewResource;
use App\Models\User;
use App\Service\TelegramUserService;
use Carbon\Carbon;
use Carbon\Translator;
use Illuminate\Http\Request;

class UserController extends BaseController
{
    private string $customLocale = 'ru_custom';

    public function __construct(
        protected TelegramUserService $tgService,
    ) {
        Translator::get($this->customLocale)->setTranslations([
            'ago' => 'С нами уже :time',
        ]);
    }

    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $this->tgService->getUserByTelegramId($request);
        $user['with_us'] = Carbon::parse($user['created_at'])->locale($this->customLocale)->diffForHumans();

        // Calculate completed/closed requests count
        $completedSendRequests = $user->sendRequests()
            ->whereIn('status', ['completed', 'closed'])
            ->count();

        $completedDeliveryRequests = $user->deliveryRequests()
            ->whereIn('status', ['completed', 'closed'])
            ->count();

        $totalCompletedRequests = $completedSendRequests + $completedDeliveryRequests;

        $averageRating = $user->reviews->avg('rating');

        return response()->json([
            'telegram' => $user->telegramUser,
            'user' => collect($user)->except('telegram_user')->merge([
                'completed_requests_count' => $totalCompletedRequests
            ]),
            'reviews' => ReviewResource::collection($user->reviews),
            'average_rating' => round($averageRating, 2),
        ]);
    }

    public function show(Request $request, User $user): \Illuminate\Http\JsonResponse
    {
        $averageRating = $user->reviews->avg('rating');
        $user['with_us'] = Carbon::parse($user['created_at'])->locale($this->customLocale)->diffForHumans();

        // Calculate completed/closed requests count
        $completedSendRequests = $user->sendRequests()
            ->whereIn('status', ['completed', 'closed'])
            ->count();

        $completedDeliveryRequests = $user->deliveryRequests()
            ->whereIn('status', ['completed', 'closed'])
            ->count();

        $totalCompletedRequests = $completedSendRequests + $completedDeliveryRequests;

        return response()->json([
            'telegram' => $user->telegramUser,
            'user' => collect($user)->except('telegram_user')->merge([
                'completed_requests_count' => $totalCompletedRequests
            ]),
            'reviews' => ReviewResource::collection($user->reviews),
            'average_rating' => round($averageRating, 2),
        ]);
    }
}
