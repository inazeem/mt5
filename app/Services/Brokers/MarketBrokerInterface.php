<?php

namespace App\Services\Brokers;

interface MarketBrokerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getTickerPrice(string $symbol): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCandles(string $symbol, string $timeframe = '1h', int $limit = 20): array;

    /**
     * @param array<int, array<string, mixed>> $exitLegs
     * @return array<string, mixed>
     */
    public function placeOrder(string $symbol, float $lotSize, string $side, array $exitLegs = []): array;

    /**
     * @return array<string, mixed>
     */
    public function getOpenTradeSnapshot(): array;

    /**
     * @return array<string, mixed>
     */
    public function getAccountInformation(): array;

    /**
     * Map a canonical symbol to the broker-specific tradable symbol.
     */
    public function toBrokerSymbol(string $symbol): string;

    public function baseSymbol(string $symbol): string;
}
