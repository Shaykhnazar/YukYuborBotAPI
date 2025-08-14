<?php

namespace App\Http\Middleware\Traits;

use App\Models\TelegramUser;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

trait TelegramUserHandler
{
    protected function handleRequest(Request $request, Closure $next, bool $isDev = false): JsonResponse
    {
        try {
            $userDataHeader = $request->header('X-TELEGRAM-USER-DATA');

            if (!$userDataHeader) {
                Log::warning('No Telegram user data header found');
                return response()->json(['error' => 'No Telegram user data provided'], 401);
            }

            // Decode base64-encoded header (to handle non-ASCII characters)
            $userDataHeader = base64_decode($userDataHeader);

            // Parse the header data
            parse_str($userDataHeader, $userData);

            if (!isset($userData['user'])) {
                Log::warning('No user data in Telegram header');
                return response()->json(['error' => 'Invalid Telegram user data'], 401);
            }

            // Decode user JSON
            $user = json_decode($userData['user'], true);

            if (!$user || !isset($user['id'])) {
                Log::warning('Invalid user JSON in Telegram data');
                return response()->json(['error' => 'Invalid user format'], 401);
            }

            $telegramId = (int) $user['id'];

            if ($telegramId <= 0) {
                Log::warning('Invalid Telegram ID: ' . $telegramId);
                return response()->json(['error' => 'Invalid Telegram ID'], 401);
            }

            // Find or create user
            $existingTelegramUser = TelegramUser::where('telegram', $telegramId)->first();

            if (!$existingTelegramUser) {
                // Create new user
                $userModel = User::create([
                    'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
                    'links_balance' => 3 // Default balance
                ]);

                $telegramUser = TelegramUser::create([
                    'telegram' => $telegramId,
                    'username' => $user['username'] ?? null,
                    'image' => $user['photo_url'] ?? null,
                    'user_id' => $userModel->id
                ]);

//                Log::info('Created new user', [
//                    'telegram_id' => $telegramId,
//                    'user_id' => $userModel->id,
//                    'name' => $userModel->name,
//                    'dev_mode' => $isDev
//                ]);
            } else {
                // Update existing user info if needed
                $userModel = $existingTelegramUser->user;

                $needsUpdate = false;
                $updates = [];

                // Update username if changed
                if (isset($user['username']) && $existingTelegramUser->username !== $user['username']) {
                    $updates['username'] = $user['username'];
                    $needsUpdate = true;
                }

                // Update photo if changed
                if (isset($user['photo_url']) && $existingTelegramUser->image !== $user['photo_url']) {
                    $updates['image'] = $user['photo_url'];
                    $needsUpdate = true;
                }

                if ($needsUpdate) {
                    $existingTelegramUser->update($updates);
//                    Log::info('Updated Telegram user info', [
//                        'telegram_id' => $telegramId,
//                        'updates' => $updates
//                    ]);
                }

                // Update name if needed
                $newName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                if ($userModel->name !== $newName && !empty($newName)) {
                    $userModel->update(['name' => $newName]);
//                    Log::info('Updated user name', [
//                        'user_id' => $userModel->id,
//                        'old_name' => $userModel->name,
//                        'new_name' => $newName
//                    ]);
                }
            }

            // Add telegram_id to request for easy access in controllers
            $request->attributes->set('telegram_id', $telegramId);

//            Log::info('Telegram user processed successfully', [
//                'telegram_id' => $telegramId,
//                'user_id' => $userModel->id,
//                'dev_mode' => $isDev
//            ]);

            return $next($request);

        } catch (\Exception $e) {
            Log::error('Error processing Telegram user data', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'dev_mode' => $isDev
            ]);

            return response()->json([
                'error' => 'Failed to process user data',
                'message' => $isDev ? $e->getMessage() : 'Authentication failed'
            ], 500);
        }
    }
}
