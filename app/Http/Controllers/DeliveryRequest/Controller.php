<?php

namespace App\Http\Controllers\DeliveryRequest;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\DeliveryRequest\CreateRequest;
use App\Models\DeliveryRequest;
use App\Service\TelegramUserService;
use App\Service\Matcher;

class Controller extends BaseController
{
    public function __construct(
        protected TelegramUserService $userService,
        protected Matcher $matcher
    )
    {
    }

    public function create(CreateRequest $request)
    {
        $dto = $request->getDTO();
        $deliveryReq = new DeliveryRequest(
            [
                'from_location' => $dto->fromLoc,
                'to_location' => $dto->toLoc,
                'description' => $dto->desc ?? null,
                'from_date' => $dto->fromDate->toDateString(),
                'to_date' => $dto->toDate->toDateString(),
                'price' => $dto->price ?? null,
                'currency' => $dto->currency ?? null,
                'user_id' => $this->userService->getUserByTelegramId($request)->id,
                'status' => 'open',
            ]
        );
        $deliveryReq->save();
        $this->matcher->matchDeliveryRequest($deliveryReq);

        return response()->json($deliveryReq);
    }
}
