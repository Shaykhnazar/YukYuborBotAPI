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
        // Register broadcasting routes
        Broadcast::routes(['middleware' => $middleware]);

        // Load channel definitions
        require base_path('routes/channels.php');
    }
}
