<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_trade_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('ai_confidence')->nullable()->after('ai_decision');
        });
    }

    public function down(): void
    {
        Schema::table('bot_trade_logs', function (Blueprint $table) {
            $table->dropColumn('ai_confidence');
        });
    }
};
