<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->foreignId('parent_id')->nullable()->constrained('locations')->onDelete('cascade');
            $table->string('type', 50)->default('country');
            $table->string('country_code', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Add indexes
            $table->index('parent_id', 'locations_parent_id_index');
            $table->index('type', 'locations_type_index');
            $table->index('name', 'locations_name_index');
            $table->unique(['name', 'parent_id'], 'locations_name_parent_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};