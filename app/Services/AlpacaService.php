<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Services\Brokers\MarketBrokerInterface;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class AlpacaService implements MarketBrokerInterface
{
    private const PAPER_TRADING_URL = 'https://paper-api.alpaca.markets';
    private const LIVE_TRADING_URL = 'https://api.alpaca.markets';
    private const DATA_URL = 'https://data.alpaca.markets';
    private const TIMEOUT_SECONDS = 12;
    private const CONNECT_TIMEOUT_SECONDS = 5;
    /** Alpaca crypto rejects orders below this USD notional (cost basis). */
    private const MIN_ORDER_NOTIONAL_USD = 10.0;

    public function assertConfigured(): void
    {
        [$settings, $keyId, $secret] = $this->credentials();
        if ($keyId === '' || $secret === '') {
            throw new RuntimeException('Alpaca API credentials are missing. Add Key ID and Secret in Settings.');
        }
    }

    public function getTickerPrice(string $symbol): array
    {
        $alpacaSymbol = $this->toAlpacaSymbol($symbol);
        $client = $this->dataClient();

        $response = $client->get('/v1beta3/crypto/us/latest/quotes', [
            'query' => ['symbols' => $alpacaSymbol],
        ]);

        $decoded = json_decode((string) $response->getBody(), true);
        $quote = is_array($decoded['quotes'][$alpacaSymbol] ?? null) ? $decoded['quotes'][$alpacaSymbol] : null;
        if ($quote === null) {
            throw new RuntimeException('Unable to fetch Alpaca quote for '.$alpacaSymbol.'.');
        }

        $bid = isset($quote['bp']) ? (float) $quote['bp'] : (isset($quote['bid']) ? (float) $quote['bid'] : null);
        $ask = isset($quote['ap']) ? (float) $quote['ap'] : (isset($quote['ask']) ? (float) $quote['ask'] : null);

        if ($bid === null || $ask === null || $bid <= 0 || $ask <= 0) {
            throw new RuntimeException('Invalid Alpaca quote for '.$alpacaSymbol.'.');
        }

        return [
            'symbol' => $alpacaSymbol,
            'bid' => $bid,
            'ask' => $ask,
            'last' => ($bid + $ask) / 2,
            'time' => $quote['t'] ?? now()->toDateTimeString(),
            'raw' => $quote,
        ];
    }

    public function getCandles(string $symbol, string $timeframe = '1h', int $limit = 20): array
    {
        $alpacaSymbol = $this->toAlpacaSymbol($symbol);
        $alpacaTimeframe = $this->mapTimeframe($timeframe);
        $limit = max(1, min(1000, $limit));

        $cacheKey = sprintf('alpaca_candles:%s:%s:%d', md5($alpacaSymbol), $alpacaTimeframe, $limit);
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey, []);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $client = $this->dataClient();
        $response = $client->get('/v1beta3/crypto/us/bars', [
            'query' => [
                'symbols' => $alpacaSymbol,
                'timeframe' => $alpacaTimeframe,
                'limit' => $limit,
            ],
        ]);

        $decoded = json_decode((string) $response->getBody(), true);
        $bars = is_array($decoded['bars'][$alpacaSymbol] ?? null) ? $decoded['bars'][$alpacaSymbol] : [];
        $normalized = $this->normalizeBars($bars);

        if (empty($normalized)) {
            throw new RuntimeException('No Alpaca candles returned for '.$alpacaSymbol.' ('.$timeframe.').');
        }

        Cache::put($cacheKey, $normalized, now()->addMinutes(2));

        return $normalized;
    }

    public function placeOrder(string $symbol, float $lotSize, string $side, array $exitLegs = []): array
    {
        $this->assertConfigured();
        $settings = AppSetting::singleton();
        if (!$this->usesPaper($settings) && $settings->demo_only) {
            throw new RuntimeException('Live Alpaca trading is blocked while demo_only mode is enabled.');
        }

        $alpacaSymbol = $this->toAlpacaSymbol($symbol);
        $orderSide = strtolower($side) === 'sell' ? 'sell' : 'buy';
        $maxQty = null;
        if ($orderSide === 'sell') {
            $maxQty = $this->positionQtyForSymbol($alpacaSymbol);
            if ($maxQty <= 0) {
                throw new RuntimeException('Cannot sell '.$alpacaSymbol.': no holdings on Alpaca (spot crypto is long-only).');
            }
        }

        $qty = $this->resolveOrderQty($symbol, $lotSize, $orderSide, null, $maxQty);
        if ($qty <= 0) {
            throw new RuntimeException('Order quantity for '.$alpacaSymbol.' is below Alpaca minimum notional.');
        }
        $leg = is_array($exitLegs[0] ?? null) ? $exitLegs[0] : [];
        $takeProfit = isset($leg['take_profit']) ? (float) $leg['take_profit'] : null;
        $stopLoss = isset($leg['stop_loss']) ? (float) $leg['stop_loss'] : null;

        $payload = [
            'symbol' => $alpacaSymbol,
            'qty' => $this->formatQty($qty),
            'side' => $orderSide,
            'type' => 'market',
            'time_in_force' => 'gtc',
        ];

        $client = $this->tradingClient();
        $entryResponse = $this->submitOrder($client, $payload);
        $orderId = trim((string) ($entryResponse['id'] ?? ''));

        $orders = [[
            'leg' => $leg,
            'payload' => $payload,
            'response' => [
                'orderId' => $orderId,
                'positionId' => $orderId,
                'raw' => $entryResponse,
            ],
        ]];

        // Alpaca crypto only supports order_class=simple — bracket/OTOCO is rejected.
        if ($stopLoss !== null) {
            $exitSide = $orderSide === 'buy' ? 'sell' : 'buy';
            $stopPrice = $this->formatPrice($stopLoss);
            $limitPrice = $orderSide === 'buy'
                ? $this->formatPrice($stopLoss * 0.999)
                : $this->formatPrice($stopLoss * 1.001);

            $stopPayload = [
                'symbol' => $alpacaSymbol,
                'qty' => $this->formatQty($qty),
                'side' => $exitSide,
                'type' => 'stop_limit',
                'stop_price' => $stopPrice,
                'limit_price' => $limitPrice,
                'time_in_force' => 'gtc',
            ];

            try {
                $stopResponse = $this->submitOrder($client, $stopPayload);
                $orders[] = [
                    'leg' => ['type' => 'stop_loss', 'stop_loss' => $stopLoss],
                    'payload' => $stopPayload,
                    'response' => [
                        'orderId' => trim((string) ($stopResponse['id'] ?? '')),
                        'raw' => $stopResponse,
                    ],
                ];
            } catch (\Throwable $e) {
                $orders[] = [
                    'leg' => ['type' => 'stop_loss', 'stop_loss' => $stopLoss],
                    'payload' => $stopPayload,
                    'response' => ['error' => $e->getMessage()],
                ];
            }
        }

        if ($takeProfit !== null) {
            $exitSide = $orderSide === 'buy' ? 'sell' : 'buy';
            $tpPayload = [
                'symbol' => $alpacaSymbol,
                'qty' => $this->formatQty($qty),
                'side' => $exitSide,
                'type' => 'limit',
                'limit_price' => $this->formatPrice($takeProfit),
                'time_in_force' => 'gtc',
            ];

            try {
                $tpResponse = $this->submitOrder($client, $tpPayload);
                $orders[] = [
                    'leg' => ['type' => 'take_profit', 'take_profit' => $takeProfit],
                    'payload' => $tpPayload,
                    'response' => [
                        'orderId' => trim((string) ($tpResponse['id'] ?? '')),
                        'raw' => $tpResponse,
                    ],
                ];
            } catch (\Throwable $e) {
                $orders[] = [
                    'leg' => ['type' => 'take_profit', 'take_profit' => $takeProfit],
                    'payload' => $tpPayload,
                    'response' => ['error' => $e->getMessage()],
                ];
            }
        }

        return [
            'mode' => 'multi-leg',
            'symbol' => $alpacaSymbol,
            'broker' => 'alpaca',
            'requested_qty' => $this->normalizeQty($lotSize),
            'order_qty' => $qty,
            'orders' => $orders,
        ];
    }

    /**
     * Resolve crypto order quantity, bumping above Alpaca's minimum USD notional when needed.
     */
    public function resolveOrderQty(string $symbol, float $lotSize, string $side, ?float $referencePrice = null, ?float $maxQty = null): float
    {
        $qty = $this->normalizeQty($lotSize);
        $side = strtolower($side);
        $price = $referencePrice;
        if ($price === null || $price <= 0) {
            $quote = $this->getTickerPrice($symbol);
            $price = $side === 'sell'
                ? (float) ($quote['bid'] ?? 0)
                : (float) ($quote['ask'] ?? 0);
            if ($price <= 0) {
                $price = (float) ($quote['last'] ?? 0);
            }
        }

        if ($side === 'sell') {
            if ($maxQty !== null) {
                if ($maxQty <= 0) {
                    return 0.0;
                }

                $qty = min($qty, $maxQty);
            }

            if ($price > 0 && ($qty * $price) < self::MIN_ORDER_NOTIONAL_USD) {
                if ($maxQty !== null && ($maxQty * $price) >= self::MIN_ORDER_NOTIONAL_USD) {
                    return $this->normalizeQty($maxQty);
                }

                return 0.0;
            }

            return $qty;
        }

        return $this->ensureMinNotionalQty($qty, $price);
    }

    public function estimateBuyNotionalUsd(string $symbol, float $lotSize, ?float $askPrice = null): float
    {
        $price = $askPrice;
        if ($price === null || $price <= 0) {
            $quote = $this->getTickerPrice($symbol);
            $price = (float) ($quote['ask'] ?? $quote['last'] ?? 0);
        }

        if ($price <= 0) {
            return 0.0;
        }

        return $this->resolveOrderQty($symbol, $lotSize, 'buy', $price) * $price;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function submitOrder(Client $client, array $payload): array
    {
        $response = $client->post('/v2/orders', ['json' => $payload]);
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Alpaca order response was invalid.');
        }

        return $decoded;
    }

    public function getOpenTradeSnapshot(): array
    {
        $this->assertConfigured();
        $client = $this->tradingClient();
        $response = $client->get('/v2/positions');
        $decoded = json_decode((string) $response->getBody(), true);
        $positions = is_array($decoded) ? $decoded : [];

        $normalized = [];
        foreach ($positions as $position) {
            if (!is_array($position)) {
                continue;
            }

            $normalized[] = [
                'symbol' => (string) ($position['symbol'] ?? ''),
                'qty' => isset($position['qty']) ? (float) $position['qty'] : null,
                'side' => (string) ($position['side'] ?? ''),
                'avg_entry_price' => isset($position['avg_entry_price']) ? (float) $position['avg_entry_price'] : null,
                'raw' => $position,
            ];
        }

        return [
            'positions' => $normalized,
            'orders' => [],
            'fetched_at' => now()->toDateTimeString(),
            'broker' => 'alpaca',
        ];
    }

    public function positionQtyForSymbol(string $symbol): float
    {
        $target = strtoupper($this->toAlpacaSymbol($symbol));
        $snapshot = $this->getOpenTradeSnapshot();

        foreach ($snapshot['positions'] as $position) {
            if (!is_array($position)) {
                continue;
            }

            $positionSymbol = strtoupper((string) ($position['symbol'] ?? ''));
            if ($positionSymbol !== $target && $this->baseSymbol($positionSymbol) !== $this->baseSymbol($target)) {
                continue;
            }

            $qty = isset($position['qty']) ? (float) $position['qty'] : 0.0;

            return max(0.0, abs($qty));
        }

        return 0.0;
    }

    public function getAccountInformation(): array
    {
        $this->assertConfigured();
        $client = $this->tradingClient();
        $cacheKey = 'alpaca_account_information';
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $response = $client->get('/v2/account');
        $decoded = json_decode((string) $response->getBody(), true);
        if (!is_array($decoded)) {
            return [];
        }

        $equity = isset($decoded['equity']) ? (float) $decoded['equity'] : (float) ($decoded['portfolio_value'] ?? 0);
        $balance = isset($decoded['cash']) ? (float) $decoded['cash'] : $equity;
        $normalized = [
            'equity' => $equity,
            'balance' => $balance,
            'raw' => $decoded,
            'broker' => 'alpaca',
        ];

        Cache::put($cacheKey, $normalized, now()->addSeconds(20));

        return $normalized;
    }

    public function toBrokerSymbol(string $symbol): string
    {
        return $this->toAlpacaSymbol($symbol);
    }

    public function baseSymbol(string $symbol): string
    {
        return strtoupper(str_replace('/', '', trim($symbol)));
    }

    public function toAlpacaSymbol(string $symbol): string
    {
        $normalized = strtoupper(trim($symbol));
        if (str_contains($normalized, '/')) {
            return $normalized;
        }

        if (str_ends_with($normalized, 'USD') && strlen($normalized) > 3) {
            $base = substr($normalized, 0, -3);

            return $base.'/USD';
        }

        return $normalized;
    }

    private function normalizeBars(array $bars): array
    {
        $normalized = [];
        foreach ($bars as $bar) {
            if (!is_array($bar)) {
                continue;
            }

            $normalized[] = [
                'open' => isset($bar['o']) ? (float) $bar['o'] : (isset($bar['open']) ? (float) $bar['open'] : null),
                'high' => isset($bar['h']) ? (float) $bar['h'] : (isset($bar['high']) ? (float) $bar['high'] : null),
                'low' => isset($bar['l']) ? (float) $bar['l'] : (isset($bar['low']) ? (float) $bar['low'] : null),
                'close' => isset($bar['c']) ? (float) $bar['c'] : (isset($bar['close']) ? (float) $bar['close'] : null),
                'volume' => isset($bar['v']) ? (float) $bar['v'] : (isset($bar['volume']) ? (float) $bar['volume'] : 0),
                'time' => $bar['t'] ?? null,
            ];
        }

        return array_values(array_filter($normalized, static fn (array $bar) => $bar['open'] !== null && $bar['close'] !== null));
    }

    private function mapTimeframe(string $timeframe): string
    {
        return match (strtolower(trim($timeframe))) {
            '1m' => '1Min',
            '5m' => '5Min',
            '15m' => '15Min',
            '30m' => '30Min',
            '1h' => '1Hour',
            '4h' => '4Hour',
            '1d' => '1Day',
            default => throw new RuntimeException("Unsupported Alpaca timeframe '{$timeframe}'."),
        };
    }

    private function normalizeQty(float $lotSize): float
    {
        $qty = max(0.0001, round($lotSize, 8));

        return $qty;
    }

    private function ensureMinNotionalQty(float $qty, float $price): float
    {
        if ($price <= 0) {
            return $qty;
        }

        if (($qty * $price) >= self::MIN_ORDER_NOTIONAL_USD) {
            return $qty;
        }

        $requiredQty = (self::MIN_ORDER_NOTIONAL_USD / $price) * 1.002;

        return max($qty, ceil($requiredQty * 100000000) / 100000000);
    }

    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 8, '.', ''), '0'), '.');
    }

    private function formatPrice(float $price): string
    {
        return rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
    }

    /**
     * @return array{0: AppSetting, 1: string, 2: string}
     */
    private function credentials(): array
    {
        $settings = AppSetting::singleton();
        $keyId = trim((string) ($settings->alpaca_api_key_id ?? ''));
        $secret = trim((string) ($settings->alpaca_api_secret ?? ''));

        return [$settings, $keyId, $secret];
    }

    private function usesPaper(AppSetting $settings): bool
    {
        if ($settings->demo_only) {
            return true;
        }

        return (bool) ($settings->alpaca_paper ?? true);
    }

    private function tradingClient(): Client
    {
        [$settings, $keyId, $secret] = $this->credentials();
        $baseUri = $this->usesPaper($settings) ? self::PAPER_TRADING_URL : self::LIVE_TRADING_URL;

        return new Client([
            'base_uri' => $baseUri,
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'APCA-API-KEY-ID' => $keyId,
                'APCA-API-SECRET-KEY' => $secret,
            ],
        ]);
    }

    private function dataClient(): Client
    {
        [$settings, $keyId, $secret] = $this->credentials();
        if ($keyId === '' || $secret === '') {
            throw new RuntimeException('Alpaca API credentials are missing.');
        }

        return new Client([
            'base_uri' => self::DATA_URL,
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'headers' => [
                'Accept' => 'application/json',
                'APCA-API-KEY-ID' => $keyId,
                'APCA-API-SECRET-KEY' => $secret,
            ],
        ]);
    }
}
