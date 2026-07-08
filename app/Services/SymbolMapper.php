<?php

namespace App\Services;

use App\Models\Mt5EaTerminal;

class SymbolMapper
{
    public const SUFFIX_NONE = 'none';

    public const SUFFIX_SPREAD_BET = 'spread_bet';

    public const SUFFIX_AUTO = 'auto';

    public function __construct(
        private readonly Mt5Service $mt5Service,
    ) {}

    /**
     * Map a canonical symbol (EURUSD) to the broker-specific symbol for a terminal.
     */
    public function toBrokerSymbol(Mt5EaTerminal $terminal, string $symbol): string
    {
        $canonical = $this->normalizeCanonical($symbol);

        $map = is_array($terminal->symbol_map) ? $terminal->symbol_map : [];
        $mapped = $map[$canonical] ?? null;
        if (is_string($mapped) && trim($mapped) !== '') {
            return strtoupper(trim($mapped));
        }

        $suffixMode = $terminal->symbol_suffix ?: self::SUFFIX_AUTO;
        if (in_array($suffixMode, [self::SUFFIX_SPREAD_BET, self::SUFFIX_NONE], true)) {
            return $this->applySuffixPolicy($terminal, $canonical);
        }

        $fromQuotes = $this->matchFromQuoteKeys($terminal, $canonical);
        if ($fromQuotes !== null) {
            return $fromQuotes;
        }

        $fromPositions = $this->matchFromPositions($terminal, $canonical);
        if ($fromPositions !== null) {
            return $fromPositions;
        }

        return $this->applySuffixPolicy($terminal, $canonical);
    }

    /**
     * @return array<int, string>
     */
    public function brokerSymbolCandidates(Mt5EaTerminal $terminal, string $symbol): array
    {
        $canonical = $this->normalizeCanonical($symbol);
        $broker = $this->toBrokerSymbol($terminal, $canonical);
        $candidates = [$broker, $canonical];
        $suffixMode = $terminal->symbol_suffix ?: self::SUFFIX_AUTO;

        if ($suffixMode === self::SUFFIX_SPREAD_BET || ($suffixMode === self::SUFFIX_AUTO && $this->inferSpreadBetBroker($terminal))) {
            array_unshift($candidates, $canonical.'_SB');
        } elseif ($suffixMode === self::SUFFIX_AUTO) {
            // Auto/plain forex: never invent _SB for IC-style brokers.
            foreach (['.a', '.i', '.c', '.pro', '.z'] as $suffix) {
                $candidates[] = $canonical.$suffix;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    public function normalizeCanonical(string $symbol): string
    {
        $upper = strtoupper(str_replace('/', '', trim($symbol)));

        return $this->mt5Service->baseSymbol($upper);
    }

    /**
     * @param  array<string, string>  $lines  canonical => broker symbol
     * @return array<string, string>
     */
    public static function parseMapInput(string $input): array
    {
        $map = [];

        foreach (preg_split('/\R/', $input) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$left, $right] = array_map('trim', explode('=', $line, 2));
            } elseif (str_contains($line, ':')) {
                [$left, $right] = array_map('trim', explode(':', $line, 2));
            } elseif (preg_match('/^([A-Z0-9._-]+)\s+([A-Z0-9._-]+)$/i', $line, $matches)) {
                $left = $matches[1];
                $right = $matches[2];
            } else {
                continue;
            }

            $canonical = strtoupper(str_replace('/', '', $left));
            $broker = strtoupper(str_replace('/', '', $right));

            if ($canonical !== '' && $broker !== '') {
                $map[$canonical] = $broker;
            }
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    public static function suffixOptions(): array
    {
        return [
            self::SUFFIX_AUTO => 'Auto (detect from quotes / broker)',
            self::SUFFIX_NONE => 'Plain (e.g. GBPUSD — IC Markets)',
            self::SUFFIX_SPREAD_BET => 'Spread bet suffix (_SB — Pepperstone)',
        ];
    }

    private function applySuffixPolicy(Mt5EaTerminal $terminal, string $canonical): string
    {
        $suffixMode = $terminal->symbol_suffix ?: self::SUFFIX_AUTO;

        if ($suffixMode === self::SUFFIX_SPREAD_BET && preg_match('/^[A-Z]{6}$/', $canonical) === 1) {
            return $canonical.'_SB';
        }

        if ($suffixMode === self::SUFFIX_NONE) {
            return $canonical;
        }

        if ($suffixMode === self::SUFFIX_AUTO && $this->inferSpreadBetBroker($terminal) && preg_match('/^[A-Z]{6}$/', $canonical) === 1) {
            return $canonical.'_SB';
        }

        return $canonical;
    }

    private function inferSpreadBetBroker(Mt5EaTerminal $terminal): bool
    {
        $company = strtoupper((string) ($terminal->broker_company ?? ''));

        if (str_contains($company, 'PEPPERSTONE')) {
            return true;
        }

        // IC Markets demo often reports as "Raw Trading Ltd".
        if (
            str_contains($company, 'IC MARKETS')
            || str_contains($company, 'ICMARKETS')
            || str_contains($company, 'RAW TRADING')
        ) {
            return false;
        }

        $quotes = is_array($terminal->market_quotes) ? $terminal->market_quotes : [];
        $hasSpreadBet = false;
        $hasPlainForex = false;

        foreach (array_keys($quotes) as $key) {
            $brokerSymbol = strtoupper((string) $key);
            if (str_ends_with($brokerSymbol, '_SB')) {
                $hasSpreadBet = true;
                continue;
            }

            if (preg_match('/^[A-Z]{6}$/', $brokerSymbol) === 1) {
                $hasPlainForex = true;
            }
        }

        // Prefer plain symbols when both styles appear (stale _SB keys must not win).
        return $hasSpreadBet && ! $hasPlainForex;
    }

    private function matchFromQuoteKeys(Mt5EaTerminal $terminal, string $canonical): ?string
    {
        $quotes = is_array($terminal->market_quotes) ? $terminal->market_quotes : [];
        $matches = [];

        foreach (array_keys($quotes) as $key) {
            $brokerSymbol = strtoupper((string) $key);
            if ($this->mt5Service->baseSymbol($brokerSymbol) === $canonical) {
                $matches[] = $brokerSymbol;
            }
        }

        if ($matches === []) {
            return null;
        }

        return $this->preferBrokerSymbol($terminal, $canonical, $matches);
    }

    /**
     * @param  array<int, string>  $matches
     */
    private function preferBrokerSymbol(Mt5EaTerminal $terminal, string $canonical, array $matches): string
    {
        $matches = array_values(array_unique($matches));
        if (in_array($canonical, $matches, true)) {
            if (! $this->inferSpreadBetBroker($terminal) || ($terminal->symbol_suffix ?: self::SUFFIX_AUTO) === self::SUFFIX_NONE) {
                return $canonical;
            }
        }

        $preferred = $this->applySuffixPolicy($terminal, $canonical);
        if (in_array($preferred, $matches, true)) {
            return $preferred;
        }

        if ($this->inferSpreadBetBroker($terminal)) {
            foreach ($matches as $match) {
                if (str_ends_with($match, '_SB')) {
                    return $match;
                }
            }
        } else {
            foreach ($matches as $match) {
                if (! str_ends_with($match, '_SB')) {
                    return $match;
                }
            }
        }

        return $matches[0];
    }

    private function matchFromPositions(Mt5EaTerminal $terminal, string $canonical): ?string
    {
        $positions = is_array($terminal->positions) ? $terminal->positions : [];
        $matches = [];

        foreach ($positions as $position) {
            if (! is_array($position)) {
                continue;
            }

            $brokerSymbol = strtoupper((string) ($position['symbol'] ?? ''));
            if ($brokerSymbol === '') {
                continue;
            }

            if ($this->mt5Service->baseSymbol($brokerSymbol) === $canonical) {
                $matches[] = $brokerSymbol;
            }
        }

        if ($matches === []) {
            return null;
        }

        return $this->preferBrokerSymbol($terminal, $canonical, $matches);
    }
}
