<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
//       $this->call(RequestsSeeder::class);
       $this->call(LocationsSeeder::class);
       $this->call(RoutesSeeder::class);
    }

}
