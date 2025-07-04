<?php

namespace App\Providers;

use App\Service\TelegramUserService;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Determine middleware based on environment
        $middleware = [];

        if (app()->environment(['local', 'development'])) {
            // Development/Local environment - use development middleware
            $middleware[] = 'tg.init.dev';
            // Skip the auth:tgwebapp middleware in development
        } else {
            // Production environment - use production middleware + auth
            $middleware[] = 'tg.init';
            $middleware[] = 'auth:tgwebapp';
        }
        // Register broadcasting routes
        Broadcast::routes(['prefix' => 'api', 'middleware' => $middleware]);

//         🔧 CRITICAL: Custom authenticator for proper user resolution
//        Broadcast::auth(function ($request, $guard = null) {
//            try {
//                Log::info('🔐 Broadcasting auth via BroadcastServiceProvider', [
//                    'url' => $request->url(),
//                    'method' => $request->method(),
//                    'channel' => $request->input('channel_name'),
//                    'socket_id' => $request->input('socket_id'),
//                    'has_telegram_data' => $request->hasHeader('X-TELEGRAM-USER-DATA'),
//                ]);
//
//                // Get user through the existing middleware/service
//                $telegramUserService = app(TelegramUserService::class);
//                $user = $telegramUserService->getUserByTelegramId($request);
//
//                if (!$user) {
//                    Log::warning('❌ Broadcasting auth failed - no user found in provider');
//                    return null;
//                }
//
//                Log::info('✅ Broadcasting auth successful in provider', [
//                    'user_id' => $user->id,
//                    'user_name' => $user->name,
//                    'channel' => $request->input('channel_name')
//                ]);
//
//                return $user;
//
//            } catch (\Exception $e) {
//                Log::error('❌ Broadcasting auth exception in provider', [
//                    'error' => $e->getMessage(),
//                    'trace' => $e->getTraceAsString()
//                ]);
//                return null;
//            }
//        });

//         Load channel definitions
        require base_path('routes/channels.php');
    }
}
