<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mt5_ea_terminals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_login')->index();
            $table->string('server')->nullable();
            $table->string('terminal_name')->nullable();
            $table->string('broker_company')->nullable();
            $table->decimal('balance', 18, 2)->nullable();
            $table->decimal('equity', 18, 2)->nullable();
            $table->decimal('margin', 18, 2)->nullable();
            $table->decimal('free_margin', 18, 2)->nullable();
            $table->string('currency', 12)->nullable();
            $table->boolean('trade_allowed')->default(false);
            $table->json('positions')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['account_login', 'server']);
        });

        Schema::create('mt5_ea_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mt5_ea_terminal_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('account_login')->nullable()->index();
            $table->string('action', 32);
            $table->string('symbol', 32)->nullable();
            $table->decimal('lot', 12, 4)->nullable();
            $table->decimal('sl', 12, 4)->nullable();
            $table->decimal('tp', 12, 4)->nullable();
            $table->unsignedBigInteger('ticket')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->json('result_payload')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mt5_ea_commands');
        Schema::dropIfExists('mt5_ea_terminals');
    }
};
