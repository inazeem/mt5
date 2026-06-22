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

    /**
     * @param array<int, array<string, mixed>> $candles
     * @return array<int, float>
     */
    public static function closeSeries(array $candles): array
    {
        return array_values(array_filter(array_map(static function ($candle): ?float {
            if (!is_array($candle) || !isset($candle['close'])) {
                return null;
            }

            return (float) $candle['close'];
        }, $candles), static fn (?float $value) => $value !== null));
    }

    /**
     * Wilder RSI on the latest close in the series.
     *
     * @param array<int, float> $closes
     */
    public static function rsi(array $closes, int $period = 14): ?float
    {
        if ($period <= 0 || count($closes) < ($period + 1)) {
            return null;
        }

        $gains = [];
        $losses = [];
        for ($index = 1; $index < count($closes); $index++) {
            $change = $closes[$index] - $closes[$index - 1];
            $gains[] = $change > 0 ? $change : 0.0;
            $losses[] = $change < 0 ? abs($change) : 0.0;
        }

        if (count($gains) < $period) {
            return null;
        }

        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;

        for ($index = $period; $index < count($gains); $index++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$index]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$index]) / $period;
        }

        if ($avgLoss <= 0.0) {
            return 100.0;
        }

        $relativeStrength = $avgGain / $avgLoss;

        return 100.0 - (100.0 / (1.0 + $relativeStrength));
    }

    /**
     * Wilder ADX on the latest candle in the series.
     *
     * @param array<int, array<string, mixed>> $candles
     */
    public static function adx(array $candles, int $period = 14): ?float
    {
        if ($period <= 0 || count($candles) < ($period * 2)) {
            return null;
        }

        $highs = [];
        $lows = [];
        $closes = [];
        foreach ($candles as $candle) {
            if (!is_array($candle)) {
                continue;
            }

            $high = isset($candle['high']) ? (float) $candle['high'] : null;
            $low = isset($candle['low']) ? (float) $candle['low'] : null;
            $close = isset($candle['close']) ? (float) $candle['close'] : null;
            if ($high === null || $low === null || $close === null) {
                continue;
            }

            $highs[] = $high;
            $lows[] = $low;
            $closes[] = $close;
        }

        if (count($highs) < ($period * 2)) {
            return null;
        }

        $trueRanges = [];
        $plusDm = [];
        $minusDm = [];
        for ($index = 1; $index < count($highs); $index++) {
            $upMove = $highs[$index] - $highs[$index - 1];
            $downMove = $lows[$index - 1] - $lows[$index];
            $plusDm[] = ($upMove > $downMove && $upMove > 0) ? $upMove : 0.0;
            $minusDm[] = ($downMove > $upMove && $downMove > 0) ? $downMove : 0.0;
            $trueRanges[] = max(
                $highs[$index] - $lows[$index],
                abs($highs[$index] - $closes[$index - 1]),
                abs($lows[$index] - $closes[$index - 1])
            );
        }

        if (count($trueRanges) < $period) {
            return null;
        }

        $smoothedTr = array_sum(array_slice($trueRanges, 0, $period));
        $smoothedPlusDm = array_sum(array_slice($plusDm, 0, $period));
        $smoothedMinusDm = array_sum(array_slice($minusDm, 0, $period));

        $dxValues = [];
        for ($index = $period; $index < count($trueRanges); $index++) {
            $smoothedTr = $smoothedTr - ($smoothedTr / $period) + $trueRanges[$index];
            $smoothedPlusDm = $smoothedPlusDm - ($smoothedPlusDm / $period) + $plusDm[$index];
            $smoothedMinusDm = $smoothedMinusDm - ($smoothedMinusDm / $period) + $minusDm[$index];

            if ($smoothedTr <= 0.0) {
                continue;
            }

            $plusDi = 100.0 * ($smoothedPlusDm / $smoothedTr);
            $minusDi = 100.0 * ($smoothedMinusDm / $smoothedTr);
            $diSum = $plusDi + $minusDi;
            if ($diSum <= 0.0) {
                continue;
            }

            $dxValues[] = 100.0 * (abs($plusDi - $minusDi) / $diSum);
        }

        if (count($dxValues) < $period) {
            return null;
        }

        $adx = array_sum(array_slice($dxValues, 0, $period)) / $period;
        for ($index = $period; $index < count($dxValues); $index++) {
            $adx = (($adx * ($period - 1)) + $dxValues[$index]) / $period;
        }

        return $adx;
    }
}
