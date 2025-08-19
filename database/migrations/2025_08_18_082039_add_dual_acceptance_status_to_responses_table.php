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
            $table->string('deliverer_status')->default('pending')->after('status');
            $table->string('sender_status')->default('pending')->after('deliverer_status');
            $table->string('overall_status')->default('pending')->after('sender_status');
            
            // Add indexes for efficient querying on new status columns
            $table->index('deliverer_status', 'idx_responses_deliverer_status');
            $table->index('sender_status', 'idx_responses_sender_status');
            $table->index('overall_status', 'idx_responses_overall_status');
            
            // Composite index for filtering by overall status and type
            $table->index(['overall_status', 'response_type'], 'idx_responses_overall_status_type');
            
            // Composite index for user queries with status
            $table->index(['user_id', 'overall_status'], 'idx_responses_user_overall_status');
            $table->index(['responder_id', 'overall_status'], 'idx_responses_responder_overall_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex('idx_responses_deliverer_status');
            $table->dropIndex('idx_responses_sender_status'); 
            $table->dropIndex('idx_responses_overall_status');
            $table->dropIndex('idx_responses_overall_status_type');
            $table->dropIndex('idx_responses_user_overall_status');
            $table->dropIndex('idx_responses_responder_overall_status');
            
            // Then drop columns
            $table->dropColumn(['deliverer_status', 'sender_status', 'overall_status']);
        });
    }
};
