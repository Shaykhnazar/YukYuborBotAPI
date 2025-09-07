<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationsSeeder extends Seeder
{
    public function run()
    {
        // Load Uzbekistan regions data
        $regionsJson = file_get_contents(database_path('seeders/data/regions.json'));
        $districtsJson = file_get_contents(database_path('seeders/data/districts.json'));
        
        // Remove BOM if present
        $regionsJson = preg_replace('/^\xEF\xBB\xBF/', '', $regionsJson);
        $districtsJson = preg_replace('/^\xEF\xBB\xBF/', '', $districtsJson);
        
        $regionsData = json_decode($regionsJson, true);
        $districtsData = json_decode($districtsJson, true);

        if (!$regionsData || !$districtsData) {
            $this->command->error('Failed to load JSON data files. Please check regions.json and districts.json');
            return;
        }

        $regionIds = [];

        // Insert regions as countries (top level locations)
        foreach ($regionsData as $region) {
            $id = DB::table('locations')->insertGetId([
                'name' => $region['name_uz'], // Use Uzbek name as default
                'name_ru' => $region['name_ru'], // Store Russian name separately
                'parent_id' => null,
                'type' => 'country',
                'country_code' => 'UZ',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $regionIds[$region['id']] = $id;
        }

        // Insert districts as cities under their respective regions
        foreach ($districtsData as $district) {
            $regionDbId = $regionIds[$district['region_id']] ?? null;

            if ($regionDbId) {
                DB::table('locations')->insert([
                    'name' => $district['name_uz'], // Use Uzbek name as default
                    'name_ru' => $district['name_ru'], // Store Russian name separately
                    'parent_id' => $regionDbId,
                    'type' => 'city',
                    'country_code' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info('Uzbekistan regions and districts seeded successfully.');
    }
}
