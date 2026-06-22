<?php

namespace Tests\Unit;

use App\Services\AlpacaService;
use PHPUnit\Framework\TestCase;

class AlpacaServiceOrderQtyTest extends TestCase
{
    public function test_bumps_qty_when_notional_below_ten_dollars(): void
    {
        $service = new AlpacaService();

        $qty = $service->resolveOrderQty('ETHUSD', 0.001, 'buy', 1736.0);

        $this->assertGreaterThanOrEqual(10.0, $qty * 1736.0);
        $this->assertGreaterThan(0.001, $qty);
    }

    public function test_keeps_qty_when_notional_already_meets_minimum(): void
    {
        $service = new AlpacaService();

        $qty = $service->resolveOrderQty('BTCUSD', 0.001, 'buy', 64000.0);

        $this->assertSame(0.001, $qty);
    }

    public function test_sell_without_holdings_returns_zero_qty(): void
    {
        $service = new AlpacaService();

        $qty = $service->resolveOrderQty('SOLUSD', 0.001, 'sell', 74.0, 0.0);

        $this->assertSame(0.0, $qty);
    }
}
