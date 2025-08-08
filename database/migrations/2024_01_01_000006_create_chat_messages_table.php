<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->onDelete('cascade');
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->text('message');
            $table->string('message_type', 20)->default('text');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            // Add indexes
            $table->index('chat_id', 'idx_chat_messages_chat_id');
            $table->index('sender_id', 'idx_chat_messages_sender');
            $table->index('created_at', 'idx_chat_messages_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};