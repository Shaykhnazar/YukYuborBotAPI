<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            $table->renameColumn('request_type', 'offer_type');
        });
    }

    public function down(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            $table->renameColumn('offer_type', 'request_type');
        });
    }
};