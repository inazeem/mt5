<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    private const ALLOWED_SIGNAL_TIMEFRAMES = ['5m', '15m', '30m', '1h', '4h'];
    private const ALLOWED_STRATEGIES = ['momentum', 'sma_cross', 'ema_cross', 'bollinger_reversion', 'vwap_reversion'];

    public function edit()
    {
        $settings = AppSetting::singleton();

        return view('settings.edit', compact('settings'));
    }

    public function update(Request $request)
    {
        $settings = AppSetting::singleton();

        $validated = $request->validate([
            'owner_email'         => ['nullable', 'email'],
            'demo_only'           => ['nullable', 'boolean'],
            'mt5_volume_multiplier' => ['nullable', 'integer', 'min:1'],
            'ai_provider'         => ['required', 'in:claude,perplexity'],
            'claude_api_key'      => ['nullable', 'string', 'max:255'],
            'claude_model'        => ['nullable', 'string', 'max:255'],
            'perplexity_api_key'  => ['nullable', 'string', 'max:255'],
            'perplexity_model'    => ['nullable', 'string', 'max:255'],
            'metaapi_token'       => ['nullable', 'string', 'max:4096'],
            'metaapi_account_id'  => ['nullable', 'string', 'max:255'],
            'metaapi_region'      => ['nullable', 'string', 'max:100'],
            'alpaca_api_key_id'   => ['nullable', 'string', 'max:255'],
            'alpaca_api_secret'   => ['nullable', 'string', 'max:4096'],
            'alpaca_paper'        => ['nullable', 'boolean'],
            // Auto-bot
            'bot_lot'                  => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'bot_tp_pips'              => ['nullable', 'numeric', 'min:0.1'],
            'bot_sl_pips'              => ['nullable', 'numeric', 'min:0.1'],
            'bot_trail_start_pips'     => ['nullable', 'numeric', 'min:0.1'],
            'bot_trail_pips'           => ['nullable', 'numeric', 'min:0.1'],
            'bot_trail_tp_multiplier'  => ['nullable', 'numeric', 'min:1', 'max:10'],
            'bot_min_move_pips'        => ['nullable', 'numeric', 'min:0.1'],
            'bot_max_spread_pips'      => ['nullable', 'numeric', 'min:0.1'],
            'bot_cooldown_minutes'     => ['nullable', 'integer', 'min:0'],
            'bot_session_start_utc'    => ['nullable', 'integer', 'min:0', 'max:23'],
            'bot_session_end_utc'      => ['nullable', 'integer', 'min:0', 'max:23'],
            'bot_max_trades_per_day'   => ['nullable', 'integer', 'min:1'],
            'bot_max_daily_loss_percent' => ['nullable', 'numeric', 'min:0.1'],
            'bot_ai_confirm'           => ['nullable', 'boolean'],
            'bot_max_symbols'          => ['nullable', 'integer', 'min:1'],
            'bot_ai_min_confidence'    => ['nullable', 'integer', 'min:0', 'max:100'],
            'bot_strategies'           => ['nullable', 'array'],
            'bot_strategies.*'         => ['required', 'in:momentum,sma_cross,ema_cross,bollinger_reversion,vwap_reversion'],
            'bot_signal_timeframes'    => ['nullable', 'array'],
            'bot_signal_timeframes.*'  => ['required', 'in:5m,15m,30m,1h,4h'],
            'bot_profiles'             => ['nullable', 'string'],
        ]);

        $validated['demo_only'] = $request->boolean('demo_only');
        $validated['bot_ai_confirm'] = $request->boolean('bot_ai_confirm');
        $validated['alpaca_paper'] = $request->boolean('alpaca_paper');
        $validated['bot_strategies'] = $this->normalizeStrategies($validated['bot_strategies'] ?? null) ?? ['momentum'];
        $validated['bot_signal_timeframes'] = $this->normalizeSignalTimeframes($validated['bot_signal_timeframes'] ?? null);
        $validated['bot_profiles'] = $this->normalizeBotProfiles($validated['bot_profiles'] ?? null);

        foreach (['claude_api_key', 'perplexity_api_key', 'metaapi_token', 'alpaca_api_secret'] as $secretField) {
            if (empty($validated[$secretField])) {
                unset($validated[$secretField]);
            }
        }

        $settings->fill($validated);
        $settings->save();

        return redirect()->route('settings.edit')->with('status', 'Settings saved.');
    }

    private function normalizeBotProfiles(?string $rawProfiles): ?array
    {
        if ($rawProfiles === null || trim($rawProfiles) === '') {
            return null;
        }

        try {
            $decoded = json_decode($rawProfiles, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bot_profiles' => 'Bot profiles must be valid JSON: '.$e->getMessage(),
            ]);
        }

        if (!is_array($decoded)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bot_profiles' => 'Bot profiles JSON must be an array of profile objects.',
            ]);
        }

        $profiles = [];
        $normalizeHours = static function (mixed $hours): ?array {
            if (!is_array($hours)) {
                return null;
            }

            $normalized = [];
            foreach ($hours as $value) {
                if (!is_numeric($value)) {
                    continue;
                }

                $hour = (int) $value;
                if ($hour < 0 || $hour > 23) {
                    continue;
                }

                $normalized[] = $hour;
            }

            $normalized = array_values(array_unique($normalized));
            sort($normalized);

            return !empty($normalized) ? $normalized : null;
        };

        $normalizeSymbols = static function (mixed $symbols): ?array {
            if (!is_array($symbols)) {
                return null;
            }

            $normalized = array_values(array_unique(array_filter(
                array_map(static fn ($symbol) => strtoupper(trim((string) $symbol)), $symbols),
                static fn ($symbol) => $symbol !== ''
            )));

            return !empty($normalized) ? $normalized : null;
        };

        $normalizeTimeframes = function (mixed $timeframes): ?array {
            if (!is_array($timeframes)) {
                return null;
            }

            return $this->normalizeSignalTimeframes($timeframes);
        };

        foreach ($decoded as $index => $profile) {
            if (!is_array($profile)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'bot_profiles' => 'Each bot profile must be an object. Invalid entry at index '.$index.'.',
                ]);
            }

            $name = trim((string) ($profile['name'] ?? ''));
            if ($name === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'bot_profiles' => 'Each bot profile requires a non-empty name. Invalid entry at index '.$index.'.',
                ]);
            }

            $keyRaw = (string) ($profile['key'] ?? $name);
            $key = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $keyRaw)));
            $key = trim($key, '_');
            if ($key === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'bot_profiles' => 'Each bot profile key must contain letters, numbers, dashes, or underscores. Invalid entry at index '.$index.'.',
                ]);
            }

            $signalTimeframes = $normalizeTimeframes($profile['signal_timeframes'] ?? null)
                ?? $this->normalizeSignalTimeframes(isset($profile['signal_timeframe']) ? [(string) $profile['signal_timeframe']] : null);
            $entryTimeframe = in_array(strtolower(trim((string) ($profile['entry_timeframe'] ?? ''))), self::ALLOWED_SIGNAL_TIMEFRAMES, true)
                ? strtolower(trim((string) ($profile['entry_timeframe'] ?? '')))
                : '15m';
            if (empty($signalTimeframes)) {
                $signalTimeframes = ['1h', '4h'];
            }

            $profiles[] = [
                'signal_timeframes' => $signalTimeframes,
                'entry_timeframe' => $entryTimeframe,
                'strategy' => $this->normalizeStrategy($profile['strategy'] ?? null),
                'strategy_params' => $this->normalizeStrategyParams($profile['strategy_params'] ?? null),
                'key' => $key,
                'name' => $name,
                'enabled' => (bool) ($profile['enabled'] ?? true),
                'lot' => isset($profile['lot']) ? (float) $profile['lot'] : null,
                'tp_pips' => isset($profile['tp_pips']) ? (float) $profile['tp_pips'] : null,
                'sl_pips' => isset($profile['sl_pips']) ? (float) $profile['sl_pips'] : null,
                'trail_start_pips' => isset($profile['trail_start_pips']) ? (float) $profile['trail_start_pips'] : null,
                'trail_pips' => isset($profile['trail_pips']) ? (float) $profile['trail_pips'] : null,
                'trail_tp_multiplier' => isset($profile['trail_tp_multiplier']) ? (float) $profile['trail_tp_multiplier'] : null,
                'min_move_pips' => isset($profile['min_move_pips']) ? (float) $profile['min_move_pips'] : null,
                'max_spread_pips' => isset($profile['max_spread_pips']) ? (float) $profile['max_spread_pips'] : null,
                'cooldown_minutes' => isset($profile['cooldown_minutes']) ? (int) $profile['cooldown_minutes'] : null,
                'session_start_utc' => isset($profile['session_start_utc']) ? (int) $profile['session_start_utc'] : null,
                'session_end_utc' => isset($profile['session_end_utc']) ? (int) $profile['session_end_utc'] : null,
                'max_trades_per_day' => isset($profile['max_trades_per_day']) ? (int) $profile['max_trades_per_day'] : null,
                'max_daily_loss_percent' => isset($profile['max_daily_loss_percent']) ? (float) $profile['max_daily_loss_percent'] : null,
                'ai_confirm' => isset($profile['ai_confirm']) ? (bool) $profile['ai_confirm'] : null,
                'ai_min_confidence' => isset($profile['ai_min_confidence']) ? (int) $profile['ai_min_confidence'] : null,
                'max_symbols' => isset($profile['max_symbols']) ? (int) $profile['max_symbols'] : null,
                'max_open_positions' => isset($profile['max_open_positions']) ? (int) $profile['max_open_positions'] : null,
                'max_per_cycle' => isset($profile['max_per_cycle']) ? (int) $profile['max_per_cycle'] : null,
                'min_bot_score' => isset($profile['min_bot_score']) ? (int) $profile['min_bot_score'] : null,
                'min_effective_volume' => isset($profile['min_effective_volume']) ? (float) $profile['min_effective_volume'] : null,
                'enable_max_hold' => isset($profile['enable_max_hold']) ? (bool) $profile['enable_max_hold'] : null,
                'max_hold_minutes' => isset($profile['max_hold_minutes']) ? (int) $profile['max_hold_minutes'] : null,
                'scalper' => isset($profile['scalper']) ? (bool) $profile['scalper'] : null,
                'symbols' => isset($profile['symbols']) && is_array($profile['symbols'])
                    ? array_values(array_filter(array_map(static fn ($s) => strtoupper(trim((string) $s)), $profile['symbols']), static fn ($s) => $s !== ''))
                    : null,
                'preferred_hours_utc' => $normalizeHours($profile['preferred_hours_utc'] ?? null),
                'blocked_hours_utc' => $normalizeHours($profile['blocked_hours_utc'] ?? null),
                'preferred_symbols' => $normalizeSymbols($profile['preferred_symbols'] ?? null),
                'mt5_instance_keys' => isset($profile['mt5_instance_keys']) && is_array($profile['mt5_instance_keys'])
                    ? array_values(array_filter(array_map(static fn ($k) => trim((string) $k), $profile['mt5_instance_keys']), static fn ($k) => $k !== ''))
                    : (
                        trim((string) ($profile['mt5_instance_key'] ?? '')) !== ''
                            ? [trim((string) $profile['mt5_instance_key'])]
                            : null
                    ),
                'mt5_instance_key' => trim((string) ($profile['mt5_instance_key'] ?? '')) ?: null,
                'signal_timeframe' => in_array(strtolower(trim((string) ($profile['signal_timeframe'] ?? ''))), self::ALLOWED_SIGNAL_TIMEFRAMES, true)
                    ? strtolower(trim((string) ($profile['signal_timeframe'] ?? '')))
                    : null,
            ];
        }

        $uniqueKeys = array_unique(array_column($profiles, 'key'));
        if (count($uniqueKeys) !== count($profiles)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bot_profiles' => 'Bot profile keys must be unique.',
            ]);
        }

        return $profiles;
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

    private function normalizeStrategy(mixed $raw): ?string
    {
        $value = strtolower(trim((string) $raw));
        if ($value === '') {
            return null;
        }

        return in_array($value, self::ALLOWED_STRATEGIES, true) ? $value : null;
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
}
