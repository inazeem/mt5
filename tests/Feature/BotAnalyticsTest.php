<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\BotTradeLog;
use App\Models\Mt5EaTerminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BotAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_live_uses_broker_open_positions_first(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\EnsureOwnerEmail::class);

        $user = User::factory()->create(['email' => 'owner@example.com']);
        AppSetting::singleton()->update([
            'owner_email' => 'owner@example.com',
            'bot_profiles' => [[
                'key' => 'default',
                'name' => 'default',
                'enabled' => true,
                'broker' => 'ea_bridge',
                'mt5_instance_keys' => ['icmarketdemo'],
            ]],
        ]);

        $token = 'test-'.Str::random(48);
        Mt5EaTerminal::query()->create([
            'instance_key' => 'icmarketdemo',
            'display_name' => 'ICMARKET DEMO',
            'enabled' => true,
            'is_demo' => true,
            'api_token' => $token,
            'api_token_hash' => hash('sha256', $token),
            'account_login' => 12345,
            'server' => 'ICMarkets-Demo',
            'trade_allowed' => true,
            'last_seen_at' => now(),
            'positions' => [[
                'ticket' => 555001,
                'symbol' => 'EURUSD',
                'type' => 'BUY',
                'lot' => 0.01,
                'price_open' => 1.1000,
                'sl' => 1.0950,
                'tp' => 1.1100,
                'profit' => 1.25,
            ]],
            'market_quotes' => [
                'EURUSD' => ['bid' => 1.1001, 'ask' => 1.1003],
            ],
        ]);

        BotTradeLog::query()->create([
            'bot_key' => 'default',
            'event_type' => 'trade_open',
            'status' => 'success',
            'symbol' => 'EURUSD',
            'side' => 'buy',
            'trade_outcome' => 'PENDING',
            'lot_size' => 0.01,
            'entry_price' => 1.1000,
            'meta_payload' => ['ea_instance_key' => 'icmarketdemo'],
        ]);

        $response = $this->actingAs($user)->getJson(route('bot.analytics.live'));

        $response->assertOk();
        $response->assertJsonPath('stats.active_positions', 1);
        $response->assertJsonPath('positions.0.symbol', 'EURUSD');
        $response->assertJsonPath('positions.0.terminal', 'icmarketdemo');
        $response->assertJsonPath('positions.0.positionId', '555001');

        $this->assertSame('555001', BotTradeLog::query()->value('position_id'));
    }
}
