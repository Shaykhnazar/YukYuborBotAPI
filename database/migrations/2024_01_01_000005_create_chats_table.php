<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('send_request_id')->nullable()->constrained('send_requests')->onDelete('cascade');
            $table->foreignId('delivery_request_id')->nullable()->constrained('delivery_requests')->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('receiver_id')->constrained('users')->onDelete('cascade');
            $table->string('status', 50)->default('active');
            $table->timestamps();

            // Add indexes
            $table->index(['sender_id', 'receiver_id'], 'idx_chats_sender_receiver');
            $table->index(['send_request_id', 'delivery_request_id'], 'idx_chats_requests');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};