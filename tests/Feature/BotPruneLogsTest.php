<?php

namespace Tests\Feature;

use App\Models\BotTradeLog;
use App\Services\BotLogPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BotPruneLogsTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_deletes_old_signal_logs_and_cache_temp_keys(): void
    {
        config(['cache.default' => 'database', 'cache.prefix' => 'test-cache-']);

        $oldSignal = BotTradeLog::query()->create([
            'event_type' => 'signal',
            'status' => 'no_move',
            'symbol' => 'EURUSD',
        ]);
        $oldSignal->forceFill(['created_at' => now()->subDays(2)])->save();

        $oldTrade = BotTradeLog::query()->create([
            'event_type' => 'trade_open',
            'status' => 'success',
            'symbol' => 'EURUSD',
            'trade_outcome' => 'WIN',
        ]);
        $oldTrade->forceFill(['created_at' => now()->subDays(120)])->save();

        BotTradeLog::query()->create([
            'event_type' => 'trade_open',
            'status' => 'success',
            'symbol' => 'GBPUSD',
            'trade_outcome' => 'PENDING',
        ]);

        DB::table('cache')->insert([
            [
                'key' => 'test-cache-auto_bot_last_bid_default_eurusd',
                'value' => '1.1',
                'expiration' => now()->addDay()->timestamp,
            ],
            [
                'key' => 'test-cache-keep-me',
                'value' => '1.2',
                'expiration' => now()->addDay()->timestamp,
            ],
            [
                'key' => 'test-cache-expired',
                'value' => '1.3',
                'expiration' => now()->subHour()->timestamp,
            ],
        ]);

        $stats = app(BotLogPruner::class)->prune(signalDays: 1, tradeDays: 90);

        $this->assertSame(1, $stats['bot_signals_deleted']);
        $this->assertSame(1, $stats['bot_trades_deleted']);
        $this->assertSame(1, $stats['cache_expired_deleted']);
        $this->assertSame(1, $stats['cache_temp_deleted']);
        $this->assertDatabaseMissing('bot_trade_logs', ['symbol' => 'EURUSD', 'event_type' => 'signal']);
        $this->assertDatabaseHas('bot_trade_logs', ['symbol' => 'GBPUSD', 'trade_outcome' => 'PENDING']);
        $this->assertDatabaseHas('cache', ['key' => 'test-cache-keep-me']);
        $this->assertDatabaseMissing('cache', ['key' => 'test-cache-auto_bot_last_bid_default_eurusd']);
    }

    public function test_prune_logs_command_supports_dry_run(): void
    {
        $oldGuardrail = BotTradeLog::query()->create([
            'event_type' => 'guardrail',
            'status' => 'cycle_complete',
        ]);
        $oldGuardrail->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('bot:prune-logs --dry-run --signal-days=1')
            ->expectsOutputToContain('Would prune bot temp logs:')
            ->assertExitCode(0);

        $this->assertDatabaseCount('bot_trade_logs', 1);
    }
}
