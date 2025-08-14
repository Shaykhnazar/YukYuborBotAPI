<?php

namespace App\Console\Commands;

use App\Services\LocationCacheService;
use Illuminate\Console\Command;

class ClearLocationCache extends Command
{
    protected $signature = 'cache:clear-locations {--warm : Warm the cache after clearing}';
    protected $description = 'Clear all location caches';

    public function __construct(
        private readonly LocationCacheService $locationCacheService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Clearing location cache...');

        try {
            $this->locationCacheService->clearCache();
            $this->info('Location cache cleared successfully');

            if ($this->option('warm')) {
                $this->info('Warming cache after clearing...');
                $this->locationCacheService->warmCache();
                $this->info('Cache warmed successfully');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to clear location cache: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
