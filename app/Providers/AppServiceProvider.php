<?php

namespace App\Providers;

use App\Services\Matcher;
use App\Services\GoogleSheetsService;
use App\Services\Matching\RequestMatchingService;
use App\Services\Matching\ResponseCreationService;
use App\Services\Matching\ResponseStatusService;
use App\Services\NotificationService;
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

        // Bind NotificationService as singleton
        $this->app->singleton(NotificationService::class, function ($app) {
            return new NotificationService();
        });

        // Bind Matcher with dependency injection
        $this->app->bind(Matcher::class, function ($app) {
            return new Matcher(
                $app->make(NotificationService::class),
                $app->make(RequestMatchingService::class),
                $app->make(ResponseCreationService::class),
                $app->make(ResponseStatusService::class)
            );
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
