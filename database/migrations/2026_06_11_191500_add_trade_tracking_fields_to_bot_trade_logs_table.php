<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_trade_logs', function (Blueprint $table) {
            $table->string('order_id', 60)->nullable()->after('side');
            $table->string('position_id', 60)->nullable()->after('order_id');
            $table->string('linked_trade', 80)->nullable()->after('position_id');
            $table->string('trade_outcome', 20)->nullable()->after('linked_trade');
            $table->decimal('trade_pnl', 14, 2)->nullable()->after('trade_outcome');
            $table->timestamp('trade_resolved_at')->nullable()->after('trade_pnl');

            $table->index(['event_type', 'trade_outcome', 'created_at'], 'bot_trade_logs_event_outcome_created_idx');
            $table->index(['position_id'], 'bot_trade_logs_position_id_idx');
            $table->index(['order_id'], 'bot_trade_logs_order_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bot_trade_logs', function (Blueprint $table) {
            $table->dropIndex('bot_trade_logs_event_outcome_created_idx');
            $table->dropIndex('bot_trade_logs_position_id_idx');
            $table->dropIndex('bot_trade_logs_order_id_idx');
            $table->dropColumn([
                'order_id',
                'position_id',
                'linked_trade',
                'trade_outcome',
                'trade_pnl',
                'trade_resolved_at',
            ]);
        });
    }
};
