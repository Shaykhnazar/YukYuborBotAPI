<?php

namespace App\Console\Commands;

use App\Services\RouteCacheService;
use Illuminate\Console\Command;

class WarmRouteCache extends Command
{
    protected $signature = 'cache:warm-routes';
    protected $description = 'Warm up the route cache with all essential data';

    public function __construct(
        private readonly RouteCacheService $routeCacheService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting route cache warming...');

        try {
            $startTime = microtime(true);
            
            $this->routeCacheService->warmCache();
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $this->info("Route cache warmed successfully in {$duration} seconds");
            
            // Show cache statistics
            $stats = $this->routeCacheService->getCacheStats();
            $this->table(
                ['Cache Type', 'Status'],
                [
                    ['Active Routes', $stats['active_routes'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Popular Routes', $stats['popular_routes'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Request Counts', $stats['active_requests_counts'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Individual Route Counts (sample)', $stats['individual_route_counts'] . ' cached'],
                    ['Country Routes (sample)', $stats['country_routes'] . ' cached'],
                ]
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to warm route cache: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}