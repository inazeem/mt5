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
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('owner_email')->nullable();
            $table->boolean('demo_only')->default(true);

            $table->string('mt5_server')->nullable();
            $table->unsignedInteger('mt5_port')->default(443);
            $table->string('mt5_manager_login')->nullable();
            $table->text('mt5_manager_password')->nullable();
            $table->string('mt5_account_login')->nullable();
            $table->unsignedTinyInteger('mt5_action_deal')->default(1);
            $table->unsignedInteger('mt5_volume_multiplier')->default(10000);

            $table->string('ai_provider')->default('claude');
            $table->text('claude_api_key')->nullable();
            $table->string('claude_model')->default('claude-3-5-sonnet-latest');
            $table->text('perplexity_api_key')->nullable();
            $table->string('perplexity_model')->default('sonar-pro');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
