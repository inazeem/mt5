<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->decimal('bot_lot', 8, 4)->default(0.01)->after('metaapi_region');
            $table->decimal('bot_tp_pips', 8, 2)->default(25)->after('bot_lot');
            $table->decimal('bot_sl_pips', 8, 2)->default(15)->after('bot_tp_pips');
            $table->decimal('bot_trail_start_pips', 8, 2)->default(10)->after('bot_sl_pips');
            $table->decimal('bot_trail_pips', 8, 2)->default(8)->after('bot_trail_start_pips');
            $table->decimal('bot_min_move_pips', 8, 2)->default(3)->after('bot_trail_pips');
            $table->decimal('bot_max_spread_pips', 8, 2)->default(2.5)->after('bot_min_move_pips');
            $table->unsignedInteger('bot_cooldown_minutes')->default(30)->after('bot_max_spread_pips');
            $table->unsignedTinyInteger('bot_session_start_utc')->default(6)->after('bot_cooldown_minutes');
            $table->unsignedTinyInteger('bot_session_end_utc')->default(20)->after('bot_session_start_utc');
            $table->unsignedInteger('bot_max_trades_per_day')->default(20)->after('bot_session_end_utc');
            $table->decimal('bot_max_daily_loss_percent', 5, 2)->default(2)->after('bot_max_trades_per_day');
            $table->boolean('bot_ai_confirm')->default(true)->after('bot_max_daily_loss_percent');
            $table->unsignedInteger('bot_max_symbols')->default(200)->after('bot_ai_confirm');
            $table->unsignedTinyInteger('bot_ai_min_confidence')->default(70)->after('bot_max_symbols');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn([
                'bot_lot', 'bot_tp_pips', 'bot_sl_pips', 'bot_trail_start_pips', 'bot_trail_pips',
                'bot_min_move_pips', 'bot_max_spread_pips', 'bot_cooldown_minutes',
                'bot_session_start_utc', 'bot_session_end_utc', 'bot_max_trades_per_day',
                'bot_max_daily_loss_percent', 'bot_ai_confirm', 'bot_max_symbols', 'bot_ai_min_confidence',
            ]);
        });
    }
};
