<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('send_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('from_date');
            $table->timestamp('to_date');
            $table->string('size_type')->nullable();
            $table->string('description')->nullable();
            $table->string('status');
            $table->integer('price')->nullable();
            $table->string('currency', 255)->nullable();
            $table->integer('matched_delivery_id')->nullable();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->onDelete('set null');
            $table->timestamps();

            // Add indexes
            $table->index('from_location_id', 'send_requests_from_location_id_index');
            $table->index('to_location_id', 'send_requests_to_location_id_index');
            $table->index(['from_location_id', 'to_location_id', 'status'], 'send_requests_route_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('send_requests');
    }
};