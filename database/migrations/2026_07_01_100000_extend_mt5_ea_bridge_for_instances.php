<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mt5_ea_terminals', function (Blueprint $table) {
            $table->string('instance_key', 100)->nullable()->unique()->after('id');
            $table->string('display_name')->nullable()->after('instance_key');
            $table->boolean('enabled')->default(true)->after('display_name');
            $table->boolean('is_demo')->default(true)->after('enabled');
            $table->json('market_quotes')->nullable()->after('positions');
            $table->json('market_candles')->nullable()->after('market_quotes');
        });

        Schema::table('mt5_ea_commands', function (Blueprint $table) {
            $table->string('mt5_instance_key', 100)->nullable()->index()->after('account_login');
            $table->foreignId('bot_trade_log_id')->nullable()->after('mt5_instance_key');
            $table->string('bot_key', 100)->nullable()->after('bot_trade_log_id');
        });
    }

    public function down(): void
    {
        Schema::table('mt5_ea_commands', function (Blueprint $table) {
            $table->dropColumn(['mt5_instance_key', 'bot_trade_log_id', 'bot_key']);
        });

        Schema::table('mt5_ea_terminals', function (Blueprint $table) {
            $table->dropColumn([
                'instance_key',
                'display_name',
                'enabled',
                'is_demo',
                'market_quotes',
                'market_candles',
            ]);
        });
    }
};
