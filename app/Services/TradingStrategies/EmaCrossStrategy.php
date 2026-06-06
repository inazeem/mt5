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
        $prevFast = IndicatorMath::ema(array_slice($closes, 0, -1), $fastPeriod);
        $prevSlow = IndicatorMath::ema(array_slice($closes, 0, -1), $slowPeriod);

        if ($currentFast === null || $currentSlow === null || $prevFast === null || $prevSlow === null) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Unable to compute EMA values.'];
        }

        $side = null;
        if ($prevFast <= $prevSlow && $currentFast > $currentSlow) {
            $side = 'buy';
        } elseif ($prevFast >= $prevSlow && $currentFast < $currentSlow) {
            $side = 'sell';
        }

        if ($side === null) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'No EMA crossover signal.'];
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
