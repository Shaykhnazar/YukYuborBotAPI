<?php

namespace App\Http\Middleware;

use App\Models\TelegramUser;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TelegramInitUserDev
{
    /**
     * Handle an incoming request for development environment.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('TelegramInitUserDev middleware triggered', [
            'url' => $request->url(),
            'method' => $request->method(),
            'environment' => app()->environment(),
            'dev_mode_header' => $request->header('X-DEV-MODE'),
        ]);

        // Always handle as development mode
        $this->handleDevelopmentMode($request);

        return $next($request);
    }

    /**
     * Handle development mode with fake user data
     */
    private function handleDevelopmentMode(Request $request): void
    {
        Log::info('Processing development user data');

        // Get user data from X-TELEGRAM-USER-DATA header
        $userDataHead = $request->header('X-TELEGRAM-USER-DATA');

        if ($userDataHead) {
            // Parse the data the same way as production mode
            parse_str(urldecode($userDataHead), $userData);
            Log::info('Parsed development user data', [
                'has_user_field' => isset($userData['user']),
                'chat_instance' => $userData['chat_instance'] ?? 'not_set'
            ]);

            if (isset($userData['user'])) {
                $user = json_decode($userData['user'], true);

                // Validate user data
                if (!$user || !isset($user['id'])) {
                    Log::warning('Invalid development user data, using default', ['user_data' => $user]);
                    $user = $this->getDefaultDevUser();
                } else {
                    Log::info('Using development user from header', [
                        'user_id' => $user['id'],
                        'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
                        'username' => $user['username'] ?? null
                    ]);
                }
            } else {
                Log::warning('No user field in development data, using default');
                $user = $this->getDefaultDevUser();
            }
        } else {
            // Fallback to default development user
            $user = $this->getDefaultDevUser();
            Log::info('No X-TELEGRAM-USER-DATA header, using default dev user');
        }

        // Ensure user has valid ID
        if (!isset($user['id']) || !$user['id']) {
            $user['id'] = $this->getDefaultDevUserId();
            Log::info('Fixed missing user ID', ['user_id' => $user['id']]);
        }

        // Create or update the development user
        $this->createOrUpdateUser($user);
        $request->attributes->set('telegram_id', $user['id']);
        $request->attributes->set('telegram_user', $user);

        Log::info('Development user processed successfully', [
            'telegram_id' => $user['id'],
            'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
            'username' => $user['username'] ?? null
        ]);
    }

    /**
     * Create or update user (development logic)
     */
    private function createOrUpdateUser(array $user): void
    {
        Log::info('Creating or updating development user', ['user_id' => $user['id']]);

        $telegramUser = TelegramUser::where('telegram', (int) $user['id'])->first();

        if (!$telegramUser) {
            Log::info('Creating new development user');

            // Create new user with development defaults
            $userModel = new User([
                'name' => trim(trim($user['first_name'] ?? 'Dev') . ' ' . trim($user['last_name'] ?? 'User')),
                'links_balance' => 10 // Give development users more links for testing
            ]);
            $userModel->save();

            $telegramUser = new TelegramUser([
                'telegram' => $user['id'],
                'username' => $user['username'] ?? 'dev_user',
                'image' => $user['photo_url'] ?? 'https://via.placeholder.com/150/0000FF/808080?text=Dev+User',
                'user_id' => $userModel->id
            ]);
            $telegramUser->save();

            Log::info('New development user created', [
                'user_id' => $userModel->id,
                'telegram_id' => $user['id'],
                'name' => $userModel->name,
                'links_balance' => $userModel->links_balance
            ]);
        } else {
            Log::info('Updating existing development user', ['existing_user_id' => $telegramUser->user_id]);

            // Always update development user info for testing
            $updates = [];

            if (isset($user['username']) && $telegramUser->username !== $user['username']) {
                $updates['username'] = $user['username'];
            }

            if (isset($user['photo_url']) && $telegramUser->image !== $user['photo_url']) {
                $updates['image'] = $user['photo_url'];
            }

            if (!empty($updates)) {
                $telegramUser->update($updates);
                Log::info('Development telegram user updated', $updates);
            }

            // Update user name if needed
            $newName = trim(trim($user['first_name'] ?? 'Dev') . ' ' . trim($user['last_name'] ?? 'User'));
            if ($newName && $telegramUser->user->name !== $newName) {
                $telegramUser->user->update(['name' => $newName]);
                Log::info('Development user name updated', ['new_name' => $newName]);
            }

            // Ensure development users always have enough links for testing
            if ($telegramUser->user->links_balance < 5) {
                $telegramUser->user->update(['links_balance' => 10]);
                Log::info('Refilled development user links', ['new_balance' => 10]);
            }
        }
    }

    /**
     * Get default development user ID
     */
    private function getDefaultDevUserId(): int
    {
        return (int) (env('DEV_TELEGRAM_ID') ?? 123456789);
    }

    /**
     * Get default development user
     */
    private function getDefaultDevUser(): array
    {
        return [
            'id' => $this->getDefaultDevUserId(),
            'first_name' => 'Dev',
            'last_name' => 'User',
            'username' => env('DEV_TELEGRAM_USERNAME') ?? 'dev_user',
            'language_code' => 'ru',
            'photo_url' => 'https://via.placeholder.com/150/0000FF/808080?text=Dev+User'
        ];
    }
}
