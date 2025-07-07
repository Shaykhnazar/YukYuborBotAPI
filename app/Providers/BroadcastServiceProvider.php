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

//         Load channel definitions
        require base_path('routes/channels.php');
    }
}
