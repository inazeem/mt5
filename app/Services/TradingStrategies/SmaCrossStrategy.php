<?php

namespace App\Services\TradingStrategies;

class SmaCrossStrategy implements TradingStrategyInterface
{
    public function key(): string
    {
        return 'sma_cross';
    }

    public function requiredCandles(): int
    {
        return 302;
    }

    public function evaluate(array $context): array
    {
        $params = is_array($context['strategy_params'] ?? null) ? $context['strategy_params'] : [];
        $fastPeriod = max(2, min(200, (int) ($params['sma_fast'] ?? 9)));
        $slowPeriod = max(3, min(300, (int) ($params['sma_slow'] ?? 21)));
        if ($fastPeriod >= $slowPeriod) {
            $slowPeriod = min(300, $fastPeriod + 1);
        }

        $candles = is_array($context['candles'] ?? null) ? $context['candles'] : [];
        $pipSize = (float) ($context['pip_size'] ?? 0.0001);
        $closes = $this->closeSeries($candles);

        if (count($closes) < ($slowPeriod + 2)) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Not enough candles for SMA strategy.'];
        }

        $currentFast = IndicatorMath::sma($closes, $fastPeriod);
        $currentSlow = IndicatorMath::sma($closes, $slowPeriod);
        $prevFast = IndicatorMath::sma(array_slice($closes, 0, -1), $fastPeriod);
        $prevSlow = IndicatorMath::sma(array_slice($closes, 0, -1), $slowPeriod);

        if ($currentFast === null || $currentSlow === null || $prevFast === null || $prevSlow === null) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Unable to compute SMA values.'];
        }

        $side = null;
        if ($prevFast <= $prevSlow && $currentFast > $currentSlow) {
            $side = 'buy';
        } elseif ($prevFast >= $prevSlow && $currentFast < $currentSlow) {
            $side = 'sell';
        }

        if ($side === null) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'No SMA crossover signal.'];
        }

        $strength = $pipSize > 0 ? abs(($currentFast - $currentSlow) / $pipSize) : 0.0;

        return [
            'signal' => true,
            'side' => $side,
            'signal_delta_pips' => $strength,
            'meta_payload' => [
                'strategy' => $this->key(),
                'sma_fast_period' => $fastPeriod,
                'sma_slow_period' => $slowPeriod,
                'sma_fast' => $currentFast,
                'sma_slow' => $currentSlow,
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
