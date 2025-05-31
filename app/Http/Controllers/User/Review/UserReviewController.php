<?php

namespace App\Http\Controllers\User\Review;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Review;
use App\Service\TelegramUserService;
use App\Http\Requests\Review\CreateReviewRequest;
use Illuminate\Http\JsonResponse;

class UserReviewController extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    ) {}

    public function create(CreateReviewRequest $request): JsonResponse
    {
        $dto = $request->getDTO();
        $owner = $this->tgService->getUserByTelegramId($request);
        $review = new Review(
            [
                'user_id' => $dto->userId,
                'owner_id' => $owner->id,
                'text' => $dto->text,
                'rating' => $dto->rating
            ]
        );
        $review->save();

        return response()->json($review);
    }

    public function show(int $id): JsonResponse
    {
        $review = Review::findOrFail($id);
        return response()->json($review);
    }

    public function userReviews(int $userId): JsonResponse
    {
        $reviews = Review::where('user_id', $userId)->get();
        return response()->json($reviews);
    }
}
