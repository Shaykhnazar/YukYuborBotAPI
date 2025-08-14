<?php

namespace App\Console\Commands;

use App\Services\RouteCacheService;
use Illuminate\Console\Command;

class ClearRouteCache extends Command
{
    protected $signature = 'cache:clear-routes {--warm : Warm the cache after clearing}';
    protected $description = 'Clear all route caches';

    public function __construct(
        private readonly RouteCacheService $routeCacheService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Clearing route cache...');

        try {
            $this->routeCacheService->clearCache();
            $this->info('Route cache cleared successfully');

            if ($this->option('warm')) {
                $this->info('Warming cache after clearing...');
                $this->routeCacheService->warmCache();
                $this->info('Cache warmed successfully');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to clear route cache: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}