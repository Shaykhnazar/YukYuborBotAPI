<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Location;
use App\Models\Route;

class RoutesSeeder extends Seeder
{
    public function run()
    {
        // Get countries
        $kazakhstan = Location::where('name', 'Казахстан')->first();
        $uae = Location::where('name', 'ОАЭ')->first();
        $indonesia = Location::where('name', 'Индонезия')->first();
        $turkey = Location::where('name', 'Турция')->first();

        if (!$kazakhstan || !$uae || !$indonesia || !$turkey) {
            $this->command->warn('Countries not found. Please run LocationsSeeder first.');
            return;
        }

        // Create popular routes
        $routes = [
            [
                'from_location_id' => $kazakhstan->id,
                'to_location_id' => $uae->id,
                'priority' => 10,
                'description' => 'Популярный маршрут для бизнеса'
            ],
            [
                'from_location_id' => $kazakhstan->id,
                'to_location_id' => $indonesia->id,
                'priority' => 10,
                'description' => 'Популярный маршрут для отдыха'
            ],
            [
                'from_location_id' => $kazakhstan->id,
                'to_location_id' => $turkey->id,
                'priority' => 8,
                'description' => 'Туристический маршрут',
                'is_active' => false
            ],
        ];

        foreach ($routes as $routeData) {
            Route::query()->updateOrCreate(
                [
                    'from_location_id' => $routeData['from_location_id'],
                    'to_location_id' => $routeData['to_location_id']
                ],
                $routeData
            );
        }

        $this->command->info('Routes seeded successfully.');
    }
}
