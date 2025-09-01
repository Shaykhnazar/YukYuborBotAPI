<?php

namespace App\Providers;

use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use App\Services\Matcher;
use App\Services\GoogleSheetsService;
use App\Services\Matching\CapacityAwareMatchingService;
use App\Services\Matching\RequestMatchingService;
use App\Services\Matching\ResponseCreationService;
use App\Services\Matching\ResponseStatusService;
use App\Services\Matching\RoundRobinDistributionService;
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

        // Bind RoundRobinDistributionService as singleton to maintain state
        $this->app->singleton(RoundRobinDistributionService::class, function ($app) {
            return new RoundRobinDistributionService();
        });

        // Bind CapacityAwareMatchingService with dependencies
        $this->app->bind(CapacityAwareMatchingService::class, function ($app) {
            return new CapacityAwareMatchingService(
                $app->make(SendRequestRepositoryInterface::class),
                $app->make(DeliveryRequestRepositoryInterface::class),
                $app->make(RoundRobinDistributionService::class)
            );
        });

        // Bind Matcher with dependency injection
        $this->app->bind(Matcher::class, function ($app) {
            return new Matcher(
                $app->make(NotificationService::class),
                $app->make(RequestMatchingService::class),
                $app->make(CapacityAwareMatchingService::class),
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
