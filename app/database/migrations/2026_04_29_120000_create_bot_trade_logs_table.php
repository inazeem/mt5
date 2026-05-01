<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_trade_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 50);
            $table->string('status', 30);
            $table->string('symbol', 40)->nullable();
            $table->string('side', 10)->nullable();
            $table->decimal('lot_size', 10, 2)->nullable();
            $table->decimal('entry_price', 16, 6)->nullable();
            $table->decimal('take_profit', 16, 6)->nullable();
            $table->decimal('stop_loss', 16, 6)->nullable();
            $table->decimal('spread_pips', 10, 3)->nullable();
            $table->decimal('signal_delta_pips', 10, 3)->nullable();
            $table->string('ai_provider', 30)->nullable();
            $table->string('ai_decision', 30)->nullable();
            $table->text('ai_summary')->nullable();
            $table->text('message')->nullable();
            $table->text('error_message')->nullable();
            $table->json('meta_payload')->nullable();
            $table->json('meta_response')->nullable();
            $table->timestamps();

            $table->index(['created_at', 'status']);
            $table->index(['symbol', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_trade_logs');
    }
};
