<?php

namespace App\Services;

use App\Models\Ticker;
use App\Models\AppSetting;
use App\Services\Brokers\MarketBrokerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Service wrapper for MetaApi/MT5 operations used by the bot UI and automation flows.
 *
 * Responsibilities:
 * - Send trading actions (open, close, modify).
 * - Resolve broker symbol variants and normalize volumes.
 * - Fetch account, market, and history data.
 * - Apply protective trailing-stop updates.
 */
class Mt5Service implements MarketBrokerInterface
{
    private const KNOWN_FX_CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'NZD', 'CAD'];
    private const BASE_URL = 'https://mt-client-api-v1.{region}.agiliumtrade.ai';
    private const MARKET_DATA_BASE_URL = 'https://mt-market-data-client-api-v1.{region}.agiliumtrade.ai';
    private const METAAPI_TIMEOUT_SECONDS = 12;
    private const METAAPI_CONNECT_TIMEOUT_SECONDS = 5;
    private const COMMON_SPREAD_BET_SUFFIXES = ['_SB'];
    private const COMMON_PEPPERSTONE_SUFFIXES = ['_SB'];
    private const HISTORY_DEALS_CACHE_SECONDS = 60;
    private const HISTORY_DEALS_STALE_CACHE_MINUTES = 15;
    private const HISTORY_DEALS_MAX_RETRIES = 3;
    private const HISTORY_DEALS_BACKOFF_BASE_MS = 400;
    private const QUOTE_MAX_RETRIES = 2;
    private const QUOTE_BACKOFF_BASE_MS = 300;
    private const ACCOUNT_INFO_CACHE_SECONDS = 20;
    private const ACCOUNT_INFO_MAX_RETRIES = 2;
    private const ACCOUNT_INFO_BACKOFF_BASE_MS = 400;
    /**
     * @var array<string, array{pip_size: float|null, category: string|null}>
     */
    private array $tickerMetaCache = [];

    /**
     * Place a new market order, optionally split into multiple exit legs.
     *
     * @param string $symbol Base symbol (for example GBPUSD).
     * @param float $lotSize Requested lot size before multiplier adjustments.
     * @param string $side Trade direction: buy or sell.
     * @param array<int, array<string, mixed>> $exitLegs Optional staged exit legs.
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public function placeOrder(string $symbol, float $lotSize, string $side, array $exitLegs = []): array
    {
        [$settings, $metaApiToken, $metaApiAccountId, $client] = $this->metaApiContext();

        if (!$settings->demo_only) {
            throw new RuntimeException('Live trading is blocked. Keep demo_only enabled until you are ready.');
        }

        $this->assertMetaApiSettings($metaApiToken, $metaApiAccountId);

        $actionType = strtolower($side) === 'buy' ? 'ORDER_TYPE_BUY' : 'ORDER_TYPE_SELL';
        $scaledLotSize = $this->applyConfiguredVolumeMultiplier($settings, $lotSize);

        $accountId = $metaApiAccountId;
        $symbolCandidates = $this->buildTradeSymbolCandidates($client, $accountId, $symbol);
        $resolvedSymbol = $symbolCandidates[0] ?? strtoupper(str_replace('/', '', trim($symbol)));
        $normalizedVolume = $this->normalizeVolume($resolvedSymbol, $scaledLotSize);
        $legs = $this->normalizeExitLegs($exitLegs);

        if (empty($legs)) {
            $response = null;
            $lastError = null;

            foreach ($symbolCandidates as $candidateSymbol) {
                $payload = [
                    'actionType' => $actionType,
                    'symbol' => $candidateSymbol,
                    'volume' => $this->normalizeVolume($candidateSymbol, $scaledLotSize),
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
                'volume' => $this->normalizeVolume($resolvedSymbol, $scaledLotSize),
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
                $candidateTotalVolume = $this->normalizeVolume($candidateSymbol, $scaledLotSize);
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

    /**
     * Close an existing open position by id.
     *
     * @param string $positionId Broker position identifier.
     * @return array<string, mixed>
     * @throws RuntimeException
     */
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

    /**
     * Retrieve currently open positions and pending orders.
     *
     * @return array<string, mixed>
     */
    public function getOpenTradeSnapshot(): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        return [
            'positions' => $this->safeMetaApiGet($client, "/users/current/accounts/{$accountId}/positions"),
            'orders' => $this->safeMetaApiGet($client, "/users/current/accounts/{$accountId}/orders"),
            'fetched_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Fetch account-level information from MetaApi.
     *
     * @return array<string, mixed>
     */
    public function getAccountInformation(): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        $cacheKey = 'mt5_account_information_'.md5($accountId);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $decoded = $this->fetchAccountInformationWithRetry($client, $accountId);
        if ($decoded !== []) {
            Cache::put($cacheKey, $decoded, now()->addSeconds(self::ACCOUNT_INFO_CACHE_SECONDS));
        }

        return $decoded;
    }

    /**
     * Resolve and fetch current bid/ask/last quote for a symbol.
     *
     * @param string $symbol Requested symbol.
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public function getTickerPrice(string $symbol): array
    {
        [$settings, $metaApiToken, $accountId, $client] = $this->metaApiContext();

        $requested = strtoupper(str_replace('/', '', trim($symbol)));
        if ($requested === '') {
            throw new RuntimeException('Symbol is required to fetch current ticker price.');
        }

        $marketDataClient = $this->marketDataClient($metaApiToken, (string) ($settings->metaapi_region ?? 'new-york'));
        $candidateSymbols = $this->buildQuoteSymbolCandidates($client, $accountId, $requested);
        $quoteSources = [
            ['client' => $marketDataClient, 'query' => []],
            ['client' => $client, 'query' => ['keepSubscription' => 'true']],
        ];
        $attemptedSymbols = [];

        $quote = null;
        $resolvedSymbol = null;
        $lastError = null;

        foreach ($candidateSymbols as $candidateSymbol) {
            $attemptedSymbols[] = $candidateSymbol;

            foreach ($quoteSources as $source) {
                try {
                    $quote = $this->fetchCurrentPricePayload(
                        $source['client'],
                        $accountId,
                        $candidateSymbol,
                        $source['query']
                    );
                    $resolvedSymbol = $candidateSymbol;
                    break 2;
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                }
            }

            if (!$this->shouldWarmQuoteSubscription($lastError)
                || !$this->subscribeQuoteSymbol($client, $accountId, $candidateSymbol)) {
                continue;
            }

            usleep(500000);

            foreach ($quoteSources as $source) {
                try {
                    $quote = $this->fetchCurrentPricePayload(
                        $source['client'],
                        $accountId,
                        $candidateSymbol,
                        $source['query']
                    );
                    $resolvedSymbol = $candidateSymbol;
                    break 2;
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                }
            }
        }

        if ($quote === null) {
            $attempted = !empty($attemptedSymbols)
                ? ' Attempted symbols: '.implode(', ', array_values(array_unique($attemptedSymbols))).'.'
                : '';
            throw new RuntimeException('Unable to fetch current ticker price for symbol '.$requested.'.'.$attempted.($lastError ? " {$lastError}" : ''));
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

    /**
     * Fetch current quote payload for a broker symbol.
     *
     * @param Client $client Configured MetaApi HTTP client.
     * @param string $accountId MetaApi account id.
     * @param string $symbol Broker symbol.
     * @param array<string, string> $query Optional query parameters.
     * @return array<string, mixed>
     */
    private function fetchCurrentPricePayload(Client $client, string $accountId, string $symbol, array $query = []): array
    {
        $encodedSymbol = rawurlencode($symbol);
        $path = "/users/current/accounts/{$accountId}/symbols/{$encodedSymbol}/current-price";
        $options = $query === [] ? [] : ['query' => $query];

        for ($attempt = 0; $attempt <= self::QUOTE_MAX_RETRIES; $attempt++) {
            try {
                $response = $client->get($path, $options);
                $decoded = json_decode((string) $response->getBody(), true);

                return is_array($decoded) ? $decoded : [];
            } catch (ClientException $e) {
                $status = $e->getResponse()->getStatusCode();

                if ($status === 429 && $attempt < self::QUOTE_MAX_RETRIES) {
                    $delayMs = $this->quoteBackoffDelayMs($e, $attempt);
                    usleep($delayMs * 1000);
                    continue;
                }

                throw $e;
            } catch (RequestException $e) {
                if ($attempt < self::QUOTE_MAX_RETRIES) {
                    $delayMs = self::QUOTE_BACKOFF_BASE_MS * (2 ** $attempt);
                    usleep($delayMs * 1000);
                    continue;
                }

                throw $e;
            }
        }

        throw new RuntimeException('MetaApi current-price request failed after retries due to too many requests.');
    }

    /**
     * Determine retry delay for quote 429 responses.
     */
    private function quoteBackoffDelayMs(ClientException $e, int $attempt): int
    {
        $retryAfter = trim($e->getResponse()->getHeaderLine('Retry-After'));

        if (is_numeric($retryAfter)) {
            return (int) $retryAfter * 1000;
        }

        return self::QUOTE_BACKOFF_BASE_MS * (2 ** $attempt);
    }

    /**
     * Warm a symbol subscription so current-price can backfill on brokers that
     * require current-candles subscription first.
     *
     * @param Client $client Configured MetaApi HTTP client.
     * @param string $accountId MetaApi account id.
     * @param string $symbol Broker symbol.
     * @return bool
     */
    private function subscribeQuoteSymbol(Client $client, string $accountId, string $symbol): bool
    {
        $encodedSymbol = rawurlencode($symbol);

        try {
            $response = $client->get(
                "/users/current/accounts/{$accountId}/symbols/{$encodedSymbol}/current-candles/1m",
                ['query' => ['keepSubscription' => 'true']]
            );
            $decoded = json_decode((string) $response->getBody(), true);

            return is_array($decoded);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Detect symbol-price misses that can be recovered by warming a subscription.
     */
    private function shouldWarmQuoteSubscription(?string $message): bool
    {
        if (!is_string($message) || trim($message) === '') {
            return false;
        }

        $upper = strtoupper($message);

        return str_contains($upper, 'SPECIFIED SYMBOL PRICE NOT FOUND')
            || str_contains($upper, 'NOTFOUNDERROR')
            || str_contains($upper, '404 NOT FOUND');
    }

    /**
     * Fetch historical deals within a time range.
     *
     * Uses short-lived cache to reduce API pressure and a stale fallback during
     * temporary rate limiting.
     *
     * @param \DateTimeInterface $from Inclusive start time.
     * @param \DateTimeInterface $to Inclusive end time.
     * @return array<int, array<string, mixed>>
     * @throws RuntimeException
     */
    public function getHistoryDeals(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        [, , $accountId, $client] = $this->metaApiContext();

        $windowKey = sprintf(
            'mt5_history_deals:%s:%s:%s',
            $accountId,
            $from->format('YmdHi'),
            $to->format('YmdHi')
        );
        $staleKey = $windowKey.':stale';

        if (Cache::has($windowKey)) {
            return Cache::get($windowKey, []);
        }

        try {
            $deals = $this->fetchHistoryDealsWithRetry($client, $accountId, $from, $to);
            Cache::put($windowKey, $deals, now()->addSeconds(self::HISTORY_DEALS_CACHE_SECONDS));
            Cache::put($staleKey, $deals, now()->addMinutes(self::HISTORY_DEALS_STALE_CACHE_MINUTES));

            return $deals;
        } catch (RuntimeException $e) {
            if (str_contains(strtoupper($e->getMessage()), 'TOO MANY REQUESTS') && Cache::has($staleKey)) {
                return Cache::get($staleKey, []);
            }

            throw $e;
        }
    }

    /**
     * Fetch OHLC candles for a symbol/timeframe.
     *
     * @param string $symbol Broker symbol.
     * @param string $timeframe MetaApi timeframe string.
     * @param int $limit Number of candles to return (1-1000).
     * @return array<int, array<string, mixed>>
     * @throws RuntimeException
     */
    public function getCandles(string $symbol, string $timeframe = '1h', int $limit = 20): array
    {
        [$settings, $metaApiToken, $accountId, $client] = $this->metaApiContext();

        $requested = strtoupper(str_replace('/', '', trim($symbol)));
        if ($requested === '') {
            throw new RuntimeException('Symbol is required to fetch candles.');
        }

        $allowedTimeframes = ['1m', '5m', '15m', '30m', '1h', '4h', '1d', '1w', '1mn'];
        if (!in_array($timeframe, $allowedTimeframes, true)) {
            throw new RuntimeException("Invalid timeframe '{$timeframe}'. Allowed: ".implode(', ', $allowedTimeframes));
        }

        $limit = max(1, min(1000, $limit));
        $normalizedTimeframe = strtolower($timeframe);
        $bucket = $this->candleCacheBucket($normalizedTimeframe);
        $cacheKey = sprintf(
            'mt5_candles:%s:%s:%s:%d:%d',
            $accountId,
            $requested,
            $normalizedTimeframe,
            $limit,
            $bucket
        );

        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey, []);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $encodedTimeframe = rawurlencode($timeframe);
        $marketDataClient = $this->marketDataClient($metaApiToken, (string) ($settings->metaapi_region ?? 'new-york'));
        $candidateSymbols = $this->buildCandleSymbolCandidates($client, $accountId, $requested);

        $lastError = null;
        foreach ($candidateSymbols as $candidateSymbol) {
            $encodedSymbol = rawurlencode($candidateSymbol);

            try {
                $response = $marketDataClient->get(
                    "/users/current/accounts/{$accountId}/historical-market-data/symbols/{$encodedSymbol}/timeframes/{$encodedTimeframe}/candles",
                    ['query' => ['limit' => $limit]]
                );

                $decoded = json_decode((string) $response->getBody(), true);

                if (is_array($decoded)) {
                    Cache::put($cacheKey, $decoded, now()->addDays(2));
                    return $decoded;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $detail = $lastError ? ' '.$lastError : '';
        throw new RuntimeException(
            'Unable to fetch candles for symbol '.$requested.' timeframe '.$timeframe.'. Tried: '.implode(', ', $candidateSymbols).'.'.$detail
        );
    }

    /**
     * Resolve cache bucket index so candle cache rotates automatically when a
     * new timeframe candle starts.
     */
    private function candleCacheBucket(string $timeframe): int
    {
        $seconds = $this->candleTimeframeSeconds($timeframe);

        return (int) floor(time() / max(1, $seconds));
    }

    /**
     * Convert MetaApi timeframe code to seconds for cache bucket rotation.
     */
    private function candleTimeframeSeconds(string $timeframe): int
    {
        return match (strtolower($timeframe)) {
            '1m' => 60,
            '5m' => 300,
            '15m' => 900,
            '30m' => 1800,
            '1h' => 3600,
            '4h' => 14400,
            '1d' => 86400,
            '1w' => 604800,
            '1mn' => 2592000,
            default => 60,
        };
    }

    /**
     * Get a best-effort list of major forex symbols available on the account.
     *
     * @return array<int, string>
     */
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

    /**
     * Get all discovered forex-like symbols from the broker symbol catalog.
     *
     * @return array<int, string>
     */
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

    /**
     * Apply trailing stop and optional one-time TP expansion to open positions.
     *
     * @param float $startPips Minimum profit in pips before trailing begins.
     * @param float $trailPips Trailing distance in pips.
     * @param float $tpMultiplier Take-profit expansion multiplier (>= 1).
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    public function applyTrailingStops(float $startPips, float $trailPips, float $tpMultiplier = 2.0, ?callable $parameterResolver = null): array
    {
        if ($startPips <= 0 || $trailPips <= 0 || $tpMultiplier < 1) {
            throw new RuntimeException('Trailing parameters are invalid. startPips/trailPips must be > 0 and tpMultiplier must be >= 1.');
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
            $pricePrecision = $this->pricePrecisionForPipSize($pipSize);

            $resolvedStartPips = $startPips;
            $resolvedTrailPips = $trailPips;
            $resolvedTpMultiplier = $tpMultiplier;

            if ($parameterResolver !== null) {
                $resolved = $parameterResolver($symbol, $position);
                if (is_array($resolved)) {
                    if (isset($resolved['start_pips']) && is_numeric($resolved['start_pips']) && (float) $resolved['start_pips'] > 0) {
                        $resolvedStartPips = (float) $resolved['start_pips'];
                    }
                    if (isset($resolved['trail_pips']) && is_numeric($resolved['trail_pips']) && (float) $resolved['trail_pips'] > 0) {
                        $resolvedTrailPips = (float) $resolved['trail_pips'];
                    }
                    if (isset($resolved['tp_multiplier']) && is_numeric($resolved['tp_multiplier']) && (float) $resolved['tp_multiplier'] >= 1) {
                        $resolvedTpMultiplier = (float) $resolved['tp_multiplier'];
                    }
                }
            }

            $trailDistance = $resolvedTrailPips * $pipSize;
            $startDistance = $resolvedStartPips * $pipSize;

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

                $newSl = round($currentPrice - $trailDistance, $pricePrecision);
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

                $newSl = round($currentPrice + $trailDistance, $pricePrecision);
                if ($currentSl !== null && $newSl >= $currentSl) {
                    $skipped++;
                    continue;
                }
            }

            $newTp = $currentTp;
            $tpAdjustedCacheKey = 'mt5_tp_adjusted_'.$positionId;

            // Apply TP multiplier once when trailing first modifies this position.
            if ($currentTp !== null && !Cache::has($tpAdjustedCacheKey) && $resolvedTpMultiplier > 1) {
                if ($isBuy) {
                    $tpDistance = $currentTp - $openPrice;
                    if ($tpDistance > 0) {
                        $newTp = round($openPrice + ($tpDistance * $resolvedTpMultiplier), $pricePrecision);
                    }
                } else {
                    $tpDistance = $openPrice - $currentTp;
                    if ($tpDistance > 0) {
                        $newTp = round($openPrice - ($tpDistance * $resolvedTpMultiplier), $pricePrecision);
                    }
                }
            }

            try {
                $this->modifyPositionStops($positionId, $newSl, $newTp);

                if ($newTp !== $currentTp) {
                    Cache::put($tpAdjustedCacheKey, true, now()->addDays(30));
                }

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

    /**
     * Modify stop-loss and/or take-profit values for an open position.
     *
     * @param string $positionId Broker position identifier.
     * @param float|null $stopLoss New stop-loss price.
     * @param float|null $takeProfit New take-profit price.
     * @return array<string, mixed>
     * @throws RuntimeException
     */
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

    /**
     * Resolve the most likely tradable broker symbol variant.
     *
     * @param Client $client Configured MetaApi HTTP client.
     * @param string $accountId MetaApi account id.
     * @param string $symbol User-requested symbol.
     * @return string
     * @throws RuntimeException
     */
    private function resolveBrokerSymbol(Client $client, string $accountId, string $symbol): string
    {
        $requested = strtoupper(str_replace('/', '', trim($symbol)));

        if ($requested === '') {
            throw new RuntimeException('Symbol cannot be empty.');
        }

        $baseRequested = str_ends_with($requested, '_SB') ? substr($requested, 0, -3) : $requested;

        $candidates = [];
        foreach (self::COMMON_SPREAD_BET_SUFFIXES as $suffix) {
            $candidates[] = $baseRequested.$suffix;
        }
        foreach (self::COMMON_PEPPERSTONE_SUFFIXES as $suffix) {
            $candidates[] = $baseRequested.$suffix;
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

            foreach (self::COMMON_SPREAD_BET_SUFFIXES as $suffix) {
                $spreadBetCandidate = $baseRequested.$suffix;
                if (isset($availableMap[strtoupper($spreadBetCandidate)])) {
                    return $availableMap[strtoupper($spreadBetCandidate)];
                }
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

            if (!empty($prefixSpreadBetMatches)) {
                usort($prefixSpreadBetMatches, fn (string $a, string $b) => strlen($a) <=> strlen($b));

                return $prefixSpreadBetMatches[0];
            }

            if (!empty($prefixMatches)) {
                usort($prefixMatches, function (string $a, string $b): int {
                    $aUpper = strtoupper($a);
                    $bUpper = strtoupper($b);

                    // For non-_SB fallback, prefer common FX symbol variants first.
                    $aScore = (int) str_contains($aUpper, '.') * 2 + (int) !str_contains($aUpper, '_');
                    $bScore = (int) str_contains($bUpper, '.') * 2 + (int) !str_contains($bUpper, '_');

                    if ($aScore !== $bScore) {
                        return $bScore <=> $aScore;
                    }

                    return strlen($aUpper) <=> strlen($bUpper);
                });

                return $prefixMatches[0];
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

            if (!empty($containsSpreadBetMatches)) {
                usort($containsSpreadBetMatches, fn (string $a, string $b) => strlen($a) <=> strlen($b));

                return $containsSpreadBetMatches[0];
            }

            if (!empty($containsMatches)) {
                usort($containsMatches, fn (string $a, string $b) => strlen($a) <=> strlen($b));

                return $containsMatches[0];
            }
        } catch (\Throwable) {
            // If symbol discovery fails, continue with requested symbol and let trade API decide.
        }

        return $requested;
    }

    /**
     * Extract symbol names from mixed MetaApi symbol payload shapes.
     *
     * @param mixed $decoded Decoded JSON response.
     * @return array<int, string>
     */
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

    /**
     * Normalize requested lot/stake size to broker constraints.
     *
     * @param string $symbol Broker symbol.
     * @param float $requestedVolume User requested volume.
     * @return float
     * @throws RuntimeException
     */
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

    /**
     * Apply global volume multiplier from settings.
     *
     * @param AppSetting $settings Bot configuration singleton.
     * @param float $lotSize Base lot size.
     * @return float
     */
    private function applyConfiguredVolumeMultiplier(AppSetting $settings, float $lotSize): float
    {
        $multiplier = max(1, (int) ($settings->mt5_volume_multiplier ?? 1));

        return $lotSize * $multiplier;
    }

    /**
     * Pick the best available broker symbol for a major pair.
     *
     * @param string $pair Base pair (for example EURUSD).
     * @param array<int, string> $availableSymbols Broker symbols list.
     * @return string|null
     */
    private function pickBestSymbolForPair(string $pair, array $availableSymbols): ?string
    {
        $pair = strtoupper($pair);
        $availableMap = [];
        foreach ($availableSymbols as $symbol) {
            if (is_string($symbol) && $symbol !== '') {
                $availableMap[strtoupper($symbol)] = $symbol;
            }
        }

        foreach (self::COMMON_SPREAD_BET_SUFFIXES as $suffix) {
            $spreadBetCandidate = $pair.$suffix;
            if (isset($availableMap[strtoupper($spreadBetCandidate)])) {
                return $availableMap[strtoupper($spreadBetCandidate)];
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

        if (!empty($prefixSpreadBetMatches)) {
            usort($prefixSpreadBetMatches, fn (string $a, string $b) => strlen($a) <=> strlen($b));
            return $prefixSpreadBetMatches[0];
        }

        if (!empty($prefixMatches)) {
            usort($prefixMatches, fn (string $a, string $b) => strlen($a) <=> strlen($b));
            return $prefixMatches[0];
        }

        return null;
    }

    /**
     * Build ordered candidate symbols for trade placement attempts.
     *
     * @param Client $client Configured MetaApi HTTP client.
     * @param string $accountId MetaApi account id.
     * @param string $symbol Requested user symbol.
     * @return array<int, string>
     * @throws RuntimeException
     */
    private function buildTradeSymbolCandidates(Client $client, string $accountId, string $symbol): array
    {
        $requested = strtoupper(str_replace('/', '', trim($symbol)));
        if ($requested === '') {
            throw new RuntimeException('Symbol cannot be empty.');
        }

        $candidates = [];

        $baseRequested = str_ends_with($requested, '_SB') ? substr($requested, 0, -3) : $requested;

        // Pepperstone spread-bet symbols should be preferred first.
        foreach (self::COMMON_SPREAD_BET_SUFFIXES as $suffix) {
            $candidates[] = $baseRequested.$suffix;
        }

        // Also try exactly what user typed.
        $candidates[] = $requested;

        $resolved = $this->resolveBrokerSymbol($client, $accountId, $requested);
        if ($resolved !== '') {
            $candidates[] = $resolved;
        }

        foreach (self::COMMON_PEPPERSTONE_SUFFIXES as $suffix) {
            $candidates[] = $baseRequested.$suffix;
        }

        $candidates[] = $requested;

        $normalized = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $upper = strtoupper($candidate);
            if (!isset($normalized[$upper])) {
                $normalized[$upper] = $candidate;
            }
        }

        return array_values($normalized);
    }

    /**
     * Build ordered candidate symbols for quote/current-price requests.
     *
     * Keep spread-bet variants first and avoid bare broker suffixes like
     * "EURUSDc" which are noisy for this account setup.
     *
     * @param Client $client Configured MetaApi HTTP client.
     * @param string $accountId MetaApi account id.
     * @param string $symbol Requested user symbol.
     * @return array<int, string>
     */
    private function buildQuoteSymbolCandidates(Client $client, string $accountId, string $symbol): array
    {
        $requested = strtoupper(str_replace('/', '', trim($symbol)));
        if ($requested === '') {
            return [];
        }

        $baseRequested = str_ends_with($requested, '_SB') ? substr($requested, 0, -3) : $requested;
        $candidates = [];
        $isPlainFxPair = preg_match('/^[A-Z]{6}$/', $requested) === 1;
        $isSpreadBetRequested = $this->isSpreadBetSymbol($requested);
        $requireSpreadBetVariant = $isPlainFxPair || $isSpreadBetRequested;

        foreach (self::COMMON_SPREAD_BET_SUFFIXES as $suffix) {
            $candidates[] = $baseRequested.$suffix;
        }

        // For plain FX symbols, keep quote lookups strict to spread-bet variants.
        if (!$isPlainFxPair) {
            $candidates[] = $requested;
        }

        $resolved = $this->resolveBrokerSymbol($client, $accountId, $requested);
        if ($resolved !== '' && (!$requireSpreadBetVariant || $this->isSpreadBetSymbol($resolved))) {
            $candidates[] = $resolved;
        }

        // Add a best-effort broker-discovered symbol variant for the same base pair.
        try {
            $response = $client->get("/users/current/accounts/{$accountId}/symbols");
            $decoded = json_decode((string) $response->getBody(), true);
            $availableSymbols = $this->extractSymbolNames($decoded);
            if (!empty($availableSymbols)) {
                $preferred = $this->pickBestSymbolForPair($baseRequested, $availableSymbols);
                if ($preferred !== null && $preferred !== '') {
                    if (!$requireSpreadBetVariant || $this->isSpreadBetSymbol($preferred)) {
                        $candidates[] = $preferred;
                    }
                }
            }
        } catch (\Throwable) {
            // Keep best-effort local candidates when discovery fails.
        }

        if (!$isPlainFxPair && !$isSpreadBetRequested) {
            $candidates[] = $baseRequested;
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $upper = strtoupper($candidate);
            if (!isset($normalized[$upper])) {
                $normalized[$upper] = $candidate;
            }
        }

        return array_values($normalized);
    }

    /**
     * Build ordered candidate symbols for candle/history requests.
     *
     * Historical-market-data can differ from tradable symbol naming
     * (for example, base pair candles may exist while *_SB candles do not).
     *
     * @param Client $client Configured MetaApi HTTP client.
     * @param string $accountId MetaApi account id.
     * @param string $symbol Requested user symbol.
     * @return array<int, string>
     */
    private function buildCandleSymbolCandidates(Client $client, string $accountId, string $symbol): array
    {
        $requested = strtoupper(str_replace('/', '', trim($symbol)));
        if ($requested === '') {
            return [];
        }

        $baseRequested = $this->baseSymbol($requested);
        $candidates = [];

        // Candle endpoints may reject spread-bet suffixes, so try base first.
        $candidates[] = $baseRequested;
        $candidates[] = $requested;

        try {
            $response = $client->get("/users/current/accounts/{$accountId}/symbols");
            $decoded = json_decode((string) $response->getBody(), true);
            $availableSymbols = $this->extractSymbolNames($decoded);

            if (!empty($availableSymbols)) {
                $preferred = $this->pickBestSymbolForPair($baseRequested, $availableSymbols);
                if ($preferred !== null && $preferred !== '') {
                    $candidates[] = $preferred;
                }

                foreach ($availableSymbols as $availableSymbol) {
                    if (!is_string($availableSymbol) || $availableSymbol === '') {
                        continue;
                    }

                    $availableUpper = strtoupper($availableSymbol);
                    if ($this->baseSymbol($availableUpper) === $baseRequested && !$this->isSpreadBetSymbol($availableUpper)) {
                        $candidates[] = $availableSymbol;
                    }
                }
            }
        } catch (\Throwable) {
            // Keep best-effort local candidates when discovery fails.
        }

        foreach ($this->buildTradeSymbolCandidates($client, $accountId, $requested) as $tradeCandidate) {
            $candidates[] = $tradeCandidate;
            $candidates[] = $this->baseSymbol($tradeCandidate);
        }

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

    /**
     * Strip broker-specific suffixes (e.g. ".a", ".m", "_SB") from a symbol
     * and return the normalised base symbol in upper-case.
     */
    public function toBrokerSymbol(string $symbol): string
    {
        return strtoupper(str_replace('/', '', trim($symbol)));
    }

    /**
     * Strip broker-specific suffixes (e.g. ".a", ".m", "_SB") from a symbol
     * and return the normalised base symbol in upper-case.
     */
    public function baseSymbol(string $symbol): string
    {
        $upper = strtoupper(str_replace('/', '', trim($symbol)));

        // Strip spread-bet suffix first.
        if (str_ends_with($upper, '_SB')) {
            $upper = substr($upper, 0, -3);
        }

        // Strip known dot-prefixed broker suffixes (longest first to avoid partial matches).
        foreach (['.pro', '.a', '.m', '.r', '.c'] as $suffix) {
            if (str_ends_with($upper, strtoupper($suffix))) {
                return substr($upper, 0, -strlen($suffix));
            }
        }

        return $upper;
    }

    /**
     * Determine if the exception represents an unknown symbol error.
     *
     * @param RuntimeException $e Caught trade exception.
     * @return bool
     */
    private function isUnknownSymbolError(RuntimeException $e): bool
    {
        $message = strtoupper($e->getMessage());

        return str_contains($message, 'ERR_MARKET_UNKNOWN_SYMBOL')
            || str_contains($message, 'UNKNOWN SYMBOL');
    }

    /**
     * Get lot/stake increment step for a symbol.
     *
     * @param string $symbol Broker symbol.
     * @return float
     */
    private function volumeStep(string $symbol): float
    {
        return $this->isSpreadBetSymbol($symbol) ? 1.0 : 0.01;
    }

    /**
     * Get pip size for the provided symbol.
     *
     * @param string $symbol Broker symbol.
     * @return float
     */
    private function pipSize(string $symbol): float
    {
        $meta = $this->tickerMetaForSymbol($symbol);

        return $this->resolvePipSize(
            $symbol,
            $meta['category'] ?? null,
            $meta['pip_size'] ?? null
        );
    }

    /**
     * Resolve pip/tick size for a symbol across FX and non-FX assets.
     */
    public function resolvePipSize(string $symbol, ?string $category = null, ?float $overridePipSize = null): float
    {
        if (is_numeric($overridePipSize) && (float) $overridePipSize > 0) {
            return (float) $overridePipSize;
        }

        $normalizedCategory = strtolower(trim((string) $category));
        if ($normalizedCategory !== '') {
            if (
                str_contains($normalizedCategory, 'stock')
                || str_contains($normalizedCategory, 'equity')
                || str_contains($normalizedCategory, 'share')
                || str_contains($normalizedCategory, 'commodity')
                || str_contains($normalizedCategory, 'metal')
                || str_contains($normalizedCategory, 'energy')
                || str_contains($normalizedCategory, 'oil')
                || str_contains($normalizedCategory, 'gold')
                || str_contains($normalizedCategory, 'silver')
                || str_contains($normalizedCategory, 'index')
                || str_contains($normalizedCategory, 'indice')
                || str_contains($normalizedCategory, 'crypto')
                || str_contains($normalizedCategory, 'other')
            ) {
                // 1.0 means 1 pip = 1 price point (dollar/index point/coin unit).
                // This keeps TP/SL/spread config values human-readable for non-FX assets.
                return 1.0;
            }
        }

        $base = $this->baseSymbol($symbol);
        if ($this->looksLikeForexPair($base)) {
            return str_ends_with($base, 'JPY') ? 0.01 : 0.0001;
        }

        // Unknown symbol — default to 1.0 (point-based) so spread/TP/SL stay human-readable.
        return 1.0;
    }

    /**
     * Determine price precision from pip/tick size.
     */
    public function pricePrecisionForPipSize(float $pipSize): int
    {
        if ($pipSize <= 0) {
            return 5;
        }

        $formatted = rtrim(rtrim(sprintf('%.10F', $pipSize), '0'), '.');
        $dotPos = strpos($formatted, '.');
        if ($dotPos === false) {
            return 0;
        }

        return min(8, max(0, strlen($formatted) - $dotPos - 1));
    }

    /**
     * @return array{pip_size: float|null, category: string|null}
     */
    private function tickerMetaForSymbol(string $symbol): array
    {
        $upper = strtoupper(trim($symbol));
        if ($upper === '') {
            return ['pip_size' => null, 'category' => null];
        }

        if (isset($this->tickerMetaCache[$upper])) {
            return $this->tickerMetaCache[$upper];
        }

        $base = $this->baseSymbol($upper);
        $ticker = Ticker::query()
            ->whereIn('symbol', array_values(array_unique([$upper, $base])))
            ->orderByRaw('CASE WHEN symbol = ? THEN 0 ELSE 1 END', [$upper])
            ->first(['pip_size', 'category']);

        $meta = [
            'pip_size' => $ticker && is_numeric($ticker->pip_size) ? (float) $ticker->pip_size : null,
            'category' => $ticker ? (string) ($ticker->category ?? '') : null,
        ];

        $this->tickerMetaCache[$upper] = $meta;

        return $meta;
    }

    private function looksLikeForexPair(string $symbol): bool
    {
        $base = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z]{6}$/', $base)) {
            return false;
        }

        $baseCurrency = substr($base, 0, 3);
        $quoteCurrency = substr($base, 3, 3);

        return in_array($baseCurrency, self::KNOWN_FX_CURRENCIES, true)
            && in_array($quoteCurrency, self::KNOWN_FX_CURRENCIES, true);
    }

    /**
     * Check if a symbol is a spread-bet contract.
     *
     * @param string $symbol Broker symbol.
     * @return bool
     */
    private function isSpreadBetSymbol(string $symbol): bool
    {
        return str_ends_with(strtoupper($symbol), '_SB');
    }

    /**
     * Normalize and validate exit-leg input entries.
     *
     * @param array<int, array<string, mixed>> $exitLegs Raw exit-leg payload.
     * @return array<int, array<string, float|null>>
     */
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

    /**
     * Send a trade request and translate transport/logical errors into runtime exceptions.
     *
     * @param Client $client Configured MetaApi HTTP client.
     * @param string $accountId MetaApi account id.
     * @param array<string, mixed> $payload Trade payload.
     * @return array<string, mixed>
     * @throws RuntimeException
     */
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

    /**
     * Build a MetaApi execution context from current app settings.
     *
     * @return array{0: AppSetting, 1: string, 2: string, 3: Client}
     * @throws RuntimeException
     */
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

        $client = $this->metaApiClient($metaApiToken, $metaApiRegion);

        return [$settings, $metaApiToken, $metaApiAccountId, $client];
    }

    /**
     * Build a MetaApi client for trading terminal and execution endpoints.
     */
    private function metaApiClient(string $metaApiToken, string $metaApiRegion): Client
    {
        return new Client([
            'base_uri' => str_replace('{region}', $metaApiRegion, self::BASE_URL),
            'timeout' => self::METAAPI_TIMEOUT_SECONDS,
            'connect_timeout' => self::METAAPI_CONNECT_TIMEOUT_SECONDS,
            'headers' => [
                'auth-token' => $metaApiToken,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Build a MetaApi market-data client for historical candles/ticks endpoints.
     */
    private function marketDataClient(string $metaApiToken, string $metaApiRegion): Client
    {
        return new Client([
            'base_uri' => str_replace('{region}', $metaApiRegion, self::MARKET_DATA_BASE_URL),
            'timeout' => self::METAAPI_TIMEOUT_SECONDS,
            'connect_timeout' => self::METAAPI_CONNECT_TIMEOUT_SECONDS,
            'headers' => [
                'auth-token' => $metaApiToken,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Safe GET helper that returns decoded payload or an error envelope.
     *
     * @param Client $client Configured MetaApi HTTP client.
     * @param string $path Relative endpoint path.
     * @return array<string, mixed>
     */
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

    /**
     * Fetch history deals with bounded retries for rate-limit/transient failures.
     *
     * @param Client $client Configured MetaApi HTTP client.
     * @param string $accountId MetaApi account id.
     * @param \DateTimeInterface $from Inclusive start time.
     * @param \DateTimeInterface $to Inclusive end time.
     * @return array<int, array<string, mixed>>
     * @throws RuntimeException
     */
    private function fetchHistoryDealsWithRetry(
        Client $client,
        string $accountId,
        \DateTimeInterface $from,
        \DateTimeInterface $to
    ): array {
        $startTime = rawurlencode($from->format(\DateTimeInterface::ATOM));
        $endTime = rawurlencode($to->format(\DateTimeInterface::ATOM));
        $path = "/users/current/accounts/{$accountId}/history-deals/time/{$startTime}/{$endTime}";

        for ($attempt = 0; $attempt <= self::HISTORY_DEALS_MAX_RETRIES; $attempt++) {
            try {
                $response = $client->get($path);
                $decoded = json_decode((string) $response->getBody(), true);

                return is_array($decoded) ? $decoded : [];
            } catch (ClientException $e) {
                $status = $e->getResponse()->getStatusCode();
                $body = (string) $e->getResponse()->getBody();
                $decoded = json_decode($body, true);
                $detail = is_array($decoded) ? ($decoded['message'] ?? $body) : $body;

                if ($status === 429 && $attempt < self::HISTORY_DEALS_MAX_RETRIES) {
                    $delayMs = $this->historyDealsBackoffDelayMs($e, $attempt);
                    usleep($delayMs * 1000);
                    continue;
                }

                throw new RuntimeException("MetaApi history deals failed [{$status}]: {$detail}");
            } catch (RequestException $e) {
                if ($attempt < self::HISTORY_DEALS_MAX_RETRIES) {
                    $delayMs = self::HISTORY_DEALS_BACKOFF_BASE_MS * (2 ** $attempt);
                    usleep($delayMs * 1000);
                    continue;
                }

                throw new RuntimeException('MetaApi history deals request failed: '.$e->getMessage());
            }
        }

        throw new RuntimeException('MetaApi history deals failed after retries due to too many requests.');
    }

    /**
     * Fetch account information with bounded retries for transient failures.
     *
     * @param Client $client Configured MetaApi HTTP client.
     * @param string $accountId MetaApi account id.
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    private function fetchAccountInformationWithRetry(Client $client, string $accountId): array
    {
        $path = "/users/current/accounts/{$accountId}/account-information";

        for ($attempt = 0; $attempt <= self::ACCOUNT_INFO_MAX_RETRIES; $attempt++) {
            try {
                $response = $client->get($path);
                $decoded = json_decode((string) $response->getBody(), true);

                return is_array($decoded) ? $decoded : [];
            } catch (ClientException $e) {
                $status = $e->getResponse()->getStatusCode();
                $body = (string) $e->getResponse()->getBody();
                $decoded = json_decode($body, true);
                $detail = is_array($decoded) ? ($decoded['message'] ?? $body) : $body;
                $isRetryableStatus = in_array($status, [429, 502, 503, 504], true);

                if ($isRetryableStatus && $attempt < self::ACCOUNT_INFO_MAX_RETRIES) {
                    $delayMs = $this->accountInfoBackoffDelayMs($e, $attempt, $status);
                    usleep($delayMs * 1000);
                    continue;
                }

                throw new RuntimeException("MetaApi account information failed [{$status}]: {$detail}");
            } catch (RequestException $e) {
                if ($attempt < self::ACCOUNT_INFO_MAX_RETRIES) {
                    $delayMs = self::ACCOUNT_INFO_BACKOFF_BASE_MS * (2 ** $attempt);
                    usleep($delayMs * 1000);
                    continue;
                }

                throw new RuntimeException('MetaApi account information request failed: '.$e->getMessage());
            }
        }

        throw new RuntimeException('MetaApi account information failed after retries.');
    }

    /**
     * Determine retry delay for account-information retries.
     */
    private function accountInfoBackoffDelayMs(ClientException $e, int $attempt, int $status): int
    {
        if ($status === 429) {
            $retryAfter = trim($e->getResponse()->getHeaderLine('Retry-After'));

            if (is_numeric($retryAfter)) {
                return (int) $retryAfter * 1000;
            }
        }

        return self::ACCOUNT_INFO_BACKOFF_BASE_MS * (2 ** $attempt);
    }

    /**
     * Determine retry delay for 429 responses.
     *
     * Uses Retry-After header when available, otherwise falls back to
     * exponential backoff based on the attempt index.
     *
     * @param ClientException $e Rate-limited client exception.
     * @param int $attempt Current zero-based attempt index.
     * @return int Delay in milliseconds.
     */
    private function historyDealsBackoffDelayMs(ClientException $e, int $attempt): int
    {
        $retryAfter = trim($e->getResponse()->getHeaderLine('Retry-After'));

        if (is_numeric($retryAfter)) {
            return (int) $retryAfter * 1000;
        }

        return self::HISTORY_DEALS_BACKOFF_BASE_MS * (2 ** $attempt);
    }

    /**
     * Ensure required MetaApi credentials are configured.
     *
     * @param string $metaApiToken MetaApi auth token.
     * @param string $metaApiAccountId MetaApi account id.
     * @return void
     * @throws RuntimeException
     */
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
