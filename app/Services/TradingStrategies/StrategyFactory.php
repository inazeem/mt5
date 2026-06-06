<?php

namespace App\Services\TradingStrategies;

class StrategyFactory
{
    /**
     * @return array<int, string>
     */
    public function supportedKeys(): array
    {
        return [
            'momentum',
            'sma_cross',
            'ema_cross',
            'bollinger_reversion',
            'vwap_reversion',
        ];
    }

    public function make(?string $key): TradingStrategyInterface
    {
        return match (strtolower(trim((string) $key))) {
            'sma_cross' => new SmaCrossStrategy(),
            'ema_cross' => new EmaCrossStrategy(),
            'bollinger_reversion' => new BollingerReversionStrategy(),
            'vwap_reversion' => new VwapReversionStrategy(),
            default => new MomentumStrategy(),
        };
    }
}
