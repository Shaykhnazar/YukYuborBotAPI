<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Send\CreateSendRequest;
use App\Models\SendRequest;
use App\Service\Matcher;
use App\Service\TelegramUserService;
use Carbon\CarbonImmutable;

class SendRequestController extends BaseController
{
    public function __construct(
        protected TelegramUserService $userService,
        protected Matcher $matcher
    )
    {
    }

    public function create(CreateSendRequest $request)
    {
        $dto = $request->getDTO();
        $sendReq = new SendRequest(
            [
                'from_location' => $dto->fromLoc,
                'to_location' => $dto->toLoc,
                'description' => $dto->desc ?? null,
                'from_date' => CarbonImmutable::now()->toDateString(),
                'to_date' => $dto->toDate->toDateString(),
                'price' => $dto->price ?? null,
                'currency' => $dto->currency ?? null,
                'user_id' => $this->userService->getUserByTelegramId($request)->id,
                'status' => 'open',
            ]
        );
        $sendReq->save();
        $this->matcher->matchSendRequest($sendReq);

        return response()->json($sendReq);
    }
}
