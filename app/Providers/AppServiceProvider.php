<?php

namespace App\Providers;

use App\Services\Matcher;
use App\Services\TelegramNotificationService;
use App\Services\GoogleSheetsService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind GoogleSheetsService as singleton to prevent concurrent access issues
        $this->app->singleton(GoogleSheetsService::class, function ($app) {
            return new GoogleSheetsService();
        });

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
