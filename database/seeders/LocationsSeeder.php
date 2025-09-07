<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LocationsSeeder extends Seeder
{
    public function run()
    {
        // Load Uzbekistan regions data
        $regionsData = json_decode(file_get_contents(database_path('seeders/data/regions.json')), true);
        $districtsData = json_decode(file_get_contents(database_path('seeders/data/districts.json')), true);

        // First, insert Uzbekistan as the main country (using Tashkent city as capital)
        $uzbekistanId = DB::table('locations')->insertGetId([
            'name' => 'Ўзбекистон',
            'parent_id' => null,
            'type' => 'country',
            'country_code' => 'UZ',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $regionIds = [];

        // Insert regions as parent locations
        foreach ($regionsData as $region) {
            $id = DB::table('locations')->insertGetId([
                'name' => $region['name_ru'], // Use Russian name for consistency
                'parent_id' => $uzbekistanId,
                'type' => 'region',
                'country_code' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $regionIds[$region['id']] = $id;
        }

        // Insert cities/districts under their respective regions
        foreach ($districtsData as $district) {
            $regionDbId = $regionIds[$district['region_id']] ?? null;
            
            if ($regionDbId) {
                DB::table('locations')->insert([
                    'name' => $district['name_ru'], // Use Russian name for consistency
                    'parent_id' => $regionDbId,
                    'type' => 'city',
                    'country_code' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info('Uzbekistan locations seeded successfully.');
    }
}
