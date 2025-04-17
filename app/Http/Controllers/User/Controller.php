<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller as BaseController;
use App\Service\TelegramUserService;
use Illuminate\Http\Request;

class Controller extends BaseController
{
    public function __construct(
        protected TelegramUserService $tgService,
    ) {}

    public function index(Request $request) {
        $user = $this->tgService->getUserByTelegramId($request);
        return response()->json([
            'telegram' => $user->telegramUser,
            'user' => collect($user)->except('telegram_user')
        ]);
    }
}
