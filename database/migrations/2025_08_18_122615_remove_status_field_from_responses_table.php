<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            // First drop indexes that reference the status column
            $table->dropIndex('idx_responses_user_status');
            $table->dropIndex('idx_responses_responder_status');
            
            // Then remove the old status column since we now use dual acceptance system
            // with deliverer_status, sender_status, and overall_status columns
            $table->dropColumn('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            // Restore the old status column for rollback
            $table->string('status')->default('pending')->after('response_type');
            
            // Recreate the original indexes
            $table->index(['user_id', 'status'], 'idx_responses_user_status');
            $table->index(['responder_id', 'status'], 'idx_responses_responder_status');
        });
    }
};
