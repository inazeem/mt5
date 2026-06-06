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
        $confirmCandles = max(0, min(5, (int) ($params['bb_confirm_candles'] ?? 0)));

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
            if ($confirmCandles > 0 && !$this->holdsBandBreakForCandles($closes, $period, $multiplier, 'buy', $confirmCandles)) {
                return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Bollinger confirmation pending: break has not persisted long enough.'];
            }
            $strength = $pipSize > 0 ? abs(($lower - $lastClose) / $pipSize) : 0.0;
            return [
                'signal' => true,
                'side' => 'buy',
                'signal_delta_pips' => $strength,
                'meta_payload' => [
                    'strategy' => $this->key(),
                    'bb_period' => $period,
                    'bb_stddev' => $multiplier,
                    'bb_confirm_candles' => $confirmCandles,
                    'bb_upper' => $upper,
                    'bb_middle' => $middle,
                    'bb_lower' => $lower,
                ],
            ];
        }

        if ($lastClose > $upper) {
            if ($confirmCandles > 0 && !$this->holdsBandBreakForCandles($closes, $period, $multiplier, 'sell', $confirmCandles)) {
                return ['signal' => false, 'status' => 'strategy_rejected', 'message' => 'Bollinger confirmation pending: break has not persisted long enough.'];
            }
            $strength = $pipSize > 0 ? abs(($lastClose - $upper) / $pipSize) : 0.0;
            return [
                'signal' => true,
                'side' => 'sell',
                'signal_delta_pips' => $strength,
                'meta_payload' => [
                    'strategy' => $this->key(),
                    'bb_period' => $period,
                    'bb_stddev' => $multiplier,
                    'bb_confirm_candles' => $confirmCandles,
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

    /**
     * @param array<int, float> $closes
     */
    private function holdsBandBreakForCandles(array $closes, int $period, float $multiplier, string $side, int $confirmCandles): bool
    {
        for ($offset = 0; $offset <= $confirmCandles; $offset++) {
            $slice = $offset === 0 ? $closes : array_slice($closes, 0, -$offset);
            $middle = IndicatorMath::sma($slice, $period);
            $stdDev = IndicatorMath::stdDev($slice, $period);
            $close = $slice[array_key_last($slice)] ?? null;

            if ($middle === null || $stdDev === null || $close === null) {
                return false;
            }

            $upper = $middle + ($multiplier * $stdDev);
            $lower = $middle - ($multiplier * $stdDev);

            if ($side === 'buy' && !($close < $lower)) {
                return false;
            }

            if ($side === 'sell' && !($close > $upper)) {
                return false;
            }
        }

        return true;
    }
}
