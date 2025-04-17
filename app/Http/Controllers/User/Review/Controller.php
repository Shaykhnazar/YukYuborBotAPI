<?php

namespace App\Http\Controllers\User\Review;

use App\Http\Controllers\Controller as BaseController;
use App\Models\Review;
use App\Service\TelegramUserService;
use App\Http\Requests\Review\CreateRequest;

class Controller extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    ) {}

    public function create(CreateRequest $request): \Illuminate\Http\JsonResponse
    {
        $dto = $request->getDTO();
        $owner = $this->tgService->getUserByTelegramId($request);
        $review = new Review(
            [
                'user_id' => $dto->userId,
                'owner_id' => $owner->$this->userService->getUserByTelegramId($request)->id,
                'text' => $dto->text,
                'rating' => $dto->rating
            ]
        );
        $review->save();

        return response()->json($review);
    }
}
