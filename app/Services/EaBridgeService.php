<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\Mt5EaCommand;
use App\Models\Mt5EaTerminal;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EaBridgeService
{
    public function resolveToken(): string
    {
        $settings = AppSetting::singleton();

        if (! empty($settings->ea_bridge_token)) {
            return (string) $settings->ea_bridge_token;
        }

        $token = Str::random(64);
        $settings->ea_bridge_token = $token;
        $settings->save();

        return $token;
    }

    public function regenerateToken(): string
    {
        $settings = AppSetting::singleton();
        $token = Str::random(64);
        $settings->ea_bridge_token = $token;
        $settings->save();

        return $token;
    }

    public function tokenIsValid(?string $token): bool
    {
        if ($token === null || trim($token) === '') {
            return false;
        }

        $settings = AppSetting::singleton();

        return ! empty($settings->ea_bridge_token)
            && hash_equals((string) $settings->ea_bridge_token, $token);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: bool, command: ?array<string, mixed>}
     */
    public function handlePoll(array $payload): array
    {
        $terminal = $this->upsertTerminal($payload);
        $this->applyCommandResult($payload['command_result'] ?? null);

        $command = $this->claimNextCommand($terminal);

        return [
            'ok' => true,
            'command' => $command?->toEaPayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function queueCommand(array $data): Mt5EaCommand
    {
        $action = strtoupper(trim((string) ($data['action'] ?? '')));

        if (! in_array($action, Mt5EaCommand::ALLOWED_ACTIONS, true)) {
            throw new InvalidArgumentException('Unsupported action: '.$action);
        }

        $accountLogin = isset($data['account_login']) ? (int) $data['account_login'] : null;
        $terminalId = null;

        if ($accountLogin !== null) {
            $terminal = Mt5EaTerminal::query()
                ->where('account_login', $accountLogin)
                ->orderByDesc('last_seen_at')
                ->first();

            $terminalId = $terminal?->id;
        }

        return Mt5EaCommand::query()->create([
            'mt5_ea_terminal_id' => $terminalId,
            'account_login' => $accountLogin,
            'action' => $action,
            'symbol' => isset($data['symbol']) ? strtoupper(trim((string) $data['symbol'])) : null,
            'lot' => isset($data['lot']) ? (float) $data['lot'] : null,
            'sl' => isset($data['sl']) ? (float) $data['sl'] : null,
            'tp' => isset($data['tp']) ? (float) $data['tp'] : null,
            'ticket' => isset($data['ticket']) ? (int) $data['ticket'] : null,
            'status' => Mt5EaCommand::STATUS_PENDING,
            'queued_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertTerminal(array $payload): Mt5EaTerminal
    {
        $login = (int) ($payload['login'] ?? 0);
        $server = trim((string) ($payload['server'] ?? ''));

        if ($login <= 0) {
            throw new InvalidArgumentException('login is required.');
        }

        return Mt5EaTerminal::query()->updateOrCreate(
            [
                'account_login' => $login,
                'server' => $server !== '' ? $server : null,
            ],
            [
                'terminal_name' => $payload['terminal_name'] ?? null,
                'broker_company' => $payload['broker_company'] ?? null,
                'balance' => isset($payload['balance']) ? (float) $payload['balance'] : null,
                'equity' => isset($payload['equity']) ? (float) $payload['equity'] : null,
                'margin' => isset($payload['margin']) ? (float) $payload['margin'] : null,
                'free_margin' => isset($payload['free_margin']) ? (float) $payload['free_margin'] : null,
                'currency' => $payload['currency'] ?? null,
                'trade_allowed' => (bool) ($payload['trade_allowed'] ?? false),
                'positions' => is_array($payload['positions'] ?? null) ? $payload['positions'] : [],
                'last_seen_at' => now(),
            ]
        );
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function applyCommandResult(?array $result): void
    {
        if ($result === null || ! isset($result['id'])) {
            return;
        }

        $command = Mt5EaCommand::query()->find((int) $result['id']);

        if ($command === null || $command->status === Mt5EaCommand::STATUS_COMPLETED) {
            return;
        }

        $ok = (bool) ($result['ok'] ?? false);

        $command->fill([
            'status' => $ok ? Mt5EaCommand::STATUS_COMPLETED : Mt5EaCommand::STATUS_FAILED,
            'error_message' => $ok ? null : (string) ($result['message'] ?? 'EA reported failure'),
            'result_payload' => $result,
            'completed_at' => now(),
        ]);
        $command->save();
    }

    private function claimNextCommand(Mt5EaTerminal $terminal): ?Mt5EaCommand
    {
        $command = Mt5EaCommand::query()
            ->where('status', Mt5EaCommand::STATUS_PENDING)
            ->where(function ($query) use ($terminal) {
                $query->whereNull('account_login')
                    ->orWhere('account_login', $terminal->account_login);
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($command === null) {
            return null;
        }

        $command->fill([
            'mt5_ea_terminal_id' => $terminal->id,
            'account_login' => $terminal->account_login,
            'status' => Mt5EaCommand::STATUS_SENT,
            'sent_at' => now(),
        ]);
        $command->save();

        return $command;
    }
}
