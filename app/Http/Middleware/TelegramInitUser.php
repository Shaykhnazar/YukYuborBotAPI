<?php

namespace App\Http\Middleware;

use App\Models\TelegramUser;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TelegramInitUser
{
    /**
     * Handle incoming request with real Telegram data detection
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('TelegramInitUser middleware triggered', [
            'url' => $request->url(),
            'method' => $request->method(),
            'has_user_data' => $request->hasHeader('X-TELEGRAM-USER-DATA'),
            'dev_mode' => $request->header('X-DEV-MODE'),
        ]);

        // Check if this is real Telegram data
        $initData = $request->header(config('auth.guards.tgwebapp.userDataHeaderName', 'X-TELEGRAM-USER-DATA'));
        $devMode = $request->header('X-DEV-MODE') === 'true';

        if ($initData && !$devMode) {
            return $this->handleRealTelegramData($request, $initData, $next);
        } else {
            return $this->handleDevelopmentData($request, $next);
        }
    }

    /**
     * Handle real Telegram Web App data
     */
    private function handleRealTelegramData(Request $request, string $initData, Closure $next): Response
    {
        Log::info('Processing REAL Telegram Web App data', ['init_data_length' => strlen($initData)]);

        try {
            // Parse Telegram's initData
            parse_str($initData, $params);

            if (!isset($params['user'])) {
                Log::warning('No user data in Telegram initData');
                return response()->json(['error' => 'Invalid Telegram data'], 401);
            }

            $user = json_decode($params['user'], true);

            if (!$user || !isset($user['id'])) {
                Log::warning('Invalid Telegram user data', ['user' => $user]);
                return response()->json(['error' => 'Invalid user data'], 401);
            }

            // TODO: Add Telegram hash validation here for production security
            // $this->validateTelegramHash($initData);

            $this->createOrUpdateUser($user, true);
            $request->attributes->set('telegram_id', $user['id']);
            $request->attributes->set('is_real_telegram', true);

            Log::info('Real Telegram user processed', [
                'telegram_id' => $user['id'],
                'username' => $user['username'] ?? null,
                'first_name' => $user['first_name'] ?? null
            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('Error processing real Telegram data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Telegram authentication failed'], 401);
        }
    }

    /**
     * Handle development/testing data
     */
    private function handleDevelopmentData(Request $request, Closure $next): Response
    {
        Log::info('Processing development user data');

        $userDataHead = $request->header('X-TELEGRAM-USER-DATA');

        if ($userDataHead) {
            parse_str(urldecode($userDataHead), $userData);

            if (isset($userData['user'])) {
                $user = json_decode($userData['user'], true);

                if (!$user || !isset($user['id'])) {
                    Log::warning('Invalid development user data');
                    return response()->json(['error' => 'Invalid development user data'], 401);
                }

                $this->createOrUpdateUser($user, false);
                $request->attributes->set('telegram_id', $user['id']);
                $request->attributes->set('is_real_telegram', false);

                Log::info('Development user processed', ['telegram_id' => $user['id']]);
                return $next($request);
            }
        }

        Log::warning('No valid user data found');
        return response()->json(['error' => 'No user data'], 401);
    }

    /**
     * Create or update user
     */
    private function createOrUpdateUser(array $user, bool $isRealTelegram): void
    {
        Log::info('Creating/updating user', [
            'user_id' => $user['id'],
            'is_real' => $isRealTelegram
        ]);

        $telegramUser = TelegramUser::where('telegram', (int) $user['id'])->first();

        if (!$telegramUser) {
            $userModel = new User([
                'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                'links_balance' => $isRealTelegram ? 3 : 10  // TODO: MVP: Give every user 3 links by default
            ]);
            $userModel->save();

            $telegramUser = new TelegramUser([
                'telegram' => $user['id'],
                'username' => $user['username'] ?? null,
                'image' => $user['photo_url'] ?? null,
                'user_id' => $userModel->id
            ]);
            $telegramUser->save();

            Log::info('New user created', [
                'user_id' => $userModel->id,
                'telegram_id' => $user['id'],
                'is_real' => $isRealTelegram
            ]);
        } else {
            Log::info('Updating existing production user', ['existing_user_id' => $telegramUser->user_id]);

            // Update user info only if changed
            $updates = [];
            if (isset($user['username']) && $telegramUser->username !== $user['username']) {
                $updates['username'] = $user['username'];
            }
            if (isset($user['photo_url']) && $telegramUser->image !== $user['photo_url']) {
                $updates['image'] = $user['photo_url'];
            }

            if (!empty($updates)) {
                $telegramUser->update($updates);
                Log::info('Production telegram user updated', $updates);
            }

            // Update name
            $newName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            if ($newName && $telegramUser->user->name !== $newName) {
                $telegramUser->user->update(['name' => $newName]);
            }
        }
    }
}
