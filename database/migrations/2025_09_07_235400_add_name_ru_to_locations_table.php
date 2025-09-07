<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('name_ru', 255)->nullable()->after('name');
            
            // Add index for name_ru
            $table->index('name_ru', 'locations_name_ru_index');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex('locations_name_ru_index');
            $table->dropColumn('name_ru');
        });
    }
};