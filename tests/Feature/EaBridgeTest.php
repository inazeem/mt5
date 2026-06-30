<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Mt5EaCommand;
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

        $command = app(EaBridgeService::class)->queueCommand([
            'action' => 'BUY',
            'symbol' => 'GBPUSD',
            'lot' => 0.1,
            'sl' => 20,
            'tp' => 40,
        ]);

        $response = $this->withToken($this->bearerToken(), 'Bearer')->postJson('/api/ea/poll', [
            'login' => 12345,
            'server' => 'Broker-Demo',
            'balance' => 10000,
            'equity' => 10050,
            'trade_allowed' => true,
            'positions' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('command.id', $command->id)
            ->assertJsonPath('command.action', 'BUY')
            ->assertJsonPath('command.symbol', 'GBPUSD');

        $command->refresh();
        $this->assertSame(Mt5EaCommand::STATUS_SENT, $command->status);
    }

    public function test_poll_applies_command_result(): void
    {
        AppSetting::singleton();

        $command = app(EaBridgeService::class)->queueCommand([
            'action' => 'BUY',
            'symbol' => 'EURUSD',
            'lot' => 0.01,
        ]);

        $this->withToken($this->bearerToken(), 'Bearer')->postJson('/api/ea/poll', [
            'login' => 999,
            'server' => 'Broker-Demo',
            'positions' => [],
        ])->assertOk();

        $response = $this->withToken($this->bearerToken(), 'Bearer')->postJson('/api/ea/poll', [
            'login' => 999,
            'server' => 'Broker-Demo',
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
        $this->assertSame(Mt5EaCommand::STATUS_COMPLETED, $command->status);
        $this->assertSame(555001, $command->result_payload['ticket'] ?? null);
    }
}
