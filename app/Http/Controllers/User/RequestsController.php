<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\Parcel\IndexRequest;
use App\Models\TelegramUser;
use App\Models\User;
use App\Service\TelegramUserService;
use Illuminate\Http\Request;

class RequestsController extends Controller
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
