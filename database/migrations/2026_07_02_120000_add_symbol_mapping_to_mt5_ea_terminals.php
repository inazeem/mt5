<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mt5_ea_terminals', function (Blueprint $table) {
            $table->string('symbol_suffix', 20)->nullable()->after('broker_company');
            $table->json('symbol_map')->nullable()->after('symbol_suffix');
        });
    }

    public function down(): void
    {
        Schema::table('mt5_ea_terminals', function (Blueprint $table) {
            $table->dropColumn(['symbol_suffix', 'symbol_map']);
        });
    }
};
