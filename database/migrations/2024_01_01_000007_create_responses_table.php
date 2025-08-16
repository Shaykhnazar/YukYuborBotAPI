<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('responder_id')->constrained('users')->onDelete('cascade');
            $table->string('request_type', 20);
            $table->integer('request_id');
            $table->integer('offer_id');
            $table->string('status', 20)->default('pending');
            $table->foreignId('chat_id')->nullable()->constrained('chats')->onDelete('set null');
            $table->text('message')->nullable();
            $table->string('response_type', 20)->default('matching');
            $table->string('currency', 10)->nullable();
            $table->integer('amount')->nullable();
            $table->timestamps();

            // Add unique constraint
            $table->unique(['user_id', 'responder_id', 'request_type', 'request_id', 'offer_id'], 'unique_response');

            // Add indexes
            $table->index(['user_id', 'status'], 'idx_responses_user_status');
            $table->index(['responder_id', 'status'], 'idx_responses_responder_status');
            $table->index(['request_type', 'request_id'], 'idx_responses_request');
            $table->index('created_at', 'idx_responses_created_at');
            $table->index('response_type', 'idx_responses_response_type');
            $table->index('currency', 'idx_responses_currency');
            $table->index('amount', 'idx_responses_amount');
            $table->index(['currency', 'amount'], 'idx_responses_currency_amount');
            $table->index(['response_type', 'currency'], 'idx_responses_type_currency');
        });

        /*
    Example Usage Scenarios:
      For a matching response (automatic system match):
      - user_id: Deliverer who will see the send request
      - responder_id: Sender who created the send request
      - request_type: "send"
      - request_id: Deliverer's delivery request ID
      - offer_id: Sender's send request ID
      - response_type: "matching"
      - message, currency, amount: NULL

      For a manual response (user manually responds):
      - user_id: Request owner who will receive the response
      - responder_id: User who clicked "Откликнуться"
      - request_type: "send" or "delivery"
      - request_id: 0 (not used)
      - offer_id: The request ID being responded to
      - response_type: "manual"
      - message: Custom message from responder
      - currency, amount: Optional price proposal
    */
    }

    public function down(): void
    {
        Schema::dropIfExists('responses');
    }
};
