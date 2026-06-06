<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\Request;

class StrategyController extends Controller
{
    public function edit()
    {
        $settings = AppSetting::singleton();

        return view('strategies.edit', compact('settings'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'bot_strategy_params' => ['nullable', 'array'],
            'bot_strategy_params.sma_fast' => ['nullable', 'integer', 'min:2', 'max:200'],
            'bot_strategy_params.sma_slow' => ['nullable', 'integer', 'min:3', 'max:300'],
            'bot_strategy_params.sma_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'bot_strategy_params.ema_fast' => ['nullable', 'integer', 'min:2', 'max:200'],
            'bot_strategy_params.ema_slow' => ['nullable', 'integer', 'min:3', 'max:300'],
            'bot_strategy_params.ema_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'bot_strategy_params.bb_period' => ['nullable', 'integer', 'min:5', 'max:300'],
            'bot_strategy_params.bb_stddev' => ['nullable', 'numeric', 'min:0.5', 'max:5'],
            'bot_strategy_params.bb_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
            'bot_strategy_params.vwap_period' => ['nullable', 'integer', 'min:5', 'max:500'],
            'bot_strategy_params.vwap_min_distance_pips' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'bot_strategy_params.vwap_confirm_candles' => ['nullable', 'integer', 'min:0', 'max:5'],
        ]);

        $settings = AppSetting::singleton();
        $existing = is_array($settings->bot_strategy_params ?? null) ? $settings->bot_strategy_params : [];
        $incoming = is_array($validated['bot_strategy_params'] ?? null) ? $validated['bot_strategy_params'] : [];

        // Popup forms submit only one strategy section, so merge to preserve other values.
        $merged = array_merge($existing, $incoming);

        $settings->bot_strategy_params = $this->normalizeStrategyParams($merged);
        $settings->save();

        return redirect()->route('strategies.edit')->with('status', 'Strategy parameters saved.');
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
