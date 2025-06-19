<?php

namespace App\Http\Controllers\User\Review;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Review;
use App\Service\TelegramUserService;
use App\Http\Requests\Review\CreateReviewRequest;
use App\Http\Resources\Review\ReviewResource;
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

        // Check if user already has a review from this owner
        $existingReview = Review::where('user_id', $dto->userId)
            ->where('owner_id', $owner->id)
            ->first();

        if ($existingReview) {
            return response()->json(['error' => 'You have already reviewed this user'], 409);
        }

        $review = new Review([
            'user_id' => $dto->userId,
            'owner_id' => $owner->id,
            'text' => $dto->text,
            'rating' => $dto->rating
        ]);
        $review->save();

        // Load relationships for response
        $review->load(['owner.telegramUser']);

        return response()->json(new ReviewResource($review));
    }

    public function show(int $id): JsonResponse
    {
        $review = Review::with(['owner.telegramUser'])->findOrFail($id);
        return response()->json(new ReviewResource($review));
    }

    public function userReviews(int $userId): JsonResponse
    {
        $reviews = Review::where('user_id', $userId)
            ->with(['owner.telegramUser'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(ReviewResource::collection($reviews));
    }
}
