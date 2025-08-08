<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('text');
            $table->smallInteger('rating');
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->integer('request_id')->nullable();
            $table->string('request_type', 20)->nullable();
            $table->timestamps();

            // Add unique constraint
            $table->unique(['user_id', 'owner_id', 'request_id', 'request_type'], 'unique_review_per_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};