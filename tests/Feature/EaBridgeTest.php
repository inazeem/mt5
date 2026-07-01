<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\BotTradeLog;
use App\Models\Mt5EaCommand;
use App\Models\Mt5EaTerminal;
use App\Services\Brokers\BrokerResolver;
use App\Services\Brokers\EaBridgeBroker;
use App\Services\EaBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EaBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function bearerToken(): string
    {
        return app(EaBridgeService::class)->resolveToken();
    }

    private function seedOnlineTerminal(array $overrides = []): Mt5EaTerminal
    {
        return Mt5EaTerminal::query()->create(array_merge([
            'instance_key' => 'demo-1',
            'display_name' => 'Demo Terminal',
            'enabled' => true,
            'is_demo' => true,
            'account_login' => 12345,
            'server' => 'Broker-Demo',
            'balance' => 10000,
            'equity' => 10050,
            'trade_allowed' => true,
            'positions' => [],
            'market_quotes' => [
                'GBPUSD' => ['bid' => 1.2650, 'ask' => 1.2652],
            ],
            'market_candles' => [
                'GBPUSD:15m' => [
                    ['open' => 1.2640, 'high' => 1.2660, 'low' => 1.2630, 'close' => 1.2650],
                ],
            ],
            'last_seen_at' => now(),
        ], $overrides));
    }

    public function test_poll_requires_valid_token(): void
    {
        $response = $this->postJson('/api/ea/poll', [
            'login' => 12345,
        ]);

        $response->assertUnauthorized();
    }

    public function test_poll_registers_terminal_and_returns_queued_command(): void
    {
        AppSetting::singleton();
        $this->seedOnlineTerminal();

        $command = app(EaBridgeService::class)->queueCommand([
            'action' => 'BUY',
            'symbol' => 'GBPUSD',
            'lot' => 0.1,
            'sl' => 20,
            'tp' => 40,
            'mt5_instance_key' => 'demo-1',
        ]);

        $response = $this->withToken($this->bearerToken(), 'Bearer')->postJson('/api/ea/poll', [
            'login' => 12345,
            'server' => 'Broker-Demo',
            'instance_key' => 'demo-1',
            'balance' => 10000,
            'equity' => 10050,
            'trade_allowed' => true,
            'positions' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('command.id', $command->id)
            ->assertJsonPath('command.action', 'BUY')
            ->assertJsonStructure(['watch_symbols', 'candle_requests']);

        $command->refresh();
        $this->assertSame(Mt5EaCommand::STATUS_SENT, $command->status);
    }

    public function test_poll_applies_command_result_and_updates_bot_trade_log(): void
    {
        AppSetting::singleton();
        $terminal = $this->seedOnlineTerminal();

        $command = app(EaBridgeService::class)->queueCommand([
            'action' => 'BUY',
            'symbol' => 'EURUSD',
            'lot' => 0.01,
            'mt5_instance_key' => 'demo-1',
        ]);

        $log = BotTradeLog::query()->create([
            'bot_key' => 'test-bot',
            'event_type' => 'trade_open',
            'status' => 'pending',
            'symbol' => 'EURUSD',
            'side' => 'buy',
        ]);

        $command->update(['bot_trade_log_id' => $log->id, 'bot_key' => 'test-bot']);

        $this->withToken($this->bearerToken(), 'Bearer')->postJson('/api/ea/poll', [
            'login' => $terminal->account_login,
            'server' => $terminal->server,
            'positions' => [],
        ])->assertOk();

        $response = $this->withToken($this->bearerToken(), 'Bearer')->postJson('/api/ea/poll', [
            'login' => $terminal->account_login,
            'server' => $terminal->server,
            'positions' => [],
            'command_result' => [
                'id' => $command->id,
                'ok' => true,
                'message' => 'Order placed',
                'ticket' => 555001,
            ],
        ]);

        $response->assertOk()->assertJsonPath('command', null);

        $command->refresh();
        $log->refresh();
        $this->assertSame(Mt5EaCommand::STATUS_COMPLETED, $command->status);
        $this->assertSame('success', $log->status);
        $this->assertSame('555001', $log->position_id);
    }

    public function test_broker_resolver_uses_ea_bridge_for_forex_profile(): void
    {
        $this->seedOnlineTerminal();

        $broker = app(BrokerResolver::class)->forProfile(['forex'], [
            'mt5_instance_key' => 'demo-1',
        ]);

        $this->assertInstanceOf(EaBridgeBroker::class, $broker);

        $quote = $broker->getTickerPrice('GBPUSD');
        $this->assertSame(1.2650, $quote['bid']);
    }
}
