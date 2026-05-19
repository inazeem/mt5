<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_trade_logs', function (Blueprint $table) {
            $table->string('bot_key', 100)->nullable()->after('id');
            $table->string('bot_name', 120)->nullable()->after('bot_key');
            $table->index(['bot_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bot_trade_logs', function (Blueprint $table) {
            $table->dropIndex(['bot_key', 'created_at']);
            $table->dropColumn(['bot_key', 'bot_name']);
        });
    }
};
