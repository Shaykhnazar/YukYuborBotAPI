<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Access users table
        Schema::create('access_users', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');

            $table->index('role', 'ix_access_users_role');
        });

        // Sessions table
        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
        });

        // Support requests table
        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('telegram_user_id')->constrained('telegram_users')->onDelete('cascade');
            $table->string('req_type')->nullable();
            $table->integer('req_id')->nullable();
            $table->string('message');
            $table->timestamps();
        });

        // Telegram auth table
        Schema::create('telegram_auth', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('email', 255);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_auth');
        Schema::dropIfExists('support_requests');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('access_users');
    }
};
