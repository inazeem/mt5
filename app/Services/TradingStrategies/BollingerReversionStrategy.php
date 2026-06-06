<?php

namespace App\Services\TradingStrategies;

class BollingerReversionStrategy implements TradingStrategyInterface
{
    public function key(): string
    {
        return 'bollinger_reversion';
    }

    public function requiredCandles(): int
    {
        return 302;
    }

    public function evaluate(array $context): array
    {
        $params = is_array($context['strategy_params'] ?? null) ? $context['strategy_params'] : [];
        $period = max(5, min(300, (int) ($params['bb_period'] ?? 20)));
        $multiplier = max(0.5, min(5.0, (float) ($params['bb_stddev'] ?? 2.0)));

        $candles = is_array($context['candles'] ?? null) ? $context['candles'] : [];
        $pipSize = (float) ($context['pip_size'] ?? 0.0001);
        $closes = $this->closeSeries($candles);

        if (count($closes) < ($period + 2)) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Not enough candles for Bollinger strategy.'];
        }

        $middle = IndicatorMath::sma($closes, $period);
        $stdDev = IndicatorMath::stdDev($closes, $period);
        $lastClose = $closes[array_key_last($closes)] ?? null;

        if ($middle === null || $stdDev === null || $lastClose === null) {
            return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Unable to compute Bollinger values.'];
        }

        $upper = $middle + ($multiplier * $stdDev);
        $lower = $middle - ($multiplier * $stdDev);

        if ($lastClose < $lower) {
            $strength = $pipSize > 0 ? abs(($lower - $lastClose) / $pipSize) : 0.0;
            return [
                'signal' => true,
                'side' => 'buy',
                'signal_delta_pips' => $strength,
                'meta_payload' => [
                    'strategy' => $this->key(),
                    'bb_period' => $period,
                    'bb_stddev' => $multiplier,
                    'bb_upper' => $upper,
                    'bb_middle' => $middle,
                    'bb_lower' => $lower,
                ],
            ];
        }

        if ($lastClose > $upper) {
            $strength = $pipSize > 0 ? abs(($lastClose - $upper) / $pipSize) : 0.0;
            return [
                'signal' => true,
                'side' => 'sell',
                'signal_delta_pips' => $strength,
                'meta_payload' => [
                    'strategy' => $this->key(),
                    'bb_period' => $period,
                    'bb_stddev' => $multiplier,
                    'bb_upper' => $upper,
                    'bb_middle' => $middle,
                    'bb_lower' => $lower,
                ],
            ];
        }

        return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'No Bollinger band reversion signal.'];
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
