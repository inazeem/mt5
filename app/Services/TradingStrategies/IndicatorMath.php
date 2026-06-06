<?php

namespace App\Services\TradingStrategies;

final class IndicatorMath
{
    /**
     * @param array<int, float> $values
     */
    public static function sma(array $values, int $period): ?float
    {
        if ($period <= 0 || count($values) < $period) {
            return null;
        }

        $slice = array_slice($values, -$period);
        return array_sum($slice) / $period;
    }

    /**
     * @param array<int, float> $values
     */
    public static function ema(array $values, int $period): ?float
    {
        if ($period <= 0 || count($values) < $period) {
            return null;
        }

        $multiplier = 2 / ($period + 1);
        $ema = self::sma(array_slice($values, 0, $period), $period);
        if ($ema === null) {
            return null;
        }

        foreach (array_slice($values, $period) as $value) {
            $ema = (($value - $ema) * $multiplier) + $ema;
        }

        return $ema;
    }

    /**
     * @param array<int, float> $values
     */
    public static function stdDev(array $values, int $period): ?float
    {
        if ($period <= 0 || count($values) < $period) {
            return null;
        }

        $slice = array_slice($values, -$period);
        $mean = array_sum($slice) / $period;
        $variance = 0.0;
        foreach ($slice as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return sqrt($variance / $period);
    }

    /**
     * @param array<int, array<string, mixed>> $candles
     */
    public static function vwap(array $candles): ?float
    {
        if (empty($candles)) {
            return null;
        }

        $weightedPriceSum = 0.0;
        $volumeSum = 0.0;

        foreach ($candles as $candle) {
            if (!is_array($candle)) {
                continue;
            }

            $high = isset($candle['high']) ? (float) $candle['high'] : null;
            $low = isset($candle['low']) ? (float) $candle['low'] : null;
            $close = isset($candle['close']) ? (float) $candle['close'] : null;
            $volume = isset($candle['tickVolume']) ? (float) $candle['tickVolume'] : 0.0;

            if ($high === null || $low === null || $close === null || $volume <= 0) {
                continue;
            }

            $typicalPrice = ($high + $low + $close) / 3;
            $weightedPriceSum += $typicalPrice * $volume;
            $volumeSum += $volume;
        }

        if ($volumeSum <= 0) {
            return null;
        }

        return $weightedPriceSum / $volumeSum;
    }
}
