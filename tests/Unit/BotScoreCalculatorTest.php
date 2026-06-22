<?php

namespace Tests\Unit;

use App\Services\BotScoreCalculator;
use PHPUnit\Framework\TestCase;

class BotScoreCalculatorTest extends TestCase
{
    private BotScoreCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new BotScoreCalculator();
    }

    public function test_forex_signal_and_tight_spread_score_high(): void
    {
        $result = $this->calculator->calculate(10.0, 0.5, 'forex', 2.5, 15.0);

        $this->assertGreaterThanOrEqual(90, $result['score']);
        $this->assertSame('forex', $result['components']['spread_category']);
    }

    public function test_crypto_uses_wider_signal_reference(): void
    {
        $moderate = $this->calculator->calculate(60.0, 40.0, 'crypto', 50.0, 100.0);
        $strong = $this->calculator->calculate(120.0, 40.0, 'crypto', 50.0, 100.0);

        $this->assertGreaterThan($moderate['score'], $strong['score']);
        $this->assertSame(120.0, $strong['components']['signal_reference_pips']);
    }

    public function test_spread_penalty_when_spread_exceeds_quarter_of_sl(): void
    {
        $withoutPenalty = $this->calculator->calculate(10.0, 3.0, 'forex', 10.0, 20.0);
        $withPenalty = $this->calculator->calculate(10.0, 6.0, 'forex', 10.0, 20.0);

        $this->assertLessThan(
            $withoutPenalty['components']['spread_score'],
            $withPenalty['components']['spread_score']
        );
    }

    public function test_wide_spread_relative_to_max_lowers_score(): void
    {
        $tight = $this->calculator->calculate(10.0, 5.0, 'crypto', 50.0, 100.0);
        $wide = $this->calculator->calculate(10.0, 45.0, 'crypto', 50.0, 100.0);

        $this->assertGreaterThan($wide['score'], $tight['score']);
    }

    public function test_adx_below_floor_hard_rejects(): void
    {
        $result = $this->calculator->calculate(
            10.0,
            0.5,
            'forex',
            2.5,
            15.0,
            'buy',
            18.0,
            null,
            null,
            true,
            false,
        );

        $this->assertTrue($result['hard_reject']);
        $this->assertSame('adx_below_floor', $result['hard_reject_reason']);
    }

    public function test_adx_and_rsi_enabled_use_extended_weights_and_components(): void
    {
        $withIndicators = $this->calculator->calculate(
            10.0,
            0.5,
            'forex',
            2.5,
            15.0,
            'buy',
            35.0,
            58.0,
            62.0,
            true,
            true,
        );

        $this->assertSame(0.45, $withIndicators['components']['signal_weight']);
        $this->assertSame(0.25, $withIndicators['components']['spread_weight']);
        $this->assertNotNull($withIndicators['components']['adx_score']);
        $this->assertNotNull($withIndicators['components']['rsi_score']);
        $this->assertGreaterThanOrEqual(80, $withIndicators['score']);
    }

    public function test_rsi_misalignment_lowers_buy_score(): void
    {
        $aligned = $this->calculator->calculate(
            10.0,
            0.5,
            'forex',
            2.5,
            15.0,
            'buy',
            30.0,
            58.0,
            60.0,
            true,
            true,
        );
        $misaligned = $this->calculator->calculate(
            10.0,
            0.5,
            'forex',
            2.5,
            15.0,
            'buy',
            30.0,
            35.0,
            78.0,
            true,
            true,
        );

        $this->assertGreaterThan($misaligned['score'], $aligned['score']);
    }

    public function test_weak_adx_lowers_score_without_hard_reject_when_above_floor(): void
    {
        $weak = $this->calculator->calculate(
            10.0,
            0.5,
            'forex',
            2.5,
            15.0,
            'buy',
            24.0,
            null,
            null,
            true,
            false,
        );
        $strong = $this->calculator->calculate(
            10.0,
            0.5,
            'forex',
            2.5,
            15.0,
            'buy',
            40.0,
            null,
            null,
            true,
            false,
        );

        $this->assertFalse($weak['hard_reject']);
        $this->assertGreaterThan($weak['score'], $strong['score']);
    }
}
