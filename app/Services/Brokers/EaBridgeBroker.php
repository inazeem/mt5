<?php

namespace App\Services\Brokers;

use App\Models\AppSetting;
use App\Models\Mt5EaCommand;
use App\Models\Mt5EaTerminal;
use App\Models\Ticker;
use App\Services\EaBridgeService;
use App\Services\Mt5Service;
use App\Services\SymbolMapper;
use RuntimeException;

class EaBridgeBroker implements MarketBrokerInterface
{
    private ?Mt5EaTerminal $terminal = null;

    public function __construct(
        private readonly EaBridgeService $eaBridge,
        private readonly Mt5Service $mt5Service,
        private readonly SymbolMapper $symbolMapper,
    ) {}

    public function forInstance(?string $instanceKey): self
    {
        $clone = clone $this;
        $clone->terminal = $this->eaBridge->resolveTerminal($instanceKey);

        return $clone;
    }

    public function getTickerPrice(string $symbol): array
    {
        $terminal = $this->terminal();
        $requested = strtoupper(str_replace('/', '', trim($symbol)));
        $quotes = is_array($terminal->market_quotes) ? $terminal->market_quotes : [];

        foreach ($this->symbolMapper->brokerSymbolCandidates($terminal, $requested) as $candidate) {
            $quote = $quotes[$candidate] ?? $quotes[strtoupper($candidate)] ?? null;
            if (! is_array($quote)) {
                continue;
            }

            $bid = (float) ($quote['bid'] ?? 0);
            $ask = (float) ($quote['ask'] ?? 0);
            if ($bid > 0 && $ask > 0) {
                return [
                    'symbol' => $candidate,
                    'bid' => $bid,
                    'ask' => $ask,
                    'last' => isset($quote['last']) ? (float) $quote['last'] : (($bid + $ask) / 2),
                    'time' => $quote['time'] ?? $terminal->last_seen_at?->toDateTimeString(),
                    'raw' => $quote,
                ];
            }
        }

        throw new RuntimeException(
            'EA bridge quote unavailable for '.$requested.' on terminal '.$terminal->label()
            .'. Ensure LaravelBridge is online, symbol suffix mode is correct (IC Markets = Plain),'
            .' and the symbol is in the bot profile / ticker list watched by this instance.'
        );
    }

    public function getCandles(string $symbol, string $timeframe = '1h', int $limit = 20): array
    {
        $terminal = $this->terminal();
        $requested = strtoupper(str_replace('/', '', trim($symbol)));
        $normalizedTimeframe = strtolower(trim($timeframe));
        $limit = max(1, min(1000, $limit));
        $candlesByKey = is_array($terminal->market_candles) ? $terminal->market_candles : [];

        foreach ($this->symbolMapper->brokerSymbolCandidates($terminal, $requested) as $candidate) {
            $cacheKey = strtoupper($candidate).':'.$normalizedTimeframe;
            $candles = $candlesByKey[$cacheKey] ?? null;
            if (! is_array($candles) || $candles === []) {
                continue;
            }

            return array_slice($candles, -$limit);
        }

        throw new RuntimeException(
            'EA bridge candles unavailable for '.$requested.' '.$normalizedTimeframe
            .' on terminal '.$terminal->label().'.'
        );
    }

    public function placeOrder(string $symbol, float $lotSize, string $side, array $exitLegs = []): array
    {
        $settings = AppSetting::singleton();
        if (! $settings->demo_only && $this->terminal()->is_demo) {
            // demo terminal with demo_only off is still blocked at service layer elsewhere
        }
        if (! $settings->demo_only) {
            throw new RuntimeException('Live trading is blocked. Keep demo_only enabled until you are ready.');
        }

        if (! $this->terminal()->isOnline()) {
            throw new RuntimeException('EA terminal '.$this->terminal()->label().' is offline.');
        }

        if (! $this->terminal()->trade_allowed) {
            throw new RuntimeException('EA terminal '.$this->terminal()->label().' reports trading disabled.');
        }

        $quote = $this->getTickerPrice($symbol);
        $bid = (float) $quote['bid'];
        $ask = (float) $quote['ask'];
        $entry = strtolower($side) === 'buy' ? $ask : $bid;
        $canonical = $this->mt5Service->baseSymbol($symbol);
        $matchedBrokerSymbol = strtoupper(trim((string) ($quote['symbol'] ?? '')));
        if ($matchedBrokerSymbol === '') {
            $matchedBrokerSymbol = $this->toBrokerSymbol($canonical);
        }
        $ticker = Ticker::query()
            ->whereIn('symbol', array_values(array_unique([$canonical, strtoupper($symbol)])))
            ->orderByRaw('CASE WHEN symbol = ? THEN 0 ELSE 1 END', [$canonical])
            ->first();
        $pipSize = $this->mt5Service->resolvePipSize(
            $canonical,
            $ticker?->category,
            is_numeric($ticker?->pip_size) ? (float) $ticker->pip_size : null
        );

        $leg = $exitLegs[0] ?? [];
        $tpPrice = isset($leg['take_profit']) ? (float) $leg['take_profit'] : null;
        $slPrice = isset($leg['stop_loss']) ? (float) $leg['stop_loss'] : null;
        $tpPips = $tpPrice !== null && $pipSize > 0 ? abs($tpPrice - $entry) / $pipSize : null;
        $slPips = $slPrice !== null && $pipSize > 0 ? abs($entry - $slPrice) / $pipSize : null;

        $scaledLot = $lotSize;
        if ($settings->mt5_volume_multiplier > 1) {
            $scaledLot = $lotSize * (int) $settings->mt5_volume_multiplier;
        }

        $command = $this->eaBridge->queueCommand([
            'action' => strtolower($side) === 'buy' ? 'BUY' : 'SELL',
            'symbol' => $canonical,
            'broker_symbol' => $matchedBrokerSymbol,
            'lot' => $scaledLot,
            'sl' => $slPips,
            'tp' => $tpPips,
            'mt5_instance_key' => $this->terminal()->instance_key,
            'account_login' => $this->terminal()->account_login,
        ]);

        return [
            'mode' => 'ea_queued',
            'command_id' => $command->id,
            'status' => Mt5EaCommand::STATUS_PENDING,
            'order_qty' => $scaledLot,
            'orders' => [[
                'response' => [
                    'commandId' => $command->id,
                    'orderId' => 'ea-cmd-'.$command->id,
                ],
            ]],
        ];
    }

    public function getOpenTradeSnapshot(): array
    {
        $terminal = $this->terminal();
        $positions = is_array($terminal->positions) ? $terminal->positions : [];

        return [
            'positions' => array_map(static function (array $position): array {
                return [
                    'id' => (string) ($position['ticket'] ?? ''),
                    'symbol' => $position['symbol'] ?? null,
                    'type' => strtoupper((string) ($position['type'] ?? '')),
                    'volume' => $position['lot'] ?? $position['volume'] ?? null,
                    'openPrice' => $position['price_open'] ?? null,
                    'stopLoss' => $position['sl'] ?? null,
                    'takeProfit' => $position['tp'] ?? null,
                    'profit' => $position['profit'] ?? null,
                    'raw' => $position,
                ];
            }, $positions),
            'orders' => [],
            'fetched_at' => $terminal->last_seen_at?->toDateTimeString(),
            'source' => 'ea_bridge',
            'terminal' => $terminal->instance_key,
        ];
    }

    public function getAccountInformation(): array
    {
        $terminal = $this->terminal();

        return [
            'login' => $terminal->account_login,
            'server' => $terminal->server,
            'balance' => $terminal->balance,
            'equity' => $terminal->equity,
            'margin' => $terminal->margin,
            'marginFree' => $terminal->free_margin,
            'currency' => $terminal->currency,
            'tradeAllowed' => $terminal->trade_allowed,
            'source' => 'ea_bridge',
        ];
    }

    public function toBrokerSymbol(string $symbol): string
    {
        return $this->symbolMapper->toBrokerSymbol($this->terminal(), $symbol);
    }

    public function baseSymbol(string $symbol): string
    {
        return $this->mt5Service->baseSymbol($symbol);
    }

    public function instanceKey(): ?string
    {
        return $this->terminal()->instance_key;
    }

    public function instanceLabel(): string
    {
        return $this->terminal()->label();
    }

    private function terminal(): Mt5EaTerminal
    {
        if ($this->terminal === null) {
            $this->terminal = $this->eaBridge->resolveTerminal(null);
        }

        return $this->terminal;
    }
}
