<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mt5_ea_terminals', function (Blueprint $table) {
            $table->unsignedBigInteger('account_login')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('mt5_ea_terminals', function (Blueprint $table) {
            $table->unsignedBigInteger('account_login')->nullable(false)->change();
        });
    }
};
