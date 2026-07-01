<?php

use App\Models\Mt5EaTerminal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mt5_ea_terminals', function (Blueprint $table) {
            $table->text('api_token')->nullable()->after('is_demo');
            $table->string('api_token_hash', 64)->nullable()->unique()->after('api_token');
        });

        Schema::table('mt5_ea_commands', function (Blueprint $table) {
            $table->string('source', 24)->nullable()->after('bot_key');
        });

        Mt5EaTerminal::query()->whereNull('api_token_hash')->each(function (Mt5EaTerminal $terminal): void {
            $token = Str::random(64);
            $terminal->forceFill([
                'api_token' => $token,
                'api_token_hash' => hash('sha256', $token),
            ])->save();
        });
    }

    public function down(): void
    {
        Schema::table('mt5_ea_commands', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('mt5_ea_terminals', function (Blueprint $table) {
            $table->dropColumn(['api_token', 'api_token_hash']);
        });
    }
};
