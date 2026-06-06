<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('app_settings', 'bot_entry_timeframe')) {
                $table->string('bot_entry_timeframe', 10)->nullable()->after('bot_signal_timeframes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            if (Schema::hasColumn('app_settings', 'bot_entry_timeframe')) {
                $table->dropColumn('bot_entry_timeframe');
            }
        });
    }
};
