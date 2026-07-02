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
use Illuminate\Support\Str;
use Tests\TestCase;

class EaBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function seedOnlineTerminal(array $overrides = []): Mt5EaTerminal
    {
        $token = (string) ($overrides['api_token'] ?? ('test-'.Str::random(48)));
        unset($overrides['api_token'], $overrides['api_token_hash']);

        return Mt5EaTerminal::query()->create(array_merge([
            'instance_key' => 'demo-1',
            'display_name' => 'Demo Terminal',
            'enabled' => true,
            'is_demo' => true,
            'api_token' => $token,
            'api_token_hash' => Mt5EaTerminal::hashToken($token),
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
        $terminal = $this->seedOnlineTerminal();
        $token = (string) $terminal->api_token;

        $command = app(EaBridgeService::class)->queueCommand([
            'action' => 'BUY',
            'symbol' => 'GBPUSD',
            'lot' => 0.1,
            'sl' => 20,
            'tp' => 40,
            'mt5_instance_key' => 'demo-1',
        ]);

        $response = $this->withToken($token, 'Bearer')->postJson('/api/ea/poll', [
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
        $token = (string) $terminal->api_token;

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

        $this->withToken($token, 'Bearer')->postJson('/api/ea/poll', [
            'login' => $terminal->account_login,
            'server' => $terminal->server,
            'positions' => [],
        ])->assertOk();

        $response = $this->withToken($token, 'Bearer')->postJson('/api/ea/poll', [
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

    public function test_create_instance_and_queue_test_trade(): void
    {
        AppSetting::singleton();
        $terminal = app(EaBridgeService::class)->createInstance([
            'display_name' => 'IC Markets Demo',
            'is_demo' => true,
        ]);

        $this->assertNotEmpty($terminal->api_token);
        $this->assertNotEmpty($terminal->api_token_hash);

        $terminal->update(['last_seen_at' => now()]);

        $command = app(EaBridgeService::class)->queueTestTrade($terminal);
        $this->assertSame('test', $command->source);
        $this->assertSame('BUY', $command->action);
    }

    public function test_delete_instance(): void
    {
        AppSetting::singleton();
        $terminal = app(EaBridgeService::class)->createInstance([
            'display_name' => 'Delete Me',
            'is_demo' => true,
        ]);

        app(EaBridgeService::class)->deleteInstance($terminal);

        $this->assertDatabaseMissing('mt5_ea_terminals', ['id' => $terminal->id]);
    }

    public function test_reveal_token_without_regenerating(): void
    {
        AppSetting::singleton();
        $terminal = app(EaBridgeService::class)->createInstance([
            'display_name' => 'Reveal Test',
            'is_demo' => true,
        ]);

        $originalHash = $terminal->api_token_hash;
        $revealed = app(EaBridgeService::class)->revealTerminalToken($terminal);

        $this->assertNotEmpty($revealed);
        $terminal->refresh();
        $this->assertSame($originalHash, $terminal->api_token_hash);
    }

    public function test_broker_resolver_uses_metaapi_when_profile_requests_it(): void
    {
        $this->seedOnlineTerminal();

        $broker = app(BrokerResolver::class)->forProfile(['forex'], [
            'mt5_broker' => 'metaapi',
        ]);

        $this->assertInstanceOf(\App\Services\Mt5Service::class, $broker);
    }

    public function test_profile_instance_keys_supports_multi_and_legacy(): void
    {
        $keys = EaBridgeService::profileInstanceKeys([
            'mt5_instance_keys' => ['demo-a', 'demo-b'],
            'mt5_instance_key' => 'demo-c',
        ]);

        $this->assertSame(['demo-a', 'demo-b', 'demo-c'], $keys);
    }

    public function test_queue_command_maps_symbol_for_spread_bet_terminal(): void
    {
        AppSetting::singleton();
        $terminal = $this->seedOnlineTerminal([
            'instance_key' => 'pepper-demo',
            'symbol_suffix' => 'spread_bet',
        ]);

        $command = app(EaBridgeService::class)->queueCommand([
            'action' => 'BUY',
            'symbol' => 'GBPUSD',
            'lot' => 0.01,
            'mt5_instance_key' => $terminal->instance_key,
        ]);

        $this->assertSame('GBPUSD_SB', $command->symbol);
    }

    public function test_poll_watch_plan_uses_broker_symbols(): void
    {
        AppSetting::singleton();
        $terminal = $this->seedOnlineTerminal([
            'instance_key' => 'pepper-demo',
            'symbol_suffix' => 'spread_bet',
            'market_quotes' => [
                'GBPUSD_SB' => ['bid' => 1.2650, 'ask' => 1.2652],
            ],
        ]);
        $token = (string) $terminal->api_token;

        $response = $this->withToken($token, 'Bearer')->postJson('/api/ea/poll', [
            'login' => $terminal->account_login,
            'server' => $terminal->server,
            'positions' => [],
        ]);

        $response->assertOk();
        $watchSymbols = $response->json('watch_symbols') ?? [];
        $this->assertContains('GBPUSD_SB', $watchSymbols);
        $this->assertNotContains('GBPUSD', $watchSymbols);
    }

    public function test_poll_merges_quote_cache_instead_of_replacing(): void
    {
        AppSetting::singleton();
        $terminal = $this->seedOnlineTerminal([
            'market_quotes' => [
                'EURUSD' => ['bid' => 1.08, 'ask' => 1.0802],
            ],
        ]);
        $token = (string) $terminal->api_token;

        $this->withToken($token, 'Bearer')->postJson('/api/ea/poll', [
            'login' => $terminal->account_login,
            'server' => $terminal->server,
            'positions' => [],
            'quotes' => [
                'GBPUSD' => ['bid' => 1.2650, 'ask' => 1.2652],
            ],
        ])->assertOk();

        $terminal->refresh();
        $quotes = $terminal->market_quotes ?? [];
        $this->assertArrayHasKey('EURUSD', $quotes);
        $this->assertArrayHasKey('GBPUSD', $quotes);
    }
}
