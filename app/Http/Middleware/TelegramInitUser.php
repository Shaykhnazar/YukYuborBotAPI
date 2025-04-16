<?php

namespace App\Http\Middleware;

use App\Models\TelegramUser;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TelegramInitUser
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userDataHead = $request->header(config('auth.guards.tgwebapp.userDataHeaderName'));

        parse_str(urldecode($userDataHead), $userData);
        $user = json_decode($userData['user'], true);

        if (!TelegramUser::where('telegram', (int) $user['id'])->exists()){
            $userModel = new User(
                [
                    'name' => trim(trim($user['first_name']) . ' ' . trim($user['last_name']))
                ]
            );
            $userModel->save();
            $telegramUser = new TelegramUser(
                [
                    'telegram' => $user['id'],
                    'username' => $user['username'],
                    'image' => $user['photo_url'],
                    'user_id' => $userModel->id
                ]
            );
            $telegramUser->save();
        }
        $request->attributes->set('telegram_id', $user['id']);
        return $next($request);
    }
}
