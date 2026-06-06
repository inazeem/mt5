<?php

namespace App\Services\TradingStrategies;

class EmaCrossStrategy implements TradingStrategyInterface
{
    public function key(): string
    {
        return 'ema_cross';
    }

    public function requiredCandles(): int
    {
        return 306;
    }

    public function evaluate(array $context): array
    {
        $params = is_array($context['strategy_params'] ?? null) ? $context['strategy_params'] : [];
        $fastPeriod = max(2, min(200, (int) ($params['ema_fast'] ?? 9)));
        $slowPeriod = max(3, min(300, (int) ($params['ema_slow'] ?? 21)));
        $confirmCandles = max(0, min(5, (int) ($params['ema_confirm_candles'] ?? 0)));
        if ($fastPeriod >= $slowPeriod) {
            $slowPeriod = min(300, $fastPeriod + 1);
        }

        $candles = is_array($context['candles'] ?? null) ? $context['candles'] : [];
        $pipSize = (float) ($context['pip_size'] ?? 0.0001);
        $closes = $this->closeSeries($candles);

        if (count($closes) < ($slowPeriod + 6)) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Not enough candles for EMA strategy.'];
        }

        $currentFast = IndicatorMath::ema($closes, $fastPeriod);
        $currentSlow = IndicatorMath::ema($closes, $slowPeriod);

        if ($currentFast === null || $currentSlow === null) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Unable to compute EMA values.'];
        }

        if ($currentFast === $currentSlow) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'EMA lines are flat/equal; no directional edge.'];
        }

        $side = $currentFast > $currentSlow ? 'buy' : 'sell';

        if ($confirmCandles > 0) {
            for ($offset = 1; $offset <= $confirmCandles; $offset++) {
                $slice = array_slice($closes, 0, -$offset);
                $fast = IndicatorMath::ema($slice, $fastPeriod);
                $slow = IndicatorMath::ema($slice, $slowPeriod);

                if ($fast === null || $slow === null) {
                    return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Not enough candles for EMA confirmation window.'];
                }

                if ($fast === $slow) {
                    return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'EMA confirmation pending due to flat crossover zone.'];
                }

                $historicalSide = $fast > $slow ? 'buy' : 'sell';
                if ($historicalSide !== $side) {
                    return [
                        'signal' => false,
                        'status' => 'strategy_rejected',
                        'message' => 'EMA confirmation pending: crossover has not held long enough.',
                        'meta_payload' => [
                            'strategy' => $this->key(),
                            'ema_confirm_candles' => $confirmCandles,
                        ],
                    ];
                }
            }
        }

        $strength = $pipSize > 0 ? abs(($currentFast - $currentSlow) / $pipSize) : 0.0;

        return [
            'signal' => true,
            'side' => $side,
            'signal_delta_pips' => $strength,
            'meta_payload' => [
                'strategy' => $this->key(),
                'ema_fast_period' => $fastPeriod,
                'ema_slow_period' => $slowPeriod,
                'ema_confirm_candles' => $confirmCandles,
                'ema_fast' => $currentFast,
                'ema_slow' => $currentSlow,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $candles
     * @return array<int, float>
     */
    private function closeSeries(array $candles): array
    {
        return array_values(array_filter(array_map(static function ($candle) {
            if (!is_array($candle) || !isset($candle['close'])) {
                return null;
            }

            return (float) $candle['close'];
        }, $candles), static fn ($value) => $value !== null));
    }
}
