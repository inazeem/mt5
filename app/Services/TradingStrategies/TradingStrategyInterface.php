<?php

namespace App\Services\TradingStrategies;

interface TradingStrategyInterface
{
    public function key(): string;

    public function requiredCandles(): int;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function evaluate(array $context): array;
}
