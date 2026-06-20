<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('alpaca_api_key_id')->nullable()->after('metaapi_region');
            $table->text('alpaca_api_secret')->nullable()->after('alpaca_api_key_id');
            $table->boolean('alpaca_paper')->default(true)->after('alpaca_api_secret');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['alpaca_api_key_id', 'alpaca_api_secret', 'alpaca_paper']);
        });
    }
};
