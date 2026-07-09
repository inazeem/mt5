<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Mt5EaTerminal;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BotProfileController extends Controller
{
    private const ALLOWED_SIGNAL_TIMEFRAMES = ['5m', '15m', '30m', '1h', '4h'];
    private const ALLOWED_STRATEGIES = ['momentum', 'sma_cross', 'ema_cross', 'bollinger_reversion', 'vwap_reversion'];
    private const ALLOWED_TICKER_CATEGORIES = ['Forex', 'Stock', 'Commodity', 'Index', 'Crypto', 'Other'];

    public function index()
    {
        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];

        return view('bot-profiles.index', compact('settings', 'profiles'));
    }

    public function create()
    {
        $mt5Instances = Mt5EaTerminal::query()
            ->forList()
            ->where('enabled', true)
            ->orderBy('display_name')
            ->orderBy('instance_key')
            ->get();

        return view('bot-profiles.create', compact('mt5Instances'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key'                    => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name'                   => ['required', 'string', 'max:120'],
            'enabled'                => ['nullable', 'boolean'],
            'lot'                    => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'tp_pips'                => ['nullable', 'numeric', 'min:0.1'],
            'sl_pips'                => ['nullable', 'numeric', 'min:0.1'],
            'trail_start_pips'       => ['nullable', 'numeric', 'min:0.1'],
            'trail_pips'             => ['nullable', 'numeric', 'min:0.1'],
            'trail_tp_multiplier'    => ['nullable', 'numeric', 'min:1', 'max:10'],
            'min_move_pips'          => ['nullable', 'numeric', 'min:0.1'],
            'max_spread_pips'        => ['nullable', 'numeric', 'min:0.1'],
            'ticker_categories'      => ['nullable', 'array'],
            'ticker_categories.*'    => ['required', 'in:Forex,Stock,Commodity,Index,Crypto,Other'],
            'cooldown_minutes'       => ['nullable', 'integer', 'min:0'],
            'session_start_utc'      => ['nullable', 'integer', 'min:0', 'max:23'],
            'session_end_utc'        => ['nullable', 'integer', 'min:0', 'max:23'],
            'max_trades_per_day'     => ['nullable', 'integer', 'min:1'],
            'max_daily_loss_percent' => ['nullable', 'numeric', 'min:0.1'],
            'ai_confirm'             => ['nullable', 'boolean'],
            'ai_min_confidence'      => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_symbols'            => ['nullable', 'integer', 'min:1'],
            'max_open_positions'     => ['nullable', 'integer', 'min:1'],
            'max_per_cycle'          => ['nullable', 'integer', 'min:1'],
            'min_bot_score'          => ['nullable', 'integer', 'min:0', 'max:100'],
            'use_adx_score'          => ['nullable', 'boolean'],
            'use_rsi_score'          => ['nullable', 'boolean'],
            'adx_min_floor'          => ['nullable', 'numeric', 'min:1', 'max:100'],
            'min_effective_volume'   => ['nullable', 'numeric', 'min:0.001'],
            'enable_max_hold'        => ['nullable', 'boolean'],
            'max_hold_minutes'       => ['nullable', 'integer', 'min:1', 'max:1440'],
            'scalper'                => ['nullable', 'boolean'],
            'reverse_strategy'       => ['nullable', 'boolean'],
            'symbols'                => ['nullable', 'string'],
            'preferred_hours_utc'    => ['nullable', 'string'],
            'blocked_hours_utc'      => ['nullable', 'string'],
            'preferred_symbols'      => ['nullable', 'string'],
            'strategy'               => ['nullable', 'in:momentum,sma_cross,ema_cross,bollinger_reversion,vwap_reversion'],
            'strategies'             => ['nullable', 'array'],
            'strategies.*'           => ['required', 'in:momentum,sma_cross,ema_cross,bollinger_reversion,vwap_reversion'],
            'strategy_params'        => ['nullable', 'array'],
            'strategy_params.sma_fast' => ['nullable', 'integer', 'min:2', 'max:200'],
            'strategy_params.sma_slow' => ['nullable', 'integer', 'min:3', 'max:300'],
            'strategy_params.sma_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'strategy_params.ema_fast' => ['nullable', 'integer', 'min:2', 'max:200'],
            'strategy_params.ema_slow' => ['nullable', 'integer', 'min:3', 'max:300'],
            'strategy_params.ema_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'strategy_params.bb_period' => ['nullable', 'integer', 'min:5', 'max:300'],
            'strategy_params.bb_stddev' => ['nullable', 'numeric', 'min:0.5', 'max:5'],
            'strategy_params.bb_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'strategy_params.vwap_period' => ['nullable', 'integer', 'min:5', 'max:500'],
            'strategy_params.vwap_min_distance_pips' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'strategy_params.vwap_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'signal_timeframes'      => ['nullable', 'array'],
            'signal_timeframes.*'    => ['required', 'in:5m,15m,30m,1h,4h'],
            'signal_timeframe'       => ['nullable', 'in:5m,15m,30m,1h,4h'],
            'entry_timeframe'        => ['nullable', 'in:5m,15m,30m,1h,4h'],
            'mt5_instance_keys'      => ['nullable', 'array'],
            'mt5_instance_keys.*'    => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'mt5_instance_key'       => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'mt5_broker'             => ['nullable', 'string', 'in:ea_bridge,metaapi'],
        ]);

        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];

        $key = strtolower(trim($validated['key']));
        if (collect($profiles)->pluck('key')->contains($key)) {
            throw ValidationException::withMessages([
                'key' => 'Bot key must be unique.',
            ]);
        }

        $symbols = $this->parseSymbolsCsv($validated['symbols'] ?? null);
        $preferredSymbols = $this->parseSymbolsCsv($validated['preferred_symbols'] ?? null);
        $preferredHoursUtc = $this->parseHoursCsv($validated['preferred_hours_utc'] ?? null);
        $blockedHoursUtc = $this->parseHoursCsv($validated['blocked_hours_utc'] ?? null);
        $tickerCategories = $this->normalizeTickerCategories($validated['ticker_categories'] ?? null);
        $signalTimeframes = $this->normalizeSignalTimeframes(
            $validated['signal_timeframes'] ?? (isset($validated['signal_timeframe']) ? [(string) $validated['signal_timeframe']] : null)
        );
        $entryTimeframe = $this->normalizeSignalTimeframe($validated['entry_timeframe'] ?? null);
        if ($entryTimeframe !== null && empty($signalTimeframes)) {
            $signalTimeframes = ['1h', '4h'];
        }
        if ($entryTimeframe === null) {
            $entryTimeframe = '15m';
        }
        $strategies = $this->normalizeStrategies($validated['strategies'] ?? null);
        if ($strategies === null && !empty($validated['strategy'])) {
            $strategies = [$this->normalizeStrategy($validated['strategy'])];
        }

        $newProfile = array_merge([
            'key' => $key,
            'name' => trim($validated['name']),
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'lot' => isset($validated['lot']) ? (float) $validated['lot'] : null,
            'tp_pips' => isset($validated['tp_pips']) ? (float) $validated['tp_pips'] : null,
            'sl_pips' => isset($validated['sl_pips']) ? (float) $validated['sl_pips'] : null,
            'trail_start_pips' => isset($validated['trail_start_pips']) ? (float) $validated['trail_start_pips'] : null,
            'trail_pips' => isset($validated['trail_pips']) ? (float) $validated['trail_pips'] : null,
            'trail_tp_multiplier' => isset($validated['trail_tp_multiplier']) ? (float) $validated['trail_tp_multiplier'] : null,
            'min_move_pips' => isset($validated['min_move_pips']) ? (float) $validated['min_move_pips'] : null,
            'max_spread_pips' => isset($validated['max_spread_pips']) ? (float) $validated['max_spread_pips'] : null,
            'ticker_categories' => !empty($tickerCategories) ? $tickerCategories : null,
            'cooldown_minutes' => isset($validated['cooldown_minutes']) ? (int) $validated['cooldown_minutes'] : null,
            'session_start_utc' => isset($validated['session_start_utc']) ? (int) $validated['session_start_utc'] : null,
            'session_end_utc' => isset($validated['session_end_utc']) ? (int) $validated['session_end_utc'] : null,
            'max_trades_per_day' => isset($validated['max_trades_per_day']) ? (int) $validated['max_trades_per_day'] : null,
            'max_daily_loss_percent' => isset($validated['max_daily_loss_percent']) ? (float) $validated['max_daily_loss_percent'] : null,
            'ai_confirm' => $request->boolean('ai_confirm'),
            'ai_min_confidence' => isset($validated['ai_min_confidence']) ? (int) $validated['ai_min_confidence'] : null,
            'max_symbols' => isset($validated['max_symbols']) ? (int) $validated['max_symbols'] : null,
            'max_open_positions' => isset($validated['max_open_positions']) ? (int) $validated['max_open_positions'] : null,
            'max_per_cycle' => isset($validated['max_per_cycle']) ? (int) $validated['max_per_cycle'] : null,
            'min_bot_score' => isset($validated['min_bot_score']) ? (int) $validated['min_bot_score'] : null,
            'use_adx_score' => $request->boolean('use_adx_score'),
            'use_rsi_score' => $request->boolean('use_rsi_score'),
            'adx_min_floor' => isset($validated['adx_min_floor']) ? (float) $validated['adx_min_floor'] : null,
            'min_effective_volume' => isset($validated['min_effective_volume']) ? (float) $validated['min_effective_volume'] : null,
            'enable_max_hold' => $request->boolean('enable_max_hold'),
            'max_hold_minutes' => isset($validated['max_hold_minutes']) ? (int) $validated['max_hold_minutes'] : null,
            'scalper' => $request->boolean('scalper'),
            'reverse_strategy' => $request->boolean('reverse_strategy'),
            'symbols' => !empty($symbols) ? $symbols : null,
            'preferred_symbols' => !empty($preferredSymbols) ? $preferredSymbols : null,
            'preferred_hours_utc' => !empty($preferredHoursUtc) ? $preferredHoursUtc : null,
            'blocked_hours_utc' => !empty($blockedHoursUtc) ? $blockedHoursUtc : null,
            'strategies' => $strategies,
            'strategy' => !empty($strategies) ? $strategies[0] : $this->normalizeStrategy($validated['strategy'] ?? null),
            'strategy_params' => $this->normalizeStrategyParams($validated['strategy_params'] ?? null),
            'signal_timeframes' => $signalTimeframes,
            'signal_timeframe' => !empty($signalTimeframes) ? $signalTimeframes[0] : null,
            'entry_timeframe' => $entryTimeframe,
            'mt5_broker' => $this->normalizeMt5Broker($validated['mt5_broker'] ?? null),
        ], $this->mt5InstanceFieldsFromValidated($validated));

        $profiles[] = $newProfile;
        $settings->bot_profiles = $profiles;
        $settings->save();

        return redirect()->route('bot-profiles.index')->with('status', 'Bot profile created successfully.');
    }

    public function edit(string $key)
    {
        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];
        $profile = collect($profiles)->firstWhere('key', $key);

        if (!$profile) {
            abort(404, 'Bot profile not found.');
        }

        $mt5Instances = Mt5EaTerminal::query()
            ->forList()
            ->where('enabled', true)
            ->orderBy('display_name')
            ->orderBy('instance_key')
            ->get();

        return view('bot-profiles.edit', compact('profile', 'mt5Instances'));
    }

    public function update(Request $request, string $key)
    {
        $validated = $request->validate([
            'name'                   => ['required', 'string', 'max:120'],
            'enabled'                => ['nullable', 'boolean'],
            'lot'                    => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'tp_pips'                => ['nullable', 'numeric', 'min:0.1'],
            'sl_pips'                => ['nullable', 'numeric', 'min:0.1'],
            'trail_start_pips'       => ['nullable', 'numeric', 'min:0.1'],
            'trail_pips'             => ['nullable', 'numeric', 'min:0.1'],
            'trail_tp_multiplier'    => ['nullable', 'numeric', 'min:1', 'max:10'],
            'min_move_pips'          => ['nullable', 'numeric', 'min:0.1'],
            'max_spread_pips'        => ['nullable', 'numeric', 'min:0.1'],
            'ticker_categories'      => ['nullable', 'array'],
            'ticker_categories.*'    => ['required', 'in:Forex,Stock,Commodity,Index,Crypto,Other'],
            'cooldown_minutes'       => ['nullable', 'integer', 'min:0'],
            'session_start_utc'      => ['nullable', 'integer', 'min:0', 'max:23'],
            'session_end_utc'        => ['nullable', 'integer', 'min:0', 'max:23'],
            'max_trades_per_day'     => ['nullable', 'integer', 'min:1'],
            'max_daily_loss_percent' => ['nullable', 'numeric', 'min:0.1'],
            'ai_confirm'             => ['nullable', 'boolean'],
            'ai_min_confidence'      => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_symbols'            => ['nullable', 'integer', 'min:1'],
            'max_open_positions'     => ['nullable', 'integer', 'min:1'],
            'max_per_cycle'          => ['nullable', 'integer', 'min:1'],
            'min_bot_score'          => ['nullable', 'integer', 'min:0', 'max:100'],
            'use_adx_score'          => ['nullable', 'boolean'],
            'use_rsi_score'          => ['nullable', 'boolean'],
            'adx_min_floor'          => ['nullable', 'numeric', 'min:1', 'max:100'],
            'min_effective_volume'   => ['nullable', 'numeric', 'min:0.001'],
            'enable_max_hold'        => ['nullable', 'boolean'],
            'max_hold_minutes'       => ['nullable', 'integer', 'min:1', 'max:1440'],
            'scalper'                => ['nullable', 'boolean'],
            'reverse_strategy'       => ['nullable', 'boolean'],
            'symbols'                => ['nullable', 'string'],
            'preferred_hours_utc'    => ['nullable', 'string'],
            'blocked_hours_utc'      => ['nullable', 'string'],
            'preferred_symbols'      => ['nullable', 'string'],
            'strategy'               => ['nullable', 'in:momentum,sma_cross,ema_cross,bollinger_reversion,vwap_reversion'],
            'strategies'             => ['nullable', 'array'],
            'strategies.*'           => ['required', 'in:momentum,sma_cross,ema_cross,bollinger_reversion,vwap_reversion'],
            'strategy_params'        => ['nullable', 'array'],
            'strategy_params.sma_fast' => ['nullable', 'integer', 'min:2', 'max:200'],
            'strategy_params.sma_slow' => ['nullable', 'integer', 'min:3', 'max:300'],
            'strategy_params.sma_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'strategy_params.ema_fast' => ['nullable', 'integer', 'min:2', 'max:200'],
            'strategy_params.ema_slow' => ['nullable', 'integer', 'min:3', 'max:300'],
            'strategy_params.ema_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'strategy_params.bb_period' => ['nullable', 'integer', 'min:5', 'max:300'],
            'strategy_params.bb_stddev' => ['nullable', 'numeric', 'min:0.5', 'max:5'],
            'strategy_params.bb_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'strategy_params.vwap_period' => ['nullable', 'integer', 'min:5', 'max:500'],
            'strategy_params.vwap_min_distance_pips' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'strategy_params.vwap_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'signal_timeframes'      => ['nullable', 'array'],
            'signal_timeframes.*'    => ['required', 'in:5m,15m,30m,1h,4h'],
            'signal_timeframe'       => ['nullable', 'in:5m,15m,30m,1h,4h'],
            'entry_timeframe'        => ['nullable', 'in:5m,15m,30m,1h,4h'],
            'mt5_instance_keys'      => ['nullable', 'array'],
            'mt5_instance_keys.*'    => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'mt5_instance_key'       => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'mt5_broker'             => ['nullable', 'string', 'in:ea_bridge,metaapi'],
        ]);

        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];
        $profileIndex = collect($profiles)->search(static fn ($p) => $p['key'] === $key);

        if ($profileIndex === false) {
            abort(404, 'Bot profile not found.');
        }

        $symbols = $this->parseSymbolsCsv($validated['symbols'] ?? null);
        $preferredSymbols = $this->parseSymbolsCsv($validated['preferred_symbols'] ?? null);
        $preferredHoursUtc = $this->parseHoursCsv($validated['preferred_hours_utc'] ?? null);
        $blockedHoursUtc = $this->parseHoursCsv($validated['blocked_hours_utc'] ?? null);
        $tickerCategories = $this->normalizeTickerCategories($validated['ticker_categories'] ?? null);
        $signalTimeframes = $this->normalizeSignalTimeframes(
            $validated['signal_timeframes'] ?? (isset($validated['signal_timeframe']) ? [(string) $validated['signal_timeframe']] : null)
        );
        $entryTimeframe = $this->normalizeSignalTimeframe($validated['entry_timeframe'] ?? null);
        if ($entryTimeframe !== null && empty($signalTimeframes)) {
            $signalTimeframes = ['1h', '4h'];
        }
        if ($entryTimeframe === null) {
            $entryTimeframe = '15m';
        }
        $strategies = $this->normalizeStrategies($validated['strategies'] ?? null);
        if ($strategies === null && !empty($validated['strategy'])) {
            $strategies = [$this->normalizeStrategy($validated['strategy'])];
        }

        $profiles[$profileIndex] = array_merge($profiles[$profileIndex], [
            'name' => trim($validated['name']),
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'lot' => isset($validated['lot']) ? (float) $validated['lot'] : null,
            'tp_pips' => isset($validated['tp_pips']) ? (float) $validated['tp_pips'] : null,
            'sl_pips' => isset($validated['sl_pips']) ? (float) $validated['sl_pips'] : null,
            'trail_start_pips' => isset($validated['trail_start_pips']) ? (float) $validated['trail_start_pips'] : null,
            'trail_pips' => isset($validated['trail_pips']) ? (float) $validated['trail_pips'] : null,
            'trail_tp_multiplier' => isset($validated['trail_tp_multiplier']) ? (float) $validated['trail_tp_multiplier'] : null,
            'min_move_pips' => isset($validated['min_move_pips']) ? (float) $validated['min_move_pips'] : null,
            'max_spread_pips' => isset($validated['max_spread_pips']) ? (float) $validated['max_spread_pips'] : null,
            'ticker_categories' => !empty($tickerCategories) ? $tickerCategories : null,
            'cooldown_minutes' => isset($validated['cooldown_minutes']) ? (int) $validated['cooldown_minutes'] : null,
            'session_start_utc' => isset($validated['session_start_utc']) ? (int) $validated['session_start_utc'] : null,
            'session_end_utc' => isset($validated['session_end_utc']) ? (int) $validated['session_end_utc'] : null,
            'max_trades_per_day' => isset($validated['max_trades_per_day']) ? (int) $validated['max_trades_per_day'] : null,
            'max_daily_loss_percent' => isset($validated['max_daily_loss_percent']) ? (float) $validated['max_daily_loss_percent'] : null,
            'ai_confirm' => $request->boolean('ai_confirm'),
            'ai_min_confidence' => isset($validated['ai_min_confidence']) ? (int) $validated['ai_min_confidence'] : null,
            'max_symbols' => isset($validated['max_symbols']) ? (int) $validated['max_symbols'] : null,
            'max_open_positions' => isset($validated['max_open_positions']) ? (int) $validated['max_open_positions'] : null,
            'max_per_cycle' => isset($validated['max_per_cycle']) ? (int) $validated['max_per_cycle'] : null,
            'min_bot_score' => isset($validated['min_bot_score']) ? (int) $validated['min_bot_score'] : null,
            'use_adx_score' => $request->boolean('use_adx_score'),
            'use_rsi_score' => $request->boolean('use_rsi_score'),
            'adx_min_floor' => isset($validated['adx_min_floor']) ? (float) $validated['adx_min_floor'] : null,
            'min_effective_volume' => isset($validated['min_effective_volume']) ? (float) $validated['min_effective_volume'] : null,
            'enable_max_hold' => $request->boolean('enable_max_hold'),
            'max_hold_minutes' => isset($validated['max_hold_minutes']) ? (int) $validated['max_hold_minutes'] : null,
            'scalper' => $request->boolean('scalper'),
            'reverse_strategy' => $request->boolean('reverse_strategy'),
            'symbols' => !empty($symbols) ? $symbols : null,
            'preferred_symbols' => !empty($preferredSymbols) ? $preferredSymbols : null,
            'preferred_hours_utc' => !empty($preferredHoursUtc) ? $preferredHoursUtc : null,
            'blocked_hours_utc' => !empty($blockedHoursUtc) ? $blockedHoursUtc : null,
            'strategies' => $strategies,
            'strategy' => !empty($strategies) ? $strategies[0] : $this->normalizeStrategy($validated['strategy'] ?? null),
            'strategy_params' => $this->normalizeStrategyParams($validated['strategy_params'] ?? null),
            'signal_timeframes' => $signalTimeframes,
            'signal_timeframe' => !empty($signalTimeframes) ? $signalTimeframes[0] : null,
            'entry_timeframe' => $entryTimeframe,
            'mt5_broker' => $this->normalizeMt5Broker($validated['mt5_broker'] ?? null),
        ], $this->mt5InstanceFieldsFromValidated($validated));

        $settings->bot_profiles = $profiles;
        $settings->save();

        return redirect()->route('bot-profiles.index')->with('status', 'Bot profile updated successfully.');
    }

    public function destroy(string $key)
    {
        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];
        $profiles = array_values(array_filter($profiles, static fn ($p) => ($p['key'] ?? '') !== $key));

        $settings->bot_profiles = !empty($profiles) ? $profiles : null;
        $settings->save();

        return redirect()->route('bot-profiles.index')->with('status', 'Bot profile deleted successfully.');
    }

    private function parseSymbolsCsv(?string $raw): array
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($symbol) => strtoupper(trim((string) $symbol)), explode(',', $text)),
            static fn ($symbol) => $symbol !== ''
        )));
    }

    private function parseHoursCsv(?string $raw): array
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }

        $hours = [];
        foreach (explode(',', $text) as $chunk) {
            $value = trim($chunk);
            if ($value === '' || !is_numeric($value)) {
                continue;
            }

            $hour = (int) $value;
            if ($hour < 0 || $hour > 23) {
                continue;
            }

            $hours[] = $hour;
        }

        $hours = array_values(array_unique($hours));
        sort($hours);

        return $hours;
    }

    private function normalizeTickerCategories(?array $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $value) {
            $category = trim((string) $value);
            if (!in_array($category, self::ALLOWED_TICKER_CATEGORIES, true)) {
                continue;
            }

            $normalized[] = $category;
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeSignalTimeframe(?string $raw): ?string
    {
        $value = strtolower(trim((string) $raw));
        if ($value === '') {
            return null;
        }

        return in_array($value, self::ALLOWED_SIGNAL_TIMEFRAMES, true) ? $value : null;
    }

    private function normalizeStrategy(mixed $raw): ?string
    {
        $value = strtolower(trim((string) $raw));
        if ($value === '') {
            return null;
        }

        return in_array($value, self::ALLOWED_STRATEGIES, true) ? $value : null;
    }

    private function normalizeSignalTimeframes(?array $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $order = array_flip(self::ALLOWED_SIGNAL_TIMEFRAMES);
        $timeframes = array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            $raw
        ), static fn ($value) => isset($order[$value]))));

        usort($timeframes, static fn ($a, $b) => $order[$a] <=> $order[$b]);

        return !empty($timeframes) ? $timeframes : null;
    }

    private function normalizeStrategies(?array $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $order = array_flip(self::ALLOWED_STRATEGIES);
        $strategies = array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            $raw
        ), static fn ($value) => isset($order[$value]))));

        usort($strategies, static fn ($a, $b) => $order[$a] <=> $order[$b]);

        return !empty($strategies) ? $strategies : null;
    }

    private function normalizeStrategyParams(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $normalized = [
            'sma_fast' => isset($raw['sma_fast']) ? (int) $raw['sma_fast'] : null,
            'sma_slow' => isset($raw['sma_slow']) ? (int) $raw['sma_slow'] : null,
            'sma_confirm_candles' => isset($raw['sma_confirm_candles']) ? (int) $raw['sma_confirm_candles'] : null,
            'ema_fast' => isset($raw['ema_fast']) ? (int) $raw['ema_fast'] : null,
            'ema_slow' => isset($raw['ema_slow']) ? (int) $raw['ema_slow'] : null,
            'ema_confirm_candles' => isset($raw['ema_confirm_candles']) ? (int) $raw['ema_confirm_candles'] : null,
            'bb_period' => isset($raw['bb_period']) ? (int) $raw['bb_period'] : null,
            'bb_stddev' => isset($raw['bb_stddev']) ? (float) $raw['bb_stddev'] : null,
            'bb_confirm_candles' => isset($raw['bb_confirm_candles']) ? (int) $raw['bb_confirm_candles'] : null,
            'vwap_period' => isset($raw['vwap_period']) ? (int) $raw['vwap_period'] : null,
            'vwap_min_distance_pips' => isset($raw['vwap_min_distance_pips']) ? (float) $raw['vwap_min_distance_pips'] : null,
            'vwap_confirm_candles' => isset($raw['vwap_confirm_candles']) ? (int) $raw['vwap_confirm_candles'] : null,
        ];

        $normalized = array_filter($normalized, static fn ($value) => $value !== null);

        return !empty($normalized) ? $normalized : null;
    }

    private function normalizeMt5Broker(?string $value): string
    {
        $broker = strtolower(trim((string) $value));

        return in_array($broker, ['ea_bridge', 'metaapi'], true) ? $broker : 'ea_bridge';
    }

    /**
     * @return array{mt5_instance_keys: ?array<int, string>, mt5_instance_key: ?string}
     */
    private function mt5InstanceFieldsFromValidated(array $validated): array
    {
        $keys = [];

        foreach ($validated['mt5_instance_keys'] ?? [] as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $keys[] = $key;
            }
        }

        $legacy = trim((string) ($validated['mt5_instance_key'] ?? ''));
        if ($legacy !== '' && ! in_array($legacy, $keys, true)) {
            $keys[] = $legacy;
        }

        $keys = array_values(array_unique($keys));

        return [
            'mt5_instance_keys' => $keys !== [] ? $keys : null,
            'mt5_instance_key' => $keys[0] ?? null,
        ];
    }
}
