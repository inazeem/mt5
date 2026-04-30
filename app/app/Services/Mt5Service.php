<?php

namespace App\Services;

use App\Models\AppSetting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

class Mt5Service
{
    private const BASE_URL = 'https://mt-client-api-v1.{region}.agiliumtrade.ai';
    private const COMMON_PEPPERSTONE_SUFFIXES = ['', '.a', '.m', '.r', '.pro', '.c', 'a', 'm', 'r', 'pro', 'c'];

    public function placeOrder(string $symbol, float $lotSize, string $side, array $exitLegs = []): array
    {
        [$settings, $metaApiToken, $metaApiAccountId, $client] = $this->metaApiContext();

        if (!$settings->demo_only) {
            throw new RuntimeException('Live trading is blocked. Keep demo_only enabled until you are ready.');
        }

        $this->assertMetaApiSettings($metaApiToken, $metaApiAccountId);

        $actionType = strtolower($side) === 'buy' ? 'ORDER_TYPE_BUY' : 'ORDER_TYPE_SELL';

        $accountId = $metaApiAccountId;
        $symbolCandidates = $this->buildTradeSymbolCandidates($client, $accountId, $symbol);
        $resolvedSymbol = $symbolCandidates[0] ?? strtoupper(str_replace('/', '', trim($symbol)));
        $normalizedVolume = $this->normalizeVolume($resolvedSymbol, $lotSize);
        $legs = $this->normalizeExitLegs($exitLegs);

        if (empty($legs)) {
            $response = null;
            $lastError = null;

            foreach ($symbolCandidates as $candidateSymbol) {
                $payload = [
                    'actionType' => $actionType,
                    'symbol' => $candidateSymbol,
                    'volume' => $this->normalizeVolume($candidateSymbol, $lotSize),
                ];

                try {
                    $response = $this->sendTradeRequest($client, $accountId, $payload);
                    $resolvedSymbol = $candidateSymbol;
                    break;
                } catch (RuntimeException $e) {
                    $lastError = $e;
                    if (!$this->isUnknownSymbolError($e)) {
                        throw $e;
                    }
                }
            }

            if ($response === null) {
                throw $lastError ?? new RuntimeException('MetaApi trade failed: no tradable symbol variant found.');
            }

            $payload = [
                'actionType' => $actionType,
                'symbol' => $resolvedSymbol,
                'volume' => $this->normalizeVolume($resolvedSymbol, $lotSize),
            ];

            return [
                'mode' => 'single-order',
                'payload' => $payload,
                'response' => $response,
            ];
        }

        $percentTotal = array_sum(array_column($legs, 'close_percent'));
        if (abs($percentTotal - 100.0) > 0.01) {
            throw new RuntimeException('Exit legs close percentages must total exactly 100%.');
        }

        $orderResults = [];
        $lastError = null;

        foreach ($symbolCandidates as $candidateSymbol) {
            try {
                $candidateTotalVolume = $this->normalizeVolume($candidateSymbol, $lotSize);
                $step = $this->volumeStep($candidateSymbol);
                $remainingVolume = $candidateTotalVolume;
                $candidateOrderResults = [];

                foreach ($legs as $index => $leg) {
                    if ($index === count($legs) - 1) {
                        $legVolume = $this->normalizeVolume($candidateSymbol, $remainingVolume);
                    } else {
                        $rawLegVolume = ($candidateTotalVolume * $leg['close_percent']) / 100;
                        $legVolume = $this->normalizeVolume($candidateSymbol, $rawLegVolume);
                        $remainingVolume = max(0.0, $remainingVolume - $legVolume);
                    }

                    if ($legVolume < $step) {
                        throw new RuntimeException('Computed leg volume is too small for broker minimum lot step.');
                    }

                    $payload = [
                        'actionType' => $actionType,
                        'symbol' => $candidateSymbol,
                        'volume' => $legVolume,
                    ];

                    if ($leg['take_profit'] !== null) {
                        $payload['takeProfit'] = $leg['take_profit'];
                    }

                    if ($leg['stop_loss'] !== null) {
                        $payload['stopLoss'] = $leg['stop_loss'];
                    }

                    $candidateOrderResults[] = [
                        'leg' => $leg,
                        'payload' => $payload,
                        'response' => $this->sendTradeRequest($client, $accountId, $payload),
                    ];
                }

                $resolvedSymbol = $candidateSymbol;
                $normalizedVolume = $candidateTotalVolume;
                $orderResults = $candidateOrderResults;
                break;
            } catch (RuntimeException $e) {
                $lastError = $e;
                if (!$this->isUnknownSymbolError($e)) {
                    throw $e;
                }
            }
        }

        if (empty($orderResults)) {
            if ($lastError !== null && $this->isUnknownSymbolError($lastError)) {
                $attempted = implode(', ', $symbolCandidates);
                throw new RuntimeException($lastError->getMessage().' Tried symbols: '.$attempted);
            }

            throw $lastError ?? new RuntimeException('MetaApi trade failed: no tradable symbol variant found.');
        }

        return [
            'mode' => 'multi-leg',
            'symbol' => $resolvedSymbol,
            'total_volume' => $normalizedVolume,
            'orders' => $orderResults,
        ];
    }

    public function closePosition(string $positionId): array
    {
        [, , $metaApiAccountId, $client] = $this->metaApiContext();

        $positionId = trim($positionId);

        if ($positionId === '') {
            throw new RuntimeException('Position ID is required to close a trade.');
        }

        $payload = [
            'actionType' => 'POSITION_CLOSE_ID',
            'positionId' => $positionId,
        ];

        try {
            $response = $client->post(
                "/users/current/accounts/{$metaApiAccountId}/trade",
                ['json' => $payload]
            );

            $decoded = json_decode((string) $response->getBody(), true);

            return [
                'payload' => $payload,
                'response' => is_array($decoded) ? $decoded : ['raw' => (string) $response->getBody()],
            ];
        } catch (ClientException $e) {
            $body = (string) $e->getResponse()->getBody();
            $decoded = json_decode($body, true);
            $detail = is_array($decoded) ? ($decoded['message'] ?? $body) : $body;
            throw new RuntimeException("MetaApi close position failed [{$e->getResponse()->getStatusCode()}]: {$detail}");
        }
    }

    public function getOpenTradeSnapshot(): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        return [
            'positions' => $this->safeMetaApiGet($client, "/users/current/accounts/{$accountId}/positions"),
            'orders' => $this->safeMetaApiGet($client, "/users/current/accounts/{$accountId}/orders"),
            'fetched_at' => now()->toDateTimeString(),
        ];
    }

    public function getAccountInformation(): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        $response = $client->get("/users/current/accounts/{$accountId}/account-information");
        $decoded = json_decode((string) $response->getBody(), true);

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public function getTickerPrice(string $symbol): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        $requested = strtoupper(str_replace('/', '', trim($symbol)));
        if ($requested === '') {
            throw new RuntimeException('Symbol is required to fetch current ticker price.');
        }

        $candidateSymbols = [];

        try {
            $response = $client->get("/users/current/accounts/{$accountId}/symbols");
            $decoded = json_decode((string) $response->getBody(), true);
            $availableSymbols = $this->extractSymbolNames($decoded);

            if (!empty($availableSymbols)) {
                $availableMap = [];
                foreach ($availableSymbols as $availableSymbol) {
                    $availableMap[strtoupper($availableSymbol)] = $availableSymbol;
                }

                foreach (self::COMMON_PEPPERSTONE_SUFFIXES as $suffix) {
                    $candidate = $requested.$suffix;
                    if (isset($availableMap[$candidate])) {
                        $candidateSymbols[] = $availableMap[$candidate];
                    }
                }

                foreach ($availableMap as $upper => $original) {
                    if (str_starts_with($upper, $requested)) {
                        $candidateSymbols[] = $original;
                    }
                }
            }
        } catch (\Throwable) {
            // If symbols discovery fails, continue with fallback candidates below.
        }

        foreach (self::COMMON_PEPPERSTONE_SUFFIXES as $suffix) {
            $candidateSymbols[] = $requested.$suffix;
        }
        $candidateSymbols[] = $requested;
        $candidateSymbols = array_values(array_unique($candidateSymbols));

        $quote = null;
        $resolvedSymbol = null;
        $lastError = null;

        foreach ($candidateSymbols as $candidateSymbol) {
            $encodedSymbol = rawurlencode($candidateSymbol);
            $path = "/users/current/accounts/{$accountId}/symbols/{$encodedSymbol}/current-price";

            try {
                $response = $client->get($path, [
                    'query' => ['keepSubscription' => 'true'],
                ]);
                $decoded = json_decode((string) $response->getBody(), true);
                if (is_array($decoded)) {
                    $quote = $decoded;
                    $resolvedSymbol = $candidateSymbol;
                    break;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        if ($quote === null) {
            throw new RuntimeException('Unable to fetch current ticker price for symbol '.$requested.'.'.($lastError ? " {$lastError}" : ''));
        }

        return [
            'symbol' => $resolvedSymbol ?? $requested,
            'bid' => isset($quote['bid']) ? (float) $quote['bid'] : null,
            'ask' => isset($quote['ask']) ? (float) $quote['ask'] : null,
            'last' => isset($quote['last']) ? (float) $quote['last'] : null,
            'time' => $quote['time'] ?? $quote['brokerTime'] ?? now()->toDateTimeString(),
            'raw' => $quote,
        ];
    }

    public function getHistoryDeals(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        $startTime = rawurlencode($from->format(\DateTimeInterface::ATOM));
        $endTime   = rawurlencode($to->format(\DateTimeInterface::ATOM));

        $response = $client->get(
            "/users/current/accounts/{$accountId}/history-deals/time/{$startTime}/{$endTime}"
        );

        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function getCandles(string $symbol, string $timeframe = '1h', int $limit = 20): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        $symbol = trim($symbol);
        if ($symbol === '') {
            throw new RuntimeException('Symbol is required to fetch candles.');
        }

        $allowedTimeframes = ['1m', '5m', '15m', '30m', '1h', '4h', '1d', '1w', '1mn'];
        if (!in_array($timeframe, $allowedTimeframes, true)) {
            throw new RuntimeException("Invalid timeframe '{$timeframe}'. Allowed: ".implode(', ', $allowedTimeframes));
        }

        $limit = max(1, min(1000, $limit));
        $encodedSymbol = rawurlencode($symbol);
        $encodedTimeframe = rawurlencode($timeframe);

        $response = $client->get(
            "/users/current/accounts/{$accountId}/symbols/{$encodedSymbol}/candles/{$encodedTimeframe}",
            ['query' => ['limit' => $limit]]
        );

        $decoded = json_decode((string) $response->getBody(), true);

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public function getTopForexSymbols(): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        $majors = ['EURUSD', 'GBPUSD', 'USDJPY', 'USDCHF', 'USDCAD', 'AUDUSD', 'NZDUSD', 'EURJPY'];
        $fallback = $majors;

        try {
            $response = $client->get("/users/current/accounts/{$accountId}/symbols");
            $decoded = json_decode((string) $response->getBody(), true);
            $availableSymbols = $this->extractSymbolNames($decoded);

            if (empty($availableSymbols)) {
                return $fallback;
            }

            $suggestions = [];
            foreach ($majors as $major) {
                $resolved = $this->pickBestSymbolForPair($major, $availableSymbols);
                $suggestions[] = $resolved ?? $major;
            }

            return array_values(array_unique($suggestions));
        } catch (\Throwable) {
            return $fallback;
        }
    }

    public function getForexSymbols(): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        $response = $client->get("/users/current/accounts/{$accountId}/symbols");
        $decoded = json_decode((string) $response->getBody(), true);
        $availableSymbols = $this->extractSymbolNames($decoded);

        $forexSymbols = [];
        foreach ($availableSymbols as $symbol) {
            if (!is_string($symbol)) {
                continue;
            }

            $upper = strtoupper($symbol);
            $base = substr($upper, 0, 6);

            if (strlen($base) !== 6 || !preg_match('/^[A-Z]{6}$/', $base)) {
                continue;
            }

            // Basic heuristic to keep only FX-style pairs and skip metals/indices.
            $baseCurrency = substr($base, 0, 3);
            $quoteCurrency = substr($base, 3, 3);
            $knownFx = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'NZD', 'CAD'];

            if (!in_array($baseCurrency, $knownFx, true) || !in_array($quoteCurrency, $knownFx, true)) {
                continue;
            }

            $forexSymbols[] = $symbol;
        }

        return array_values(array_unique($forexSymbols));
    }

    public function applyTrailingStops(float $startPips, float $trailPips): array
    {
        if ($startPips <= 0 || $trailPips <= 0) {
            throw new RuntimeException('Trailing stop pip values must be greater than zero.');
        }

        $snapshot = $this->getOpenTradeSnapshot();
        $positions = is_array($snapshot['positions'] ?? null) ? $snapshot['positions'] : [];
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($positions as $position) {
            if (!is_array($position)) {
                continue;
            }

            $positionId = (string) ($position['id'] ?? $position['positionId'] ?? '');
            $symbol = (string) ($position['symbol'] ?? '');
            $type = strtoupper((string) ($position['type'] ?? ''));
            $openPrice = (float) ($position['openPrice'] ?? $position['priceOpen'] ?? 0);
            $currentPrice = (float) ($position['currentPrice'] ?? $position['priceCurrent'] ?? 0);
            $currentSl = isset($position['stopLoss']) ? (float) $position['stopLoss'] : null;
            $currentTp = isset($position['takeProfit']) ? (float) $position['takeProfit'] : null;

            if ($positionId === '' || $symbol === '' || $openPrice <= 0 || $currentPrice <= 0) {
                $skipped++;
                continue;
            }

            $pipSize = $this->pipSize($symbol);
            $trailDistance = $trailPips * $pipSize;
            $startDistance = $startPips * $pipSize;

            $isBuy = str_contains($type, 'BUY');
            $isSell = str_contains($type, 'SELL');
            if (!$isBuy && !$isSell) {
                $skipped++;
                continue;
            }

            if ($isBuy) {
                $profitDistance = $currentPrice - $openPrice;
                if ($profitDistance < $startDistance) {
                    $skipped++;
                    continue;
                }

                $newSl = round($currentPrice - $trailDistance, 5);
                if ($currentSl !== null && $newSl <= $currentSl) {
                    $skipped++;
                    continue;
                }
            } else {
                $profitDistance = $openPrice - $currentPrice;
                if ($profitDistance < $startDistance) {
                    $skipped++;
                    continue;
                }

                $newSl = round($currentPrice + $trailDistance, 5);
                if ($currentSl !== null && $newSl >= $currentSl) {
                    $skipped++;
                    continue;
                }
            }

            try {
                $this->modifyPositionStops($positionId, $newSl, $currentTp);
                $updated++;
            } catch (RuntimeException $e) {
                $errors[] = [
                    'position_id' => $positionId,
                    'symbol' => $symbol,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    public function modifyPositionStops(string $positionId, ?float $stopLoss, ?float $takeProfit = null): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        $positionId = trim($positionId);
        if ($positionId === '') {
            throw new RuntimeException('Position ID is required to modify stops.');
        }

        if ($stopLoss === null && $takeProfit === null) {
            throw new RuntimeException('Provide stopLoss or takeProfit to modify a position.');
        }

        $payload = [
            'actionType' => 'POSITION_MODIFY',
            'positionId' => $positionId,
        ];

        if ($stopLoss !== null) {
            $payload['stopLoss'] = $stopLoss;
        }

        if ($takeProfit !== null) {
            $payload['takeProfit'] = $takeProfit;
        }

        $response = $this->sendTradeRequest($client, $accountId, $payload);

        return [
            'payload' => $payload,
            'response' => $response,
        ];
    }

    private function resolveBrokerSymbol(Client $client, string $accountId, string $symbol): string
    {
        $requested = strtoupper(str_replace('/', '', trim($symbol)));

        if ($requested === '') {
            throw new RuntimeException('Symbol cannot be empty.');
        }

        $candidates = [];
        foreach (self::COMMON_PEPPERSTONE_SUFFIXES as $suffix) {
            $candidates[] = $requested.$suffix;
        }

        try {
            $response = $client->get("/users/current/accounts/{$accountId}/symbols");
            $decoded = json_decode((string) $response->getBody(), true);
            $availableSymbols = $this->extractSymbolNames($decoded);

            if (empty($availableSymbols)) {
                return $requested;
            }

            $availableMap = [];
            foreach ($availableSymbols as $availableSymbol) {
                $availableMap[strtoupper($availableSymbol)] = $availableSymbol;
            }

            if (isset($availableMap[$requested])) {
                return $availableMap[$requested];
            }

            foreach ($candidates as $candidate) {
                if (isset($availableMap[$candidate])) {
                    return $availableMap[$candidate];
                }
            }

            $prefixMatches = [];
            $prefixSpreadBetMatches = [];
            foreach ($availableMap as $upper => $original) {
                if (str_starts_with($upper, $requested)) {
                    if ($this->isSpreadBetSymbol($upper)) {
                        $prefixSpreadBetMatches[] = $original;
                    } else {
                        $prefixMatches[] = $original;
                    }
                }
            }

            if (!empty($prefixMatches)) {
                usort($prefixMatches, function (string $a, string $b): int {
                    $aUpper = strtoupper($a);
                    $bUpper = strtoupper($b);

                    // Prefer common FX symbol variants first (dot suffix before underscore variants).
                    $aScore = (int) str_contains($aUpper, '.') * 2 + (int) !str_contains($aUpper, '_');
                    $bScore = (int) str_contains($bUpper, '.') * 2 + (int) !str_contains($bUpper, '_');

                    if ($aScore !== $bScore) {
                        return $bScore <=> $aScore;
                    }

                    return strlen($aUpper) <=> strlen($bUpper);
                });

                return $prefixMatches[0];
            }

            if (!empty($prefixSpreadBetMatches)) {
                usort($prefixSpreadBetMatches, fn (string $a, string $b) => strlen($a) <=> strlen($b));

                return $prefixSpreadBetMatches[0];
            }

            $containsMatches = [];
            $containsSpreadBetMatches = [];
            foreach ($availableMap as $upper => $original) {
                if (str_contains($upper, $requested)) {
                    if ($this->isSpreadBetSymbol($upper)) {
                        $containsSpreadBetMatches[] = $original;
                    } else {
                        $containsMatches[] = $original;
                    }
                }
            }

            if (!empty($containsMatches)) {
                usort($containsMatches, fn (string $a, string $b) => strlen($a) <=> strlen($b));

                return $containsMatches[0];
            }

            if (!empty($containsSpreadBetMatches)) {
                usort($containsSpreadBetMatches, fn (string $a, string $b) => strlen($a) <=> strlen($b));

                return $containsSpreadBetMatches[0];
            }
        } catch (\Throwable) {
            // If symbol discovery fails, continue with requested symbol and let trade API decide.
        }

        return $requested;
    }

    private function extractSymbolNames(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            return [];
        }

        $symbols = [];

        foreach ($decoded as $item) {
            if (is_string($item)) {
                $symbols[] = $item;
                continue;
            }

            if (is_array($item)) {
                if (!empty($item['symbol']) && is_string($item['symbol'])) {
                    $symbols[] = $item['symbol'];
                    continue;
                }

                if (!empty($item['name']) && is_string($item['name'])) {
                    $symbols[] = $item['name'];
                }
            }
        }

        return array_values(array_unique($symbols));
    }

    private function normalizeVolume(string $symbol, float $requestedVolume): float
    {
        if ($requestedVolume <= 0) {
            throw new RuntimeException('Lot size must be greater than zero.');
        }

        $upperSymbol = strtoupper($symbol);

        // Pepperstone spread-bet symbols (e.g. GBPUSD_SB) typically require integer stake sizes.
        if (str_ends_with($upperSymbol, '_SB')) {
            return (float) max(1, (int) round($requestedVolume));
        }

        // Default FX lot granularity.
        return max(0.01, round($requestedVolume, 2));
    }

    private function pickBestSymbolForPair(string $pair, array $availableSymbols): ?string
    {
        $pair = strtoupper($pair);
        $availableMap = [];
        foreach ($availableSymbols as $symbol) {
            if (is_string($symbol) && $symbol !== '') {
                $availableMap[strtoupper($symbol)] = $symbol;
            }
        }

        foreach (self::COMMON_PEPPERSTONE_SUFFIXES as $suffix) {
            $candidate = $pair.$suffix;
            if (isset($availableMap[$candidate]) && !$this->isSpreadBetSymbol($candidate)) {
                return $availableMap[$candidate];
            }
        }

        foreach (self::COMMON_PEPPERSTONE_SUFFIXES as $suffix) {
            $candidate = $pair.$suffix;
            if (isset($availableMap[$candidate])) {
                return $availableMap[$candidate];
            }
        }

        $prefixMatches = [];
        $prefixSpreadBetMatches = [];
        foreach ($availableMap as $upper => $original) {
            if (str_starts_with($upper, $pair)) {
                if ($this->isSpreadBetSymbol($upper)) {
                    $prefixSpreadBetMatches[] = $original;
                } else {
                    $prefixMatches[] = $original;
                }
            }
        }

        if (!empty($prefixMatches)) {
            usort($prefixMatches, fn (string $a, string $b) => strlen($a) <=> strlen($b));
            return $prefixMatches[0];
        }

        if (!empty($prefixSpreadBetMatches)) {
            usort($prefixSpreadBetMatches, fn (string $a, string $b) => strlen($a) <=> strlen($b));
            return $prefixSpreadBetMatches[0];
        }

        return null;
    }

    private function buildTradeSymbolCandidates(Client $client, string $accountId, string $symbol): array
    {
        $requested = strtoupper(str_replace('/', '', trim($symbol)));
        if ($requested === '') {
            throw new RuntimeException('Symbol cannot be empty.');
        }

        $candidates = [];

        // Always try exactly what user typed first.
        $candidates[] = $requested;

        $resolved = $this->resolveBrokerSymbol($client, $accountId, $requested);
        if ($resolved !== '') {
            $candidates[] = $resolved;
        }

        $baseRequested = str_ends_with($requested, '_SB') ? substr($requested, 0, -3) : $requested;

        foreach (self::COMMON_PEPPERSTONE_SUFFIXES as $suffix) {
            $candidates[] = $baseRequested.$suffix;
        }

        $candidates[] = $baseRequested.'_SB';

        $candidates[] = $requested;

        $normalized = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $upper = strtoupper($candidate);
            $normalized[$upper] = $candidate;
        }

        return array_values($normalized);
    }

    private function isUnknownSymbolError(RuntimeException $e): bool
    {
        $message = strtoupper($e->getMessage());

        return str_contains($message, 'ERR_MARKET_UNKNOWN_SYMBOL')
            || str_contains($message, 'UNKNOWN SYMBOL');
    }

    private function volumeStep(string $symbol): float
    {
        return $this->isSpreadBetSymbol($symbol) ? 1.0 : 0.01;
    }

    private function pipSize(string $symbol): float
    {
        $upper = strtoupper($symbol);
        $base = substr($upper, 0, 6);

        if (strlen($base) === 6 && str_ends_with($base, 'JPY')) {
            return 0.01;
        }

        return 0.0001;
    }

    private function isSpreadBetSymbol(string $symbol): bool
    {
        return str_ends_with(strtoupper($symbol), '_SB');
    }

    private function normalizeExitLegs(array $exitLegs): array
    {
        $normalized = [];

        foreach ($exitLegs as $leg) {
            if (!is_array($leg)) {
                continue;
            }

            $closePercent = isset($leg['close_percent']) ? (float) $leg['close_percent'] : 0.0;
            $takeProfit = isset($leg['take_profit']) ? $leg['take_profit'] : null;
            $stopLoss = isset($leg['stop_loss']) ? $leg['stop_loss'] : null;

            if ($closePercent <= 0) {
                continue;
            }

            $normalized[] = [
                'close_percent' => $closePercent,
                'take_profit' => $takeProfit !== null ? (float) $takeProfit : null,
                'stop_loss' => $stopLoss !== null ? (float) $stopLoss : null,
            ];
        }

        return $normalized;
    }

    private function sendTradeRequest(Client $client, string $accountId, array $payload): array
    {
        try {
            $response = $client->post(
                "/users/current/accounts/{$accountId}/trade",
                ['json' => $payload]
            );

            $decoded = json_decode((string) $response->getBody(), true);
            $result = is_array($decoded) ? $decoded : ['raw' => (string) $response->getBody()];

            // MetaApi can return logical trade errors in a 2xx response payload.
            if (is_array($result)) {
                $stringCode = (string) ($result['stringCode'] ?? '');
                if ($stringCode !== '' && str_starts_with($stringCode, 'ERR_')) {
                    $message = (string) ($result['message'] ?? 'Trade request failed.');
                    throw new RuntimeException("MetaApi trade failed [{$stringCode}]: {$message}");
                }
            }

            return $result;
        } catch (RequestException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'cURL error 28')) {
                throw new RuntimeException(
                    'MetaApi trade request timed out. Trade status is unknown. Check open positions/orders before retrying to avoid duplicate orders.'
                );
            }

            throw new RuntimeException('MetaApi trade request failed: '.$message);
        } catch (ClientException $e) {
            $body = (string) $e->getResponse()->getBody();
            $decoded = json_decode($body, true);
            $detail = is_array($decoded) ? ($decoded['message'] ?? $body) : $body;
            if ($e->getResponse()->getStatusCode() === 401) {
                $detail .= ' Verify MetaApi token is active, copied fully, and has no extra spaces/newlines.';
            }
            if ($e->getResponse()->getStatusCode() === 400 && str_contains($detail, 'ERR_MARKET_UNKNOWN_SYMBOL')) {
                $detail .= ' The broker symbol may require a suffix (example: GBPUSD.a).';
            }
            throw new RuntimeException("MetaApi trade failed [{$e->getResponse()->getStatusCode()}]: {$detail}");
        }
    }

    private function metaApiContext(): array
    {
        $settings = AppSetting::singleton();
        $metaApiToken = trim((string) $settings->metaapi_token);
        $metaApiAccountId = trim((string) $settings->metaapi_account_id);
        $metaApiRegion = trim((string) ($settings->metaapi_region ?? 'new-york'));

        if (!$settings->demo_only) {
            throw new RuntimeException('Live trading is blocked. Keep demo_only enabled until you are ready.');
        }

        $this->assertMetaApiSettings($metaApiToken, $metaApiAccountId);

        $baseUrl = str_replace('{region}', $metaApiRegion, self::BASE_URL);

        $client = new Client([
            'base_uri' => $baseUrl,
            'timeout'  => 45,
            'connect_timeout' => 10,
            'headers'  => [
                'auth-token'   => $metaApiToken,
                'Content-Type' => 'application/json',
            ],
        ]);

        return [$settings, $metaApiToken, $metaApiAccountId, $client];
    }

    private function safeMetaApiGet(Client $client, string $path): array
    {
        try {
            $response = $client->get($path);
            $decoded = json_decode((string) $response->getBody(), true);

            if (is_array($decoded)) {
                return $decoded;
            }

            return [];
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
            ];
        }
    }

    private function assertMetaApiSettings(string $metaApiToken, string $metaApiAccountId): void
    {
        $required = [
            'metaapi_token'      => $metaApiToken,
            'metaapi_account_id' => $metaApiAccountId,
        ];

        foreach ($required as $key => $value) {
            if (empty($value)) {
                throw new RuntimeException("Missing setting: {$key}. Fill it in Settings first.");
            }
        }
    }
}
