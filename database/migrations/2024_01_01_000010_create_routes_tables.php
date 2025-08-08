<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Suggested routes table
        Schema::create('suggested_routes', function (Blueprint $table) {
            $table->id();
            $table->string('from_location', 255);
            $table->string('to_location', 255);
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('status', 50)->default('pending');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();

            // Add indexes
            $table->index('status', 'suggested_routes_status_index');
            $table->index(['from_location', 'to_location'], 'suggested_routes_from_to_index');
        });

        // Routes table
        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_location_id')->constrained('locations')->onDelete('cascade');
            $table->foreignId('to_location_id')->constrained('locations')->onDelete('cascade');
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            // Add unique constraint and check constraint (SQLite doesn't support check constraints exactly like PostgreSQL)
            $table->unique(['from_location_id', 'to_location_id'], 'routes_unique_direction');

            // Add indexes
            $table->index('from_location_id', 'routes_from_location_index');
            $table->index('to_location_id', 'routes_to_location_index');
            $table->index('is_active', 'routes_active_index');
            $table->index('priority', 'routes_priority_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
        Schema::dropIfExists('suggested_routes');
    }
};
