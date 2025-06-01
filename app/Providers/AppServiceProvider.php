<?php

namespace App\Providers;

use App\Service\Matcher;
use App\Service\TelegramNotificationService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind TelegramNotificationService as singleton
        $this->app->singleton(TelegramNotificationService::class, function ($app) {
            return new TelegramNotificationService();
        });

        // Bind Matcher with dependency injection
        $this->app->bind(Matcher::class, function ($app) {
            return new Matcher($app->make(TelegramNotificationService::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
