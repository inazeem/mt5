<?php

namespace App\Services\Brokers;

use App\Services\AlpacaService;
use App\Services\Brokers\EaBridgeBroker;
use App\Services\Mt5Service;

class BrokerResolver
{
    public function __construct(
        private Mt5Service $mt5Service,
        private AlpacaService $alpacaService,
        private EaBridgeBroker $eaBridgeBroker,
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

        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            $profileTickerCategories
        ), static fn ($value) => $value !== '')));

        if (count($normalized) === 1 && $normalized[0] === 'crypto') {
            return $this->alpacaService;
        }

        $instanceKey = trim((string) ($botProfile['mt5_instance_key'] ?? ''));

        return $this->eaBridgeBroker->forInstance($instanceKey !== '' ? $instanceKey : null);
    }

    public function forSymbol(?string $tickerCategory): MarketBrokerInterface
    {
        $raw = strtolower(trim((string) $tickerCategory));
        if ($raw !== '' && str_contains($raw, 'crypto')) {
            return $this->alpacaService;
        }

        return $this->eaBridgeBroker->forInstance(null);
    }

    public function usesAlpaca(MarketBrokerInterface $broker): bool
    {
        return $broker instanceof AlpacaService;
    }

    public function usesEaBridge(MarketBrokerInterface $broker): bool
    {
        return $broker instanceof EaBridgeBroker;
    }

    /**
     * Legacy MetaAPI reads for admin pages that still opt into cloud data.
     */
    public function metaApi(): Mt5Service
    {
        return $this->mt5Service;
    }
}
