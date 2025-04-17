<?php

namespace App\Http\Controllers\User\Requests;

use App\Http\Controllers\Controller as BaseController;
use App\Http\Requests\Parcel\IndexRequest;
use App\Service\TelegramUserService;

class Controller extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    )
    {}

    public function index(IndexRequest $request)
    {
        $filter = $request->getFilter();
        $user = $this->tgService->getUserByTelegramId($request);

        $requests = $user->sendRequests;
        return response()->json($requests);
    }
}
