<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickers', function (Blueprint $table) {
            // null = auto-detect (FX logic); set explicitly for stocks/indices/crypto.
            $table->decimal('pip_size', 12, 8)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('tickers', function (Blueprint $table) {
            $table->dropColumn('pip_size');
        });
    }
};
