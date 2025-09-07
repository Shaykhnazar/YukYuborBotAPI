<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Location;
use App\Models\Route;

class RoutesSeeder extends Seeder
{
    public function run()
    {
        // Get main regional centers (capital and major regions)
        $tashkent = Location::where('name', 'город Ташкент')->first();
        $samarkand = Location::where('name', 'Самаркандская область')->first();
        $bukhara = Location::where('name', 'Бухарская область')->first();
        $fergana = Location::where('name', 'Ферганская область')->first();
        $andijan = Location::where('name', 'Андижанская область')->first();
        $namangan = Location::where('name', 'Наманганская область')->first();
        $khorezm = Location::where('name', 'Хорезмская область')->first();
        $karakalpakstan = Location::where('name', 'Республика Каракалпакстан')->first();

        // Check if main locations exist
        if (!$tashkent) {
            $this->command->warn('Main locations not found. Please run LocationsSeeder first.');
            return;
        }

        // Create popular domestic routes from Tashkent (capital) to other regions
        $routes = [
            [
                'from_location_id' => $tashkent->id,
                'to_location_id' => $samarkand->id,
                'priority' => 10,
                'description' => 'Тошкент - Самарқанд (популярный туристический маршрут)'
            ],
            [
                'from_location_id' => $tashkent->id,
                'to_location_id' => $bukhara->id,
                'priority' => 9,
                'description' => 'Тошкент - Бухоро (исторический туристический маршрут)'
            ],
            [
                'from_location_id' => $tashkent->id,
                'to_location_id' => $fergana->id,
                'priority' => 8,
                'description' => 'Тошкент - Фарғона (деловые поездки)'
            ],
            [
                'from_location_id' => $tashkent->id,
                'to_location_id' => $andijan->id,
                'priority' => 7,
                'description' => 'Тошкент - Андижон (семейные связи)'
            ],
            [
                'from_location_id' => $tashkent->id,
                'to_location_id' => $namangan->id,
                'priority' => 7,
                'description' => 'Тошкент - Наманган (региональные связи)'
            ],
            [
                'from_location_id' => $tashkent->id,
                'to_location_id' => $khorezm->id,
                'priority' => 6,
                'description' => 'Тошкент - Хоразм (региональные поездки)'
            ],
            [
                'from_location_id' => $tashkent->id,
                'to_location_id' => $karakalpakstan->id,
                'priority' => 5,
                'description' => 'Тошкент - Қорақалпоғистон (длительные поездки)'
            ],
        ];

        // Add inter-regional routes
        if ($samarkand && $bukhara) {
            $routes[] = [
                'from_location_id' => $samarkand->id,
                'to_location_id' => $bukhara->id,
                'priority' => 8,
                'description' => 'Самарқанд - Бухоро (туристический)'
            ];
        }

        if ($fergana && $andijan && $namangan) {
            // Fergana Valley internal routes
            $routes[] = [
                'from_location_id' => $fergana->id,
                'to_location_id' => $andijan->id,
                'priority' => 9,
                'description' => 'Фарғона - Андижон (долина маршрут)'
            ];
            
            $routes[] = [
                'from_location_id' => $fergana->id,
                'to_location_id' => $namangan->id,
                'priority' => 8,
                'description' => 'Фарғона - Наманган (долина маршрут)'
            ];
        }

        // Insert routes
        foreach ($routes as $routeData) {
            if (isset($routeData['from_location_id']) && isset($routeData['to_location_id']) && 
                $routeData['from_location_id'] && $routeData['to_location_id']) {
                
                Route::query()->updateOrCreate(
                    [
                        'from_location_id' => $routeData['from_location_id'],
                        'to_location_id' => $routeData['to_location_id']
                    ],
                    $routeData
                );
            }
        }

        $this->command->info('Uzbekistan domestic routes seeded successfully.');
    }
}
