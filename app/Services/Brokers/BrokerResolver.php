<?php

namespace App\Services\Brokers;

use App\Services\AlpacaService;
use App\Services\Mt5Service;

class BrokerResolver
{
    public function __construct(
        private Mt5Service $mt5Service,
        private AlpacaService $alpacaService,
    ) {}

    /**
     * @param array<int, string> $profileTickerCategories
     * @param array<string, mixed> $botProfile
     */
    public function forProfile(array $profileTickerCategories, array $botProfile = []): MarketBrokerInterface
    {
        $broker = strtolower(trim((string) ($botProfile['broker'] ?? '')));
        if ($broker === 'alpaca') {
            return $this->alpacaService;
        }
        if ($broker === 'mt5') {
            return $this->mt5Service;
        }

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            $profileTickerCategories
        ), static fn ($value) => $value !== '')));

        if (count($normalized) === 1 && $normalized[0] === 'crypto') {
            return $this->alpacaService;
        }

        return $this->mt5Service;
    }

    public function forSymbol(?string $tickerCategory): MarketBrokerInterface
    {
        $raw = strtolower(trim((string) $tickerCategory));
        if ($raw !== '' && str_contains($raw, 'crypto')) {
            return $this->alpacaService;
        }

        return $this->mt5Service;
    }

    public function usesAlpaca(MarketBrokerInterface $broker): bool
    {
        return $broker instanceof AlpacaService;
    }
}
