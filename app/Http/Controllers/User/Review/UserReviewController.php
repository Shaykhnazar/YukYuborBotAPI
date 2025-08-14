<?php

namespace App\Http\Controllers\User\Review;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Review\CreateReviewRequest;
use App\Http\Resources\Review\ReviewResource;
use App\Models\Review;
use App\Services\TelegramUserService;
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

        // Check if user already reviewed this specific request
        $existingReview = Review::where('user_id', $dto->userId)
            ->where('owner_id', $owner->id)
            ->where('request_id', $dto->requestId)
            ->where('request_type', $dto->requestType)
            ->first();

        if ($existingReview) {
            return response()->json(['error' => 'You have already reviewed this transaction'], 409);
        }

        // Verify the user was actually involved in this request
        $this->validateUserInvolvement($owner, $dto);

        $review = new Review([
            'user_id' => $dto->userId,
            'owner_id' => $owner->id,
            'text' => $dto->text,
            'rating' => $dto->rating,
            'request_id' => $dto->requestId,
            'request_type' => $dto->requestType,
        ]);

        $review->save();
        $review->load(['owner.telegramUser']);

        return response()->json(new ReviewResource($review));
    }

    private function validateUserInvolvement($owner, $dto): void
    {
        if ($dto->requestType === 'send') {
            $request = \App\Models\SendRequest::find($dto->requestId);
            if (!$request) {
                throw new \Exception('Send request not found');
            }

            // Check if user is involved in this request (either as sender or has accepted responses)
            $isInvolved = $request->user_id === $owner->id ||
                         \App\Models\Response::where('request_type', 'send')
                                            ->where('offer_id', $dto->requestId)
                                            ->where('user_id', $owner->id)
                                            ->where('status', 'accepted')
                                            ->exists();
        } else {
            $request = \App\Models\DeliveryRequest::find($dto->requestId);
            if (!$request) {
                throw new \Exception('Delivery request not found');
            }

            // Check if user is involved in this request (either as deliverer or has accepted responses)
            $isInvolved = $request->user_id === $owner->id ||
                         \App\Models\Response::where('request_type', 'delivery')
                                            ->where('offer_id', $dto->requestId)
                                            ->where('user_id', $owner->id)
                                            ->where('status', 'accepted')
                                            ->exists();
        }

        if (!$isInvolved) {
            throw new \Exception('You cannot review this transaction');
        }
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
