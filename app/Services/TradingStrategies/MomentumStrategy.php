<?php

namespace App\Services\TradingStrategies;

class MomentumStrategy implements TradingStrategyInterface
{
    public function key(): string
    {
        return 'momentum';
    }

    public function requiredCandles(): int
    {
        return 0;
    }

    public function evaluate(array $context): array
    {
        $bid = (float) ($context['bid'] ?? 0);
        $lastBid = $context['last_bid'] ?? null;
        $pipSize = (float) ($context['pip_size'] ?? 0.0001);
        $minMovePips = (float) ($context['min_move_pips'] ?? 3);
        $minMove = $minMovePips * $pipSize;

        if (!is_numeric($lastBid)) {
            return [
                'signal' => false,
                'status' => 'no_move_rejected',
                'message' => 'Signal skipped because no prior tick was available yet.',
            ];
        }

        $delta = $bid - (float) $lastBid;
        if (abs($delta) < $minMove) {
            return [
                'signal' => false,
                'status' => 'no_move_rejected',
                'message' => 'Signal rejected because price move is below minimum threshold.',
                'signal_delta_pips' => $pipSize > 0 ? ($delta / $pipSize) : 0.0,
            ];
        }

        return [
            'signal' => true,
            'side' => $delta >= 0 ? 'buy' : 'sell',
            'signal_delta_pips' => $pipSize > 0 ? ($delta / $pipSize) : 0.0,
            'meta_payload' => [
                'strategy' => $this->key(),
            ],
        ];
    }
}
