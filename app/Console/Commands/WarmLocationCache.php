<?php

namespace App\Console\Commands;

use App\Services\LocationCacheService;
use Illuminate\Console\Command;

class WarmLocationCache extends Command
{
    protected $signature = 'cache:warm-locations';
    protected $description = 'Warm up the location cache with all essential data';

    public function __construct(
        private LocationCacheService $locationCacheService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting location cache warming...');

        try {
            $startTime = microtime(true);
            
            $this->locationCacheService->warmCache();
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);

            $this->info("Location cache warmed successfully in {$duration} seconds");
            
            // Show cache statistics
            $stats = $this->locationCacheService->getCacheStats();
            $this->table(
                ['Cache Type', 'Status'],
                [
                    ['All Locations', $stats['all_locations'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Countries', $stats['countries'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Cities', $stats['cities'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Hierarchy', $stats['hierarchy'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Individual Locations (sample)', $stats['individual_locations'] . ' cached'],
                ]
            );

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to warm location cache: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}