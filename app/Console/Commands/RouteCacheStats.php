<?php

namespace App\Console\Commands;

use App\Services\RouteCacheService;
use Illuminate\Console\Command;

class RouteCacheStats extends Command
{
    protected $signature = 'cache:route-stats';
    protected $description = 'Show route cache statistics';

    public function __construct(
        private readonly RouteCacheService $routeCacheService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Route Cache Statistics');
        $this->line('');

        try {
            $stats = $this->routeCacheService->getCacheStats();

            // Basic cache status
            $this->table(
                ['Cache Type', 'Status'],
                [
                    ['Active Routes', $stats['active_routes'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Popular Routes', $stats['popular_routes'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Request Counts', $stats['active_requests_counts'] ? '✅ Cached' : '❌ Not Cached'],
                ]
            );

            // Detailed statistics
            $this->line('');
            $this->info("Individual Route Counts Cached (Sample): {$stats['individual_route_counts']}/10");
            $this->info("Country Routes Cached (Sample): {$stats['country_routes']} pairs");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to get cache statistics: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}