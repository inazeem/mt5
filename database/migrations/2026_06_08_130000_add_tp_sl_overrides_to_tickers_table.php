<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickers', function (Blueprint $table) {
            $table->decimal('max_tp_pips', 10, 3)->nullable()->after('max_spread_pips');
            $table->decimal('max_sl_pips', 10, 3)->nullable()->after('max_tp_pips');
        });
    }

    public function down(): void
    {
        Schema::table('tickers', function (Blueprint $table) {
            $table->dropColumn(['max_tp_pips', 'max_sl_pips']);
        });
    }
};
