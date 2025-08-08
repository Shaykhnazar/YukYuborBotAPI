<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add foreign key from send_requests to delivery_requests
        Schema::table('send_requests', function (Blueprint $table) {
            $table->foreign('matched_delivery_id')
                  ->references('id')
                  ->on('delivery_requests')
                  ->onDelete('set null');
        });

        // Add foreign key from delivery_requests to send_requests
        Schema::table('delivery_requests', function (Blueprint $table) {
            $table->foreign('matched_send_id')
                  ->references('id')
                  ->on('send_requests')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_requests', function (Blueprint $table) {
            $table->dropForeign(['matched_send_id']);
        });

        Schema::table('send_requests', function (Blueprint $table) {
            $table->dropForeign(['matched_delivery_id']);
        });
    }
};