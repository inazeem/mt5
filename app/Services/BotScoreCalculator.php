<?php

namespace App\Services;

/**
 * Composite trade-quality score (0–100) for auto-bot signals.
 *
 * Signal and spread components are normalized per asset category.
 * Optional ADX (trend strength) and RSI (trend momentum alignment) adjust the score.
 * Volume is enforced separately via min_effective_volume (not part of the score).
 */
class BotScoreCalculator
{
    /** Pips (or points) of strategy strength that map to a 100 signal score. */
    private const SIGNAL_FULL_SCORE_PIPS_BY_CATEGORY = [
        'forex' => 10.0,
        'crypto' => 120.0,
        'stock' => 30.0,
        'commodity' => 50.0,
        'other' => 25.0,
        'default' => 10.0,
    ];

    private const ADX_MIN_FLOOR_BY_CATEGORY = [
        'forex' => 22.0,
        'crypto' => 18.0,
        'stock' => 20.0,
        'commodity' => 20.0,
        'other' => 20.0,
        'default' => 22.0,
    ];

    private const ADX_STRONG_BY_CATEGORY = [
        'forex' => 35.0,
        'crypto' => 40.0,
        'stock' => 32.0,
        'commodity' => 32.0,
        'other' => 30.0,
        'default' => 35.0,
    ];

    /**
     * @return array{
     *     score: int,
     *     hard_reject: bool,
     *     hard_reject_reason: string|null,
     *     components: array<string, float|int|string|bool|null>
     * }
     */
    public function calculate(
        float $signalDeltaPips,
        float $spreadPips,
        string $spreadCategory,
        float $maxSpreadForSymbol,
        ?float $slPipsForSymbol = null,
        ?string $side = null,
        ?float $adx = null,
        ?float $rsiHtf = null,
        ?float $rsiEntry = null,
        bool $useAdxScore = false,
        bool $useRsiScore = false,
        ?float $adxMinFloor = null,
    ): array {
        $category = $this->normalizeCategory($spreadCategory);
        $signalReferencePips = self::SIGNAL_FULL_SCORE_PIPS_BY_CATEGORY[$category]
            ?? self::SIGNAL_FULL_SCORE_PIPS_BY_CATEGORY['default'];

        $signalStrengthScore = min(100.0, (abs($signalDeltaPips) / $signalReferencePips) * 100.0);

        $maxSpreadReference = max(0.1, $maxSpreadForSymbol);
        $spreadScore = max(0.0, min(100.0, (1.0 - ($spreadPips / $maxSpreadReference)) * 100.0));

        if ($slPipsForSymbol !== null && $slPipsForSymbol > 0 && $spreadPips > ($slPipsForSymbol * 0.25)) {
            $spreadScore *= 0.5;
        }

        $adxScore = null;
        $rsiScore = null;
        $hardReject = false;
        $hardRejectReason = null;
        $resolvedAdxFloor = $adxMinFloor ?? (self::ADX_MIN_FLOOR_BY_CATEGORY[$category] ?? self::ADX_MIN_FLOOR_BY_CATEGORY['default']);

        if ($useAdxScore && $adx !== null) {
            if ($adx < $resolvedAdxFloor) {
                $hardReject = true;
                $hardRejectReason = 'adx_below_floor';
            }
            $adxScore = $this->adxStrengthScore($adx, $category);
        }

        if ($useRsiScore && $side !== null && ($rsiHtf !== null || $rsiEntry !== null)) {
            $rsiScore = $this->rsiTrendAlignmentScore($side, $rsiHtf, $rsiEntry, $category);
        }

        $weights = $this->resolveWeights($useAdxScore && $adxScore !== null, $useRsiScore && $rsiScore !== null);

        $score = ($signalStrengthScore * $weights['signal'])
            + ($spreadScore * $weights['spread']);

        if ($adxScore !== null) {
            $score += $adxScore * $weights['adx'];
        }
        if ($rsiScore !== null) {
            $score += $rsiScore * $weights['rsi'];
        }

        return [
            'score' => max(0, min(100, (int) round($score))),
            'hard_reject' => $hardReject,
            'hard_reject_reason' => $hardRejectReason,
            'components' => [
                'spread_category' => $category,
                'signal_delta_pips' => round(abs($signalDeltaPips), 4),
                'signal_reference_pips' => $signalReferencePips,
                'signal_strength_score' => round($signalStrengthScore, 2),
                'spread_pips' => round($spreadPips, 4),
                'max_spread_reference_pips' => round($maxSpreadReference, 4),
                'spread_score' => round($spreadScore, 2),
                'sl_pips_for_symbol' => $slPipsForSymbol !== null ? round($slPipsForSymbol, 4) : null,
                'use_adx_score' => $useAdxScore,
                'use_rsi_score' => $useRsiScore,
                'adx' => $adx !== null ? round($adx, 2) : null,
                'adx_min_floor' => round($resolvedAdxFloor, 2),
                'adx_score' => $adxScore !== null ? round($adxScore, 2) : null,
                'rsi_htf' => $rsiHtf !== null ? round($rsiHtf, 2) : null,
                'rsi_entry' => $rsiEntry !== null ? round($rsiEntry, 2) : null,
                'rsi_score' => $rsiScore !== null ? round($rsiScore, 2) : null,
                'signal_weight' => $weights['signal'],
                'spread_weight' => $weights['spread'],
                'adx_weight' => $weights['adx'],
                'rsi_weight' => $weights['rsi'],
            ],
        ];
    }

    /**
     * @return array{signal: float, spread: float, adx: float, rsi: float}
     */
    private function resolveWeights(bool $hasAdx, bool $hasRsi): array
    {
        if ($hasAdx && $hasRsi) {
            return ['signal' => 0.45, 'spread' => 0.25, 'adx' => 0.15, 'rsi' => 0.15];
        }
        if ($hasAdx) {
            return ['signal' => 0.55, 'spread' => 0.25, 'adx' => 0.20, 'rsi' => 0.0];
        }
        if ($hasRsi) {
            return ['signal' => 0.55, 'spread' => 0.25, 'adx' => 0.0, 'rsi' => 0.20];
        }

        return ['signal' => 0.70, 'spread' => 0.30, 'adx' => 0.0, 'rsi' => 0.0];
    }

    private function adxStrengthScore(float $adx, string $category): float
    {
        $weak = self::ADX_MIN_FLOOR_BY_CATEGORY[$category] ?? self::ADX_MIN_FLOOR_BY_CATEGORY['default'];
        $strong = self::ADX_STRONG_BY_CATEGORY[$category] ?? self::ADX_STRONG_BY_CATEGORY['default'];
        if ($strong <= $weak) {
            return 0.0;
        }

        return max(0.0, min(100.0, (($adx - $weak) / ($strong - $weak)) * 100.0));
    }

    private function rsiTrendAlignmentScore(string $side, ?float $rsiHtf, ?float $rsiEntry, string $category): float
    {
        $overbought = $category === 'crypto' ? 78.0 : 72.0;
        $oversold = $category === 'crypto' ? 22.0 : 28.0;
        $scores = [];

        if ($rsiHtf !== null) {
            $scores[] = strtolower($side) === 'buy'
                ? $this->rsiBuyHtfScore($rsiHtf)
                : $this->rsiSellHtfScore($rsiHtf);
        }

        if ($rsiEntry !== null) {
            $scores[] = strtolower($side) === 'buy'
                ? $this->rsiBuyEntryScore($rsiEntry, $overbought, $oversold)
                : $this->rsiSellEntryScore($rsiEntry, $overbought, $oversold);
        }

        if (empty($scores)) {
            return 50.0;
        }

        return array_sum($scores) / count($scores);
    }

    private function rsiBuyHtfScore(float $rsi): float
    {
        if ($rsi < 40.0) {
            return max(0.0, ($rsi / 40.0) * 40.0);
        }
        if ($rsi < 50.0) {
            return 40.0 + (($rsi - 40.0) / 10.0) * 30.0;
        }
        if ($rsi <= 68.0) {
            return 70.0 + (($rsi - 50.0) / 18.0) * 30.0;
        }

        return 65.0;
    }

    private function rsiSellHtfScore(float $rsi): float
    {
        if ($rsi > 60.0) {
            return max(0.0, ((100.0 - $rsi) / 40.0) * 40.0);
        }
        if ($rsi > 50.0) {
            return 40.0 + ((60.0 - $rsi) / 10.0) * 30.0;
        }
        if ($rsi >= 32.0) {
            return 70.0 + ((50.0 - $rsi) / 18.0) * 30.0;
        }

        return 65.0;
    }

    private function rsiBuyEntryScore(float $rsi, float $overbought, float $oversold): float
    {
        if ($rsi >= $overbought) {
            return 25.0;
        }
        if ($rsi <= $oversold) {
            return 55.0;
        }
        if ($rsi >= 50.0) {
            return 70.0 + min(30.0, (($rsi - 50.0) / max(1.0, $overbought - 50.0)) * 30.0);
        }

        return 55.0 + (($rsi - $oversold) / max(1.0, 50.0 - $oversold)) * 15.0;
    }

    private function rsiSellEntryScore(float $rsi, float $overbought, float $oversold): float
    {
        if ($rsi <= $oversold) {
            return 25.0;
        }
        if ($rsi >= $overbought) {
            return 55.0;
        }
        if ($rsi <= 50.0) {
            return 70.0 + min(30.0, ((50.0 - $rsi) / max(1.0, 50.0 - $oversold)) * 30.0);
        }

        return 55.0 + (($overbought - $rsi) / max(1.0, $overbought - 50.0)) * 15.0;
    }

    private function normalizeCategory(string $spreadCategory): string
    {
        $category = strtolower(trim($spreadCategory));

        return array_key_exists($category, self::SIGNAL_FULL_SCORE_PIPS_BY_CATEGORY)
            ? $category
            : 'default';
    }
}
