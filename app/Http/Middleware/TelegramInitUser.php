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
     * Handle an incoming request for production environment.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('TelegramInitUser middleware triggered', [
            'url' => $request->url(),
            'method' => $request->method(),
            'environment' => app()->environment(),
            'has_telegram_data' => $request->hasHeader('X-TELEGRAM-USER-DATA'),
        ]);

        // Production mode - strict validation
        $userDataHead = $request->header(config('auth.guards.tgwebapp.userDataHeaderName', 'X-TELEGRAM-USER-DATA'));

        if (!$userDataHead) {
            Log::warning('Missing Telegram user data header in production');
            return response()->json([
                'error' => 'Missing Telegram user data',
                'message' => 'This application requires Telegram Web App authentication.'
            ], 401);
        }

        // Parse and validate user data
        parse_str(urldecode($userDataHead), $userData);
        Log::info('Parsed production user data', [
            'has_user_field' => isset($userData['user']),
            'has_auth_date' => isset($userData['auth_date']),
            'has_hash' => isset($userData['hash'])
        ]);

        if (!isset($userData['user'])) {
            Log::warning('Invalid Telegram user data - no user field');
            return response()->json([
                'error' => 'Invalid Telegram user data',
                'message' => 'User data is missing or corrupted.'
            ], 401);
        }

        $user = json_decode($userData['user'], true);

        if (!$user || !isset($user['id'])) {
            Log::warning('Invalid user data format - missing ID field', [
                'user_data' => $user,
                'has_id' => isset($user['id']),
                'user_keys' => $user ? array_keys($user) : 'null'
            ]);
            return response()->json([
                'error' => 'Invalid user data format',
                'message' => 'User ID is missing or invalid.'
            ], 401);
        }

        // Additional production validations
        if (!isset($userData['auth_date']) || !isset($userData['hash'])) {
            Log::warning('Missing authentication parameters', [
                'has_auth_date' => isset($userData['auth_date']),
                'has_hash' => isset($userData['hash'])
            ]);
            return response()->json([
                'error' => 'Missing authentication parameters',
                'message' => 'Authentication data is incomplete.'
            ], 401);
        }

        $this->createOrUpdateUser($user);
        $request->attributes->set('telegram_id', $user['id']);

        Log::info('Production user processed successfully', [
            'telegram_id' => $user['id'],
            'username' => $user['username'] ?? null,
            'first_name' => $user['first_name'] ?? null
        ]);

        return $next($request);
    }

    /**
     * Create or update user in production environment.
     */
    private function createOrUpdateUser(array $user): void
    {
        Log::info('Creating or updating production user', ['user_id' => $user['id']]);

        $telegramUser = TelegramUser::where('telegram', (int) $user['id'])->first();

        if (!$telegramUser) {
            Log::info('Creating new production user');

            // Create new user with production defaults
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

            Log::info('New production user created', [
                'user_id' => $userModel->id,
                'telegram_id' => $user['id'],
                'name' => $userModel->name
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

            // Update user name if needed
            $newName = trim(trim($user['first_name'] ?? '') . ' ' . trim($user['last_name'] ?? ''));
            if ($newName && $newName !== $telegramUser->user->name) {
                $telegramUser->user->update(['name' => $newName]);
                Log::info('Production user name updated', ['new_name' => $newName]);
            }
        }
    }


}
