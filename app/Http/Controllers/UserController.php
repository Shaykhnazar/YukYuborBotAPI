<?php

namespace App\Http\Controllers;

use App\Models\TelegramUser;
use App\Service\TelegramUserService;
use Illuminate\Http\Request;

class UserController extends Controller
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
