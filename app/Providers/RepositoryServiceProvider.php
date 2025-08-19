<?php

namespace App\Providers;

use App\Models\DeliveryRequest;
use App\Models\Response;
use App\Models\SendRequest;
use App\Models\User;
use App\Repositories\Contracts\DeliveryRequestRepositoryInterface;
use App\Repositories\Contracts\ResponseRepositoryInterface;
use App\Repositories\Contracts\SendRequestRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\DeliveryRequestRepository;
use App\Repositories\Eloquent\ResponseRepository;
use App\Repositories\Eloquent\SendRequestRepository;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind repository interfaces to their concrete implementations
        $this->app->bind(SendRequestRepositoryInterface::class, function ($app) {
            return new SendRequestRepository(new SendRequest());
        });

        $this->app->bind(DeliveryRequestRepositoryInterface::class, function ($app) {
            return new DeliveryRequestRepository(new DeliveryRequest());
        });

        $this->app->bind(ResponseRepositoryInterface::class, function ($app) {
            return new ResponseRepository(new Response());
        });

        $this->app->bind(UserRepositoryInterface::class, function ($app) {
            return new UserRepository(new User());
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}