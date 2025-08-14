<?php

namespace App\Console\Commands;

use App\Services\LocationCacheService;
use Illuminate\Console\Command;

class LocationCacheStats extends Command
{
    protected $signature = 'cache:location-stats';
    protected $description = 'Show location cache statistics';

    public function __construct(
        private LocationCacheService $locationCacheService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Location Cache Statistics');
        $this->line('');

        try {
            $stats = $this->locationCacheService->getCacheStats();

            // Basic cache status
            $this->table(
                ['Cache Type', 'Status'],
                [
                    ['All Locations', $stats['all_locations'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Countries', $stats['countries'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Cities', $stats['cities'] ? '✅ Cached' : '❌ Not Cached'],
                    ['Hierarchy', $stats['hierarchy'] ? '✅ Cached' : '❌ Not Cached'],
                ]
            );

            // Countries with cities variations
            if (!empty($stats['countries_with_cities'])) {
                $this->line('');
                $this->info('Countries with Cities Cache Variations:');
                $countryWithCitiesData = [];
                foreach ($stats['countries_with_cities'] as $limit => $cached) {
                    $countryWithCitiesData[] = [
                        "Limit {$limit}",
                        $cached ? '✅ Cached' : '❌ Not Cached'
                    ];
                }
                $this->table(['Variation', 'Status'], $countryWithCitiesData);
            }

            // Cities by country (sample)
            if (!empty($stats['cities_by_country'])) {
                $this->line('');
                $this->info('Cities by Country Cache (Sample):');
                $citiesByCountryData = [];
                foreach ($stats['cities_by_country'] as $countryId => $cached) {
                    $citiesByCountryData[] = [
                        "Country ID {$countryId}",
                        $cached ? '✅ Cached' : '❌ Not Cached'
                    ];
                }
                $this->table(['Country', 'Status'], $citiesByCountryData);
            }

            // Individual locations
            $this->line('');
            $this->info("Individual Locations Cached (Sample): {$stats['individual_locations']}/10");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failed to get cache statistics: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}