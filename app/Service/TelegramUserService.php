<?php

namespace App\Service;

use App\Models\TelegramUser;
use App\Models\User;

class TelegramUserService
{
    public function getUserByTelegramId($request): ?User
    {
        $telegramId = $request->get('telegram_id');
        return TelegramUser::where('telegram', $telegramId)->first()?->user;
    }
}
