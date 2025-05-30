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
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Add extensive debugging
        Log::info('TelegramInitUser middleware triggered', [
            'url' => $request->url(),
            'method' => $request->method(),
            'headers' => $request->headers->all(),
            'dev_mode_header' => $request->header('X-DEV-MODE'),
            'telegram_data_header' => $request->header('X-TELEGRAM-USER-DATA'),
            'environment' => app()->environment()
        ]);

        // Development mode bypass
        if (app()->environment(['local', 'development']) &&
            ($request->header('X-DEV-MODE') === 'true' || env('TELEGRAM_DEV_MODE', false))) {

            Log::info('Using development mode');
            $this->handleDevelopmentMode($request);
            return $next($request);
        }

        // Production mode - original logic
        $userDataHead = $request->header(config('auth.guards.tgwebapp.userDataHeaderName', 'X-TELEGRAM-USER-DATA'));
        Log::info('Looking for user data header', [
            'header_name' => config('auth.guards.tgwebapp.userDataHeaderName', 'X-TELEGRAM-USER-DATA'),
            'header_value' => $userDataHead
        ]);

        if (!$userDataHead) {
            Log::warning('Missing Telegram user data header');
            return response()->json(['error' => 'Missing Telegram user data'], 401);
        }

        parse_str(urldecode($userDataHead), $userData);
        Log::info('Parsed user data', ['userData' => $userData]);

        if (!isset($userData['user'])) {
            Log::warning('Invalid Telegram user data - no user field');
            return response()->json(['error' => 'Invalid Telegram user data'], 401);
        }

        $user = json_decode($userData['user'], true);
        Log::info('Decoded user', ['user' => $user]);

        if (!$user || !isset($user['id'])) {
            Log::warning('Invalid user data format');
            return response()->json(['error' => 'Invalid user data format'], 401);
        }

        $this->createOrUpdateUser($user);
        $request->attributes->set('telegram_id', $user['id']);

        Log::info('User processed successfully', ['telegram_id' => $user['id']]);

        return $next($request);
    }

    /**
     * Handle development mode with fake user data
     */
    private function handleDevelopmentMode(Request $request): void
    {
        Log::info('Handling development mode');

        // Get user data from X-TELEGRAM-USER-DATA header (same as production)
        $userDataHead = $request->header('X-TELEGRAM-USER-DATA');

        if ($userDataHead) {
            // Parse the data the same way as production mode
            parse_str(urldecode($userDataHead), $userData);
            Log::info('Parsed development user data', ['userData' => $userData]);

            if (isset($userData['user'])) {
                $user = json_decode($userData['user'], true);
                Log::info('Using development user from header', ['user' => $user]);
            } else {
                Log::warning('Invalid development user data format, using default');
                $user = $this->getDefaultDevUser();
            }
        } else {
            // Fallback to default development user
            $user = $this->getDefaultDevUser();
            Log::info('Using default dev user', ['user' => $user]);
        }

        // Create or update the development user (ensures separate DB records)
        $this->createOrUpdateUser($user);
        $request->attributes->set('telegram_id', $user['id']);
        $request->attributes->set('telegram_user', $user);

        Log::info('Development user set', [
            'telegram_id' => $user['id'],
            'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
            'username' => $user['username'] ?? null
        ]);
    }

    /**
     * Create or update user (shared logic for both production and development)
     */
    private function createOrUpdateUser(array $user): void
    {
        Log::info('Creating or updating user', ['user_id' => $user['id']]);

        $telegramUser = TelegramUser::where('telegram', (int) $user['id'])->first();

        if (!$telegramUser) {
            Log::info('Creating new user');
            // Create new user
            $userModel = new User([
                'name' => trim(trim($user['first_name'] ?? '') . ' ' . trim($user['last_name'] ?? '')),
                'links_balance' => 3 // TODO: MVP: Give every user 3 links by default
            ]);
            $userModel->save();

            $telegramUser = new TelegramUser([
                'telegram' => $user['id'],
                'username' => $user['username'] ?? null,
                'image' => $user['photo_url'] ?? null,
                'user_id' => $userModel->id
            ]);
            $telegramUser->save();
            Log::info('New user created', ['user_id' => $userModel->id, 'telegram_id' => $user['id']]);
        } else {
            Log::info('Updating existing user', ['existing_user_id' => $telegramUser->user_id]);
            // Update existing user info if needed
            $updates = [];

            if (isset($user['username']) && $telegramUser->username !== $user['username']) {
                $updates['username'] = $user['username'];
            }

            if (isset($user['photo_url']) && $telegramUser->image !== $user['photo_url']) {
                $updates['image'] = $user['photo_url'];
            }

            if (!empty($updates)) {
                $telegramUser->update($updates);
                Log::info('Telegram user updated', $updates);
            }

            // Update user name if needed
            $newName = trim(trim($user['first_name'] ?? '') . ' ' . trim($user['last_name'] ?? ''));
            if ($newName && $telegramUser->user->name !== $newName) {
                $telegramUser->user->update(['name' => $newName]);
                Log::info('User name updated', ['new_name' => $newName]);
            }
        }
    }

    /**
     * Get default development user
     */
    private function getDefaultDevUser(): array
    {
        return [
            'id' => env('DEV_TELEGRAM_ID'),
            'first_name' => 'Dev',
            'last_name' => 'User',
            'username' => env('DEV_TELEGRAM_USERNAME'),
            'language_code' => 'ru',
            'photo_url' => 'https://via.placeholder.com/150/0000FF/808080?text=Dev+User'
        ];
    }
}
