<?php

namespace App\Services\TradingStrategies;

class VwapReversionStrategy implements TradingStrategyInterface
{
    public function key(): string
    {
        return 'vwap_reversion';
    }

    public function requiredCandles(): int
    {
        return 500;
    }

    public function evaluate(array $context): array
    {
        $params = is_array($context['strategy_params'] ?? null) ? $context['strategy_params'] : [];
        $period = max(5, min(500, (int) ($params['vwap_period'] ?? 30)));
        $minDistancePips = max(0.1, min(100.0, (float) ($params['vwap_min_distance_pips'] ?? ($context['min_move_pips'] ?? 3))));

        $candles = is_array($context['candles'] ?? null) ? $context['candles'] : [];
        $pipSize = (float) ($context['pip_size'] ?? 0.0001);

        if (count($candles) < $period) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Not enough candles for VWAP strategy.'];
        }

        $series = array_slice($candles, -$period);
        $vwap = IndicatorMath::vwap($series);
        $last = $series[array_key_last($series)] ?? null;
        $close = is_array($last) && isset($last['close']) ? (float) $last['close'] : null;

        if ($vwap === null || $close === null) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Unable to compute VWAP values.'];
        }

        $distancePips = $pipSize > 0 ? (($close - $vwap) / $pipSize) : 0.0;

        if ($distancePips <= -$minDistancePips) {
            return [
                'signal' => true,
                'side' => 'buy',
                'signal_delta_pips' => abs($distancePips),
                'meta_payload' => [
                    'strategy' => $this->key(),
                    'vwap_period' => $period,
                    'vwap_min_distance_pips' => $minDistancePips,
                    'vwap' => $vwap,
                    'distance_pips' => $distancePips,
                ],
            ];
        }

        if ($distancePips >= $minDistancePips) {
            return [
                'signal' => true,
                'side' => 'sell',
                'signal_delta_pips' => abs($distancePips),
                'meta_payload' => [
                    'strategy' => $this->key(),
                    'vwap_period' => $period,
                    'vwap_min_distance_pips' => $minDistancePips,
                    'vwap' => $vwap,
                    'distance_pips' => $distancePips,
                ],
            ];
        }

        return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'No VWAP reversion signal.'];
    }
}
