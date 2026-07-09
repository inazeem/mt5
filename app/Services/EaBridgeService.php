<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\BotTradeLog;
use App\Models\Mt5EaCommand;
use App\Models\Mt5EaTerminal;
use App\Models\Ticker;
use App\Services\Brokers\EaBridgeBroker;
use App\Services\SymbolMapper;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class EaBridgeService
{
    private const MAX_WATCH_SYMBOLS = 64;

    private const MAX_CANDLE_REQUESTS = 24;

    public function __construct(
        private readonly SymbolMapper $symbolMapper,
    ) {}
    public function resolveTerminalFromToken(?string $token): ?Mt5EaTerminal
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        $hash = Mt5EaTerminal::hashToken(trim($token));

        return Mt5EaTerminal::query()
            ->where('api_token_hash', $hash)
            ->where('enabled', true)
            ->first();
    }

    /**
     * @deprecated Global token removed — each instance has its own token.
     */
    public function resolveToken(): string
    {
        $terminal = Mt5EaTerminal::query()
            ->where('enabled', true)
            ->orderByDesc('last_seen_at')
            ->first();

        if ($terminal?->api_token) {
            return (string) $terminal->api_token;
        }

        throw new RuntimeException('No MT5 instance token available. Create an instance on EA Bridge first.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createInstance(array $data): Mt5EaTerminal
    {
        $displayName = trim((string) ($data['display_name'] ?? ''));
        if ($displayName === '') {
            throw new InvalidArgumentException('Display name is required.');
        }

        $isDemo = (bool) ($data['is_demo'] ?? true);
        $instanceKey = trim((string) ($data['instance_key'] ?? ''));
        if ($instanceKey === '') {
            $suffix = $isDemo ? 'demo' : 'live';
            $instanceKey = Mt5EaTerminal::makeUniqueInstanceKey($displayName.'-'.$suffix);
        } else {
            $instanceKey = Mt5EaTerminal::slugifyInstanceKey($instanceKey);
            if (Mt5EaTerminal::query()->where('instance_key', $instanceKey)->exists()) {
                throw new InvalidArgumentException('Instance key "'.$instanceKey.'" is already in use.');
            }
        }

        $plainToken = Str::random(64);

        return Mt5EaTerminal::query()->create([
            'instance_key' => $instanceKey,
            'display_name' => $displayName,
            'is_demo' => $isDemo,
            'enabled' => true,
            'api_token' => $plainToken,
            'api_token_hash' => Mt5EaTerminal::hashToken($plainToken),
        ]);
    }

    public function regenerateTerminalToken(Mt5EaTerminal $terminal): string
    {
        $plainToken = Str::random(64);
        $terminal->forceFill([
            'api_token' => $plainToken,
            'api_token_hash' => Mt5EaTerminal::hashToken($plainToken),
        ]);
        $terminal->save();

        return $plainToken;
    }

    public function revealTerminalToken(Mt5EaTerminal $terminal): string
    {
        $token = trim((string) $terminal->api_token);

        if ($token === '') {
            throw new RuntimeException('No API token stored for "'.$terminal->label().'". Regenerate a new one.');
        }

        return $token;
    }

    public function queueTestTrade(Mt5EaTerminal $terminal, string $symbol = 'GBPUSD', float $lot = 0.01): Mt5EaCommand
    {
        if (! $terminal->instance_key) {
            throw new InvalidArgumentException('Instance key is required before sending a test trade.');
        }

        if (! $terminal->isOnline()) {
            throw new RuntimeException('Instance "'.$terminal->label().'" is offline. Start LaravelBridge in MT5 first.');
        }

        if (! $terminal->is_demo) {
            $settings = AppSetting::singleton();
            if ($settings->demo_only) {
                throw new RuntimeException('Test trades on live instances are blocked while demo_only is enabled.');
            }
        }

        return $this->queueCommand([
            'action' => 'BUY',
            'symbol' => strtoupper($symbol),
            'lot' => $lot,
            'sl' => 15,
            'tp' => 15,
            'mt5_instance_key' => $terminal->instance_key,
            'source' => 'test',
        ]);
    }

    public function resolveTerminal(?string $instanceKey): Mt5EaTerminal
    {
        if ($instanceKey !== null && trim($instanceKey) !== '') {
            $terminal = Mt5EaTerminal::query()
                ->where('instance_key', trim($instanceKey))
                ->where('enabled', true)
                ->first();

            if ($terminal === null) {
                throw new RuntimeException('MT5 instance "'.$instanceKey.'" is not registered or is disabled.');
            }

            if (! $terminal->isOnline()) {
                throw new RuntimeException('MT5 instance "'.$terminal->label().'" is offline.');
            }

            return $terminal;
        }

        $terminal = Mt5EaTerminal::query()
            ->where('enabled', true)
            ->where('last_seen_at', '>=', now()->subSeconds(Mt5EaTerminal::ONLINE_GRACE_SECONDS))
            ->orderByDesc('last_seen_at')
            ->first();

        if ($terminal === null) {
            throw new RuntimeException('No online EA terminal is available. Attach LaravelBridge in MT5.');
        }

        return $terminal;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<int, string>
     */
    public static function profileInstanceKeys(array $profile): array
    {
        $keys = [];

        if (is_array($profile['mt5_instance_keys'] ?? null)) {
            foreach ($profile['mt5_instance_keys'] as $key) {
                $key = trim((string) $key);
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        $legacy = trim((string) ($profile['mt5_instance_key'] ?? ''));
        if ($legacy !== '' && ! in_array($legacy, $keys, true)) {
            $keys[] = $legacy;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function profileMatchesTerminal(array $profile, Mt5EaTerminal $terminal): bool
    {
        $keys = self::profileInstanceKeys($profile);

        if ($keys === []) {
            return true;
        }

        return $terminal->instance_key !== null
            && in_array($terminal->instance_key, $keys, true);
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    public function resolvePrimaryTerminalForProfile(array $profile): Mt5EaTerminal
    {
        $keys = self::profileInstanceKeys($profile);

        if ($keys === []) {
            return $this->resolveTerminal(null);
        }

        foreach ($keys as $key) {
            $terminal = Mt5EaTerminal::query()
                ->where('instance_key', $key)
                ->where('enabled', true)
                ->first();

            if ($terminal?->isOnline()) {
                return $terminal;
            }
        }

        throw new RuntimeException(
            'No online MT5 instance for profile. Expected one of: '.implode(', ', $keys)
        );
    }

    /**
     * @return array<int, Mt5EaTerminal>
     */
    public function selectableTerminals(): array
    {
        return Mt5EaTerminal::query()
            ->where('enabled', true)
            ->orderBy('display_name')
            ->orderBy('instance_key')
            ->orderByDesc('last_seen_at')
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTerminalInstance(int $terminalId, array $data): Mt5EaTerminal
    {
        $terminal = Mt5EaTerminal::query()->findOrFail($terminalId);
        $instanceKey = trim((string) ($data['instance_key'] ?? $terminal->instance_key ?? ''));

        if ($instanceKey === '') {
            $instanceKey = Mt5EaTerminal::makeUniqueInstanceKey(
                (string) ($data['display_name'] ?? $terminal->broker_company ?? $terminal->account_login)
            );
        } else {
            $instanceKey = Mt5EaTerminal::slugifyInstanceKey($instanceKey);
            $exists = Mt5EaTerminal::query()
                ->where('instance_key', $instanceKey)
                ->where('id', '!=', $terminal->id)
                ->exists();
            if ($exists) {
                throw new InvalidArgumentException('Instance key "'.$instanceKey.'" is already in use.');
            }
        }

        $terminal->fill([
            'instance_key' => $instanceKey,
            'display_name' => trim((string) ($data['display_name'] ?? $terminal->display_name ?? '')) ?: null,
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : $terminal->enabled,
            'is_demo' => array_key_exists('is_demo', $data) ? (bool) $data['is_demo'] : $terminal->is_demo,
            'symbol_suffix' => array_key_exists('symbol_suffix', $data)
                ? ($data['symbol_suffix'] !== '' ? (string) $data['symbol_suffix'] : null)
                : $terminal->symbol_suffix,
            'symbol_map' => array_key_exists('symbol_map', $data)
                ? (is_array($data['symbol_map']) ? $data['symbol_map'] : $terminal->symbol_map)
                : $terminal->symbol_map,
        ]);
        $terminal->save();

        return $terminal;
    }

    /**
     * @return array<int, string>
     */
    public function botProfileKeysUsingInstance(string $instanceKey): array
    {
        if (trim($instanceKey) === '') {
            return [];
        }

        $profiles = AppSetting::singleton()->bot_profiles ?? [];
        $keys = [];

        foreach ($profiles as $profile) {
            if (! is_array($profile)) {
                continue;
            }

            if (in_array($instanceKey, self::profileInstanceKeys($profile), true)) {
                $keys[] = (string) ($profile['key'] ?? 'unknown');
            }
        }

        return $keys;
    }

    public function deleteInstance(Mt5EaTerminal $terminal): void
    {
        $instanceKey = (string) ($terminal->instance_key ?? '');
        $inUse = $this->botProfileKeysUsingInstance($instanceKey);

        if ($inUse !== []) {
            throw new RuntimeException(
                'Instance "'.$terminal->label().'" is linked to bot profiles: '.implode(', ', $inUse).'. Unlink them first.'
            );
        }

        $terminal->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handlePoll(Mt5EaTerminal $authenticatedTerminal, array $payload): array
    {
        $terminal = $this->syncTerminalFromPoll($authenticatedTerminal, $payload);
        $this->applyCommandResult($payload['command_result'] ?? null);

        $command = $this->claimNextCommand($terminal);
        $watchPlan = $this->buildWatchPlan($terminal);

        return [
            'ok' => true,
            'instance_key' => $terminal->instance_key,
            'command' => $command?->toEaPayload(),
            'watch_symbols' => $watchPlan['symbols'],
            'candle_requests' => $watchPlan['candle_requests'],
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

        $terminal = null;
        $instanceKey = trim((string) ($data['mt5_instance_key'] ?? ''));
        $accountLogin = isset($data['account_login']) ? (int) $data['account_login'] : null;

        if ($instanceKey !== '') {
            $terminal = Mt5EaTerminal::query()
                ->where('instance_key', $instanceKey)
                ->where('enabled', true)
                ->first();

            if ($terminal === null) {
                throw new InvalidArgumentException('Unknown MT5 instance key: '.$instanceKey);
            }

            $accountLogin = $terminal->account_login;
        } elseif ($accountLogin !== null) {
            $terminal = Mt5EaTerminal::query()
                ->where('account_login', $accountLogin)
                ->orderByDesc('last_seen_at')
                ->first();
        }

        $canonicalSymbol = isset($data['symbol']) ? strtoupper(trim((string) $data['symbol'])) : null;
        $explicitBrokerSymbol = isset($data['broker_symbol']) ? strtoupper(trim((string) $data['broker_symbol'])) : '';
        $brokerSymbol = $canonicalSymbol;

        if ($explicitBrokerSymbol !== '') {
            $brokerSymbol = $explicitBrokerSymbol;
        } elseif ($canonicalSymbol !== null && $terminal !== null) {
            $brokerSymbol = $this->symbolMapper->toBrokerSymbol($terminal, $canonicalSymbol);
        }

        return Mt5EaCommand::query()->create([
            'mt5_ea_terminal_id' => $terminal?->id,
            'account_login' => $accountLogin,
            'mt5_instance_key' => $instanceKey !== '' ? $instanceKey : $terminal?->instance_key,
            'bot_trade_log_id' => isset($data['bot_trade_log_id']) ? (int) $data['bot_trade_log_id'] : null,
            'bot_key' => isset($data['bot_key']) ? trim((string) $data['bot_key']) : null,
            'source' => isset($data['source']) ? trim((string) $data['source']) : null,
            'action' => $action,
            'symbol' => $brokerSymbol,
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
    private function syncTerminalFromPoll(Mt5EaTerminal $terminal, array $payload): Mt5EaTerminal
    {
        $login = (int) ($payload['login'] ?? 0);
        $server = trim((string) ($payload['server'] ?? ''));

        if ($login <= 0) {
            throw new InvalidArgumentException('login is required.');
        }

        if ($terminal->isBound()) {
            if ((int) $terminal->account_login !== $login) {
                throw new InvalidArgumentException('Poll login does not match this instance token.');
            }

            if ($terminal->server && $server !== '' && $terminal->server !== $server) {
                throw new InvalidArgumentException('Poll server does not match this instance token.');
            }
        } else {
            $duplicate = Mt5EaTerminal::query()
                ->where('id', '!=', $terminal->id)
                ->where('account_login', $login)
                ->where('server', $server !== '' ? $server : null)
                ->exists();

            if ($duplicate) {
                throw new InvalidArgumentException('This MT5 account is already linked to another instance.');
            }

            $terminal->account_login = $login;
            $terminal->server = $server !== '' ? $server : null;
        }

        if (trim((string) ($payload['instance_key'] ?? '')) !== '' && empty($terminal->instance_key)) {
            $candidate = Mt5EaTerminal::slugifyInstanceKey((string) $payload['instance_key']);
            if (! Mt5EaTerminal::query()->where('instance_key', $candidate)->where('id', '!=', $terminal->id)->exists()) {
                $terminal->instance_key = $candidate;
            }
        }

        $brokerCompany = $payload['broker_company'] ?? $terminal->broker_company;
        $symbolSuffix = $terminal->symbol_suffix;
        if ($symbolSuffix === null || $symbolSuffix === '' || $symbolSuffix === SymbolMapper::SUFFIX_AUTO) {
            $company = strtoupper((string) $brokerCompany);
            if (str_contains($company, 'PEPPERSTONE')) {
                $symbolSuffix = SymbolMapper::SUFFIX_SPREAD_BET;
            } elseif (
                str_contains($company, 'IC MARKETS')
                || str_contains($company, 'ICMARKETS')
                || str_contains($company, 'RAW TRADING')
            ) {
                $symbolSuffix = SymbolMapper::SUFFIX_NONE;
            }
        }

        $terminal->fill([
            'terminal_name' => $payload['terminal_name'] ?? $terminal->terminal_name,
            'broker_company' => $brokerCompany,
            'symbol_suffix' => $symbolSuffix,
            'balance' => isset($payload['balance']) ? (float) $payload['balance'] : $terminal->balance,
            'equity' => isset($payload['equity']) ? (float) $payload['equity'] : $terminal->equity,
            'margin' => isset($payload['margin']) ? (float) $payload['margin'] : $terminal->margin,
            'free_margin' => isset($payload['free_margin']) ? (float) $payload['free_margin'] : $terminal->free_margin,
            'currency' => $payload['currency'] ?? $terminal->currency,
            'trade_allowed' => (bool) ($payload['trade_allowed'] ?? $terminal->trade_allowed),
            'positions' => is_array($payload['positions'] ?? null) ? $payload['positions'] : [],
            'market_quotes' => $this->mergeMarketQuotes(
                is_array($terminal->market_quotes) ? $terminal->market_quotes : [],
                is_array($payload['quotes'] ?? null) ? $payload['quotes'] : []
            ),
            'market_candles' => $this->mergeMarketCandles(
                is_array($terminal->market_candles) ? $terminal->market_candles : [],
                is_array($payload['candles'] ?? null) ? $payload['candles'] : []
            ),
            'last_seen_at' => now(),
        ]);
        $terminal->save();

        return $terminal->fresh();
    }

    /**
     * @param  array<string, array<string, mixed>>  $existing
     * @param  array<string, array<string, mixed>>  $incoming
     * @return array<string, array<string, mixed>>
     */
    private function mergeMarketQuotes(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $quote) {
            if (! is_array($quote)) {
                continue;
            }

            $bid = (float) ($quote['bid'] ?? 0);
            $ask = (float) ($quote['ask'] ?? 0);
            if ($bid <= 0 || $ask <= 0) {
                continue;
            }

            $existing[strtoupper((string) $key)] = $quote;
        }

        return $existing;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $existing
     * @param  array<string, array<int, array<string, mixed>>>  $incoming
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function mergeMarketCandles(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $candles) {
            if (is_array($candles) && $candles !== []) {
                $existing[$key] = $candles;
            }
        }

        return $existing;
    }

    /**
     * @return array{symbols: array<int, string>, candle_requests: array<int, array<string, mixed>>}
     */
    private function buildWatchPlan(Mt5EaTerminal $terminal): array
    {
        $symbols = [];
        $timeframes = [];
        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];

        foreach ($profiles as $profile) {
            if (! is_array($profile) || ! ($profile['enabled'] ?? true)) {
                continue;
            }

            if (! self::profileMatchesTerminal($profile, $terminal)) {
                continue;
            }

            foreach (['symbols', 'preferred_symbols'] as $field) {
                if (! is_array($profile[$field] ?? null)) {
                    continue;
                }
                foreach ($profile[$field] as $symbol) {
                    $symbols[] = $this->symbolMapper->normalizeCanonical((string) $symbol);
                }
            }

            foreach (['signal_timeframes', 'signal_timeframe', 'entry_timeframe'] as $field) {
                $value = $profile[$field] ?? null;
                if (is_array($value)) {
                    foreach ($value as $tf) {
                        $timeframes[] = strtolower(trim((string) $tf));
                    }
                } elseif (is_string($value) && $value !== '') {
                    $timeframes[] = strtolower(trim($value));
                }
            }
        }

        if ($symbols === []) {
            $symbols = Ticker::query()
                ->where('is_active', true)
                ->orderBy('symbol')
                ->limit(self::MAX_WATCH_SYMBOLS)
                ->pluck('symbol')
                ->map(fn ($symbol) => $this->symbolMapper->normalizeCanonical((string) $symbol))
                ->all();
        }

        if ($symbols === []) {
            $symbols = ['EURUSD', 'GBPUSD', 'USDJPY', 'XAUUSD'];
        }

        $symbols = array_values(array_unique(array_filter($symbols)));
        $symbols = array_map(
            fn (string $symbol) => $this->symbolMapper->toBrokerSymbol($terminal, $symbol),
            $symbols
        );
        $symbols = array_values(array_unique(array_filter($symbols)));
        $timeframes = array_values(array_unique(array_filter($timeframes)));
        if ($timeframes === []) {
            $timeframes = ['15m', '1h', '4h'];
        }

        $candleRequests = [];
        foreach (array_slice($symbols, 0, 12) as $symbol) {
            foreach (array_slice($timeframes, 0, 2) as $timeframe) {
                $candleRequests[] = [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'limit' => 120,
                ];
            }
        }

        return [
            'symbols' => array_slice($symbols, 0, self::MAX_WATCH_SYMBOLS),
            'candle_requests' => array_slice($candleRequests, 0, self::MAX_CANDLE_REQUESTS),
        ];
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

        if ($command->bot_trade_log_id) {
            $log = BotTradeLog::query()->find($command->bot_trade_log_id);
            if ($log) {
                $ticket = isset($result['ticket']) ? (int) $result['ticket'] : null;
                $log->fill([
                    'status' => $ok ? 'success' : 'failed',
                    'position_id' => $ticket ? (string) $ticket : $log->position_id,
                    'order_id' => 'ea-cmd-'.$command->id,
                    'error_message' => $ok ? null : (string) ($result['message'] ?? 'EA execution failed'),
                    'meta_payload' => array_merge(is_array($log->meta_payload) ? $log->meta_payload : [], [
                        'ea_command_id' => $command->id,
                        'ea_result' => $result,
                    ]),
                    'message' => $ok
                        ? 'Trade executed via EA bridge command #'.$command->id.'.'
                        : 'EA bridge command #'.$command->id.' failed.',
                ]);
                $log->save();
            }
        }
    }

    private function claimNextCommand(Mt5EaTerminal $terminal): ?Mt5EaCommand
    {
        $command = Mt5EaCommand::query()
            ->where('status', Mt5EaCommand::STATUS_PENDING)
            ->where(function ($query) use ($terminal) {
                $query->where('mt5_ea_terminal_id', $terminal->id)
                    ->orWhere(function ($scoped) use ($terminal) {
                        $scoped->whereNull('mt5_ea_terminal_id')
                            ->where(function ($loginScope) use ($terminal) {
                                $loginScope->whereNull('account_login')
                                    ->orWhere('account_login', $terminal->account_login);
                            });
                    });
            })
            ->where(function ($query) use ($terminal) {
                $query->whereNull('mt5_instance_key')
                    ->orWhere('mt5_instance_key', $terminal->instance_key);
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
            'mt5_instance_key' => $terminal->instance_key,
            'status' => Mt5EaCommand::STATUS_SENT,
            'sent_at' => now(),
        ]);
        $command->save();

        return $command;
    }

    /**
     * Pick the first online EA instance that can quote a canonical symbol.
     *
     * @param  array<int, string>  $instanceKeys
     */
    public function resolveScanBroker(string $canonicalSymbol, array $instanceKeys, EaBridgeBroker $broker): EaBridgeBroker
    {
        $resolution = $this->resolveScanBrokerDetailed($canonicalSymbol, $instanceKeys, $broker);

        if ($resolution['broker'] === null) {
            throw new RuntimeException((string) ($resolution['error'] ?? 'No EA instance available.'));
        }

        return $resolution['broker'];
    }

    /**
     * @param  array<int, string>  $instanceKeys
     * @return array{
     *     broker: ?EaBridgeBroker,
     *     canonical_symbol: string,
     *     broker_symbol: ?string,
     *     instance_key: ?string,
     *     instance_label: ?string,
     *     attempts: array<int, array{instance_key: ?string, instance_label: string, broker_symbol: string, ok: bool, error?: string}>,
     *     error: ?string
     * }
     */
    public function resolveScanBrokerDetailed(string $canonicalSymbol, array $instanceKeys, EaBridgeBroker $broker): array
    {
        $canonical = $this->symbolMapper->normalizeCanonical($canonicalSymbol);
        $keys = $instanceKeys !== [] ? $instanceKeys : [null];
        $attempts = [];
        $lastError = null;

        foreach ($keys as $key) {
            $instanceLabel = $key !== null ? (string) $key : 'default';
            $brokerSymbol = $canonical;

            try {
                $instance = $broker->forInstance($key);
                $instanceLabel = $instance->instanceLabel();
                $brokerSymbol = $instance->toBrokerSymbol($canonical);
                $quote = $instance->getTickerPrice($canonical);
                $matchedSymbol = strtoupper(trim((string) ($quote['symbol'] ?? '')));
                if ($matchedSymbol !== '') {
                    $brokerSymbol = $matchedSymbol;
                }

                $attempts[] = [
                    'instance_key' => $instance->instanceKey(),
                    'instance_label' => $instanceLabel,
                    'broker_symbol' => $brokerSymbol,
                    'ok' => true,
                ];

                return [
                    'broker' => $instance,
                    'canonical_symbol' => $canonical,
                    'broker_symbol' => $brokerSymbol,
                    'instance_key' => $instance->instanceKey(),
                    'instance_label' => $instanceLabel,
                    'attempts' => $attempts,
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                $attempts[] = [
                    'instance_key' => $key,
                    'instance_label' => $instanceLabel,
                    'broker_symbol' => $brokerSymbol,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
                $lastError = $e;
            }
        }

        return [
            'broker' => null,
            'canonical_symbol' => $canonical,
            'broker_symbol' => null,
            'instance_key' => null,
            'instance_label' => null,
            'attempts' => $attempts,
            'error' => $lastError?->getMessage() ?? 'No EA instance available for '.$canonical.'.',
        ];
    }
}
