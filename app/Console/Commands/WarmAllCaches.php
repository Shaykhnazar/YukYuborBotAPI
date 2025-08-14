<?php

namespace App\Console\Commands;

use App\Services\LocationCacheService;
use App\Services\RouteCacheService;
use Illuminate\Console\Command;

class WarmAllCaches extends Command
{
    protected $signature = 'cache:warm-all {--clear : Clear caches before warming}';
    protected $description = 'Warm up all application caches (locations and routes)';

    public function __construct(
        private readonly LocationCacheService $locationCacheService,
        private readonly RouteCacheService $routeCacheService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting comprehensive cache warming...');

        try {
            $overallStartTime = microtime(true);

            if ($this->option('clear')) {
                $this->info('Clearing existing caches...');
                $this->locationCacheService->clearCache();
                $this->routeCacheService->clearCache();
                $this->info('Caches cleared successfully');
                $this->line('');
            }

            // Warm location cache first (routes depend on locations)
            $this->info('1. Warming location cache...');
            $startTime = microtime(true);
            $this->locationCacheService->warmCache();
            $duration = round(microtime(true) - $startTime, 2);
            $this->info("✅ Location cache warmed in {$duration}s");

            // Warm route cache
            $this->info('2. Warming route cache...');
            $startTime = microtime(true);
            $this->routeCacheService->warmCache();
            $duration = round(microtime(true) - $startTime, 2);
            $this->info("✅ Route cache warmed in {$duration}s");

            $overallDuration = round(microtime(true) - $overallStartTime, 2);
            $this->line('');
            $this->info("🎉 All caches warmed successfully in {$overallDuration}s");

            // Show comprehensive statistics
            $this->showCacheStats();

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to warm caches: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function showCacheStats(): void
    {
        $this->line('');
        $this->info('📊 Cache Statistics Summary');
        $this->line('');

        try {
            // Location cache stats
            $locationStats = $this->locationCacheService->getCacheStats();
            $this->info('Location Caches:');
            $this->table(
                ['Type', 'Status'],
                [
                    ['Countries', $locationStats['countries'] ? '✅' : '❌'],
                    ['Cities', $locationStats['cities'] ? '✅' : '❌'],
                    ['Hierarchy', $locationStats['hierarchy'] ? '✅' : '❌'],
                    ['Individual Locations (sample)', $locationStats['individual_locations'] . '/10'],
                ]
            );

            // Route cache stats
            $routeStats = $this->routeCacheService->getCacheStats();
            $this->info('Route Caches:');
            $this->table(
                ['Type', 'Status'],
                [
                    ['Active Routes', $routeStats['active_routes'] ? '✅' : '❌'],
                    ['Popular Routes', $routeStats['popular_routes'] ? '✅' : '❌'],
                    ['Request Counts', $routeStats['active_requests_counts'] ? '✅' : '❌'],
                    ['Individual Route Counts (sample)', $routeStats['individual_route_counts'] . '/10'],
                    ['Country Routes (sample)', $routeStats['country_routes'] . ' pairs'],
                ]
            );

        } catch (\Exception $e) {
            $this->warn('Could not retrieve cache statistics: ' . $e->getMessage());
        }
    }
}