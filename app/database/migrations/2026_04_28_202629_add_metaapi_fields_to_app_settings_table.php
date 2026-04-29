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
        Schema::table('app_settings', function (Blueprint $table) {
            $table->text('metaapi_token')->nullable()->after('perplexity_model');
            $table->string('metaapi_account_id')->nullable()->after('metaapi_token');
            $table->string('metaapi_region')->nullable()->default('new-york')->after('metaapi_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['metaapi_token', 'metaapi_account_id', 'metaapi_region']);
        });
    }
};
