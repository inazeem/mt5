<?php

use App\Models\AppSetting;
use App\Models\BotTradeLog;
use App\Models\Ticker;
use App\Services\AiService;
use App\Services\Mt5Service;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mt5:auto-forex
    {--lot=0.01 : Base lot size for each trade}
    {--tp-pips=25 : Take profit distance in pips}
    {--sl-pips=15 : Stop loss distance in pips}
    {--trail-start-pips=10 : Profit pips required before trailing activates}
    {--trail-pips=8 : Trailing stop distance in pips}
    {--min-move-pips=3 : Minimum move from previous tick to trigger entry}
    {--max-spread-pips=2.5 : Maximum spread allowed for entries}
    {--cooldown-minutes=30 : Cooldown per symbol after successful entry}
    {--session-start-utc : Start trading hour (UTC) - uses database setting if not specified}
    {--session-end-utc : End trading hour (UTC) - uses database setting if not specified}
    {--max-trades-per-day : Stop opening new trades after this daily count}
    {--max-daily-loss-percent=2 : Stop opening new trades when daily drawdown exceeds this percent}
    {--ai-confirm=1 : 1 requires AI approval before entry, 0 bypasses AI confirmation}
    {--ai-min-confidence=70 : Minimum AI confidence percentage (0-100) required to approve a trade}
    {--min-bot-score=70 : Minimum bot score (0-100) required to log/execute a signal}
    {--max-symbols=200 : Max symbols to scan per cycle}
    {--max-open-positions=10 : Maximum concurrent open positions (demo-safe ceiling)}
    {--max-per-cycle=5 : Maximum new trades to open in a single cycle}
    {--scalper=1 : 1 enables scalper mode (quick in/out), 0 keeps normal mode}
    {--test-mode : Bypass ALL filters and AI — trade the first --max-symbols symbols at market for testing only}
    {--once : Run one cycle only}
', function (Mt5Service $mt5Service, AiService $aiService) {
    $runCycle = function () use ($mt5Service, $aiService) {
        $db = AppSetting::singleton();

        // Use CLI option only when the flag is explicitly passed; otherwise prefer DB settings.
        $optionProvided = static function (string $name): bool {
            foreach ($_SERVER['argv'] ?? [] as $arg) {
                if ($arg === '--'.$name || str_starts_with($arg, '--'.$name.'=')) {
                    return true;
                }
            }

            return false;
        };

        $optionOrSetting = function (string $name, mixed $settingValue, mixed $fallbackDefault) use ($optionProvided) {
            if ($optionProvided($name)) {
                return $this->option($name);
            }

            return $settingValue ?? $fallbackDefault;
        };

        $lotSize           = (float) $optionOrSetting('lot', $db->bot_lot ?? null, 0.01);
        $tpPips            = (float) $optionOrSetting('tp-pips', $db->bot_tp_pips ?? null, 25);
        $slPips            = (float) $optionOrSetting('sl-pips', $db->bot_sl_pips ?? null, 15);
        $trailStartPips    = (float) $optionOrSetting('trail-start-pips', $db->bot_trail_start_pips ?? null, 10);
        $trailPips         = (float) $optionOrSetting('trail-pips', $db->bot_trail_pips ?? null, 8);
        $minMovePips       = (float) $optionOrSetting('min-move-pips', $db->bot_min_move_pips ?? null, 3);
        $maxSpreadPips     = (float) $optionOrSetting('max-spread-pips', $db->bot_max_spread_pips ?? null, 2.5);
        $cooldownMinutes   = max(0, (int) $optionOrSetting('cooldown-minutes', $db->bot_cooldown_minutes ?? null, 30));
        $sessionStartUtc   = (int) $optionOrSetting('session-start-utc', $db->bot_session_start_utc ?? null, 6);
        $sessionEndUtc     = (int) $optionOrSetting('session-end-utc', $db->bot_session_end_utc ?? null, 20);
        $maxTradesPerDay   = max(1, (int) $optionOrSetting('max-trades-per-day', $db->bot_max_trades_per_day ?? null, 20));
        $maxDailyLossPercent = (float) $optionOrSetting('max-daily-loss-percent', $db->bot_max_daily_loss_percent ?? null, 2);
        $useAiConfirm      = (string) $this->option('ai-confirm') !== '0' && ($db->bot_ai_confirm ?? true);
        $aiMinConfidence   = (int) $optionOrSetting('ai-min-confidence', $db->bot_ai_min_confidence ?? null, 70);
        $minBotScore       = max(0, min(100, (int) $this->option('min-bot-score')));
        $maxSymbols        = max(1, (int) $optionOrSetting('max-symbols', $db->bot_max_symbols ?? null, 200));
        $scalperMode          = (string) $this->option('scalper') !== '0';
        $maxOpenPositions     = max(1, (int) $optionOrSetting('max-open-positions', $db->bot_max_open_positions ?? null, 10));
        $maxPerCycle          = max(1, (int) $optionOrSetting('max-per-cycle', $db->bot_max_per_cycle ?? null, 5));
        $testMode             = (bool) $this->option('test-mode');

        if ($scalperMode) {
            // Scalper defaults: 1:3 R:R (SL=10pip, TP=30pip).
            $tpPips = min($tpPips, 30.0);
            $slPips = min($slPips, 10.0);
            $trailStartPips = min($trailStartPips, 15.0);
            $trailPips = min($trailPips, 8.0);
            $minMovePips = min($minMovePips, 1.5);
            $maxSpreadPips = min($maxSpreadPips, 5.0);
            $cooldownMinutes = min($cooldownMinutes, 5);
        }

        if (
            $lotSize <= 0 ||
            $tpPips <= 0 ||
            $slPips <= 0 ||
            $trailStartPips <= 0 ||
            $trailPips <= 0 ||
            $minMovePips <= 0 ||
            $maxSpreadPips <= 0 ||
            $maxDailyLossPercent <= 0
        ) {
            $this->error('All numeric options must be greater than zero.');
            return 1;
        }

        if ($sessionStartUtc < 0 || $sessionStartUtc > 23 || $sessionEndUtc < 0 || $sessionEndUtc > 23) {
            $this->error('Session hours must be in UTC range 0..23.');
            return 1;
        }

        if ($testMode) {
            $this->warn('TEST MODE: all filters, guardrails, and AI confirmation are disabled.');
        }

        if ($scalperMode) {
            $this->line('Scalper mode ON  TP='.$tpPips.'pip  SL='.$slPips.'pip  maxSpread='.$maxSpreadPips.'pip  cooldown='.$cooldownMinutes.'min');
        }

        // Force UTC timezone to ensure correct time comparison
        date_default_timezone_set('UTC');
        $currentHourUtc = (int) \Carbon\Carbon::now('UTC')->format('G');
        $inSession = $sessionStartUtc <= $sessionEndUtc
            ? ($currentHourUtc >= $sessionStartUtc && $currentHourUtc <= $sessionEndUtc)
            : ($currentHourUtc >= $sessionStartUtc || $currentHourUtc <= $sessionEndUtc);

        if (!$testMode && !$inSession) {
            $msg = "Skipped cycle: outside trading session ({$sessionStartUtc}:00-{$sessionEndUtc}:59 UTC). Current UTC hour: {$currentHourUtc}.";
            $this->warn($msg);
            BotTradeLog::query()->create([
                'event_type' => 'guardrail',
                'status' => 'session_block',
                'message' => $msg,
            ]);
            return 0;
        }

        $todayStart = now()->startOfDay();
        $openedToday = BotTradeLog::query()
            ->where('event_type', 'trade_open')
            ->where('status', 'success')
            ->where('created_at', '>=', $todayStart)
            ->count();

        if (!$testMode && $openedToday >= $maxTradesPerDay) {
            $msg = "Skipped entries: daily max trades reached ({$openedToday}/{$maxTradesPerDay}).";
            $this->warn($msg);
            BotTradeLog::query()->create([
                'event_type' => 'guardrail',
                'status' => 'daily_trade_limit',
                'message' => $msg,
            ]);
            return 0;
        }

        if ($testMode) {
            $this->line('TEST MODE: skipping daily loss guard.');
        }

        try {
            $accountInfo = $mt5Service->getAccountInformation();
            $equity = (float) ($accountInfo['equity'] ?? $accountInfo['balance'] ?? 0);
            $baselineKey = 'auto_bot_day_start_equity_'.now()->format('Ymd');
            $dayStartEquity = Cache::get($baselineKey);
            if (!is_numeric($dayStartEquity) && $equity > 0) {
                Cache::put($baselineKey, $equity, now()->addDays(2));
                $dayStartEquity = $equity;
            }

            if (is_numeric($dayStartEquity) && (float) $dayStartEquity > 0 && $equity > 0) {
                $drawdownPercent = (((float) $dayStartEquity - $equity) / (float) $dayStartEquity) * 100;
                if (!$testMode && $drawdownPercent >= $maxDailyLossPercent) {
                    $msg = 'Skipped entries: daily loss guard triggered at '.number_format($drawdownPercent, 2).'%. ';
                    $msg .= 'Threshold is '.number_format($maxDailyLossPercent, 2).'%. '; 
                    $this->warn($msg);
                    BotTradeLog::query()->create([
                        'event_type' => 'guardrail',
                        'status' => 'daily_loss_limit',
                        'message' => $msg,
                    ]);
                    return 0;
                }
            }
        } catch (\Throwable $e) {
            $this->warn('Account info unavailable, skipping daily loss guard: '.$e->getMessage());
        }

        $this->info('Running trailing stop updates...');
        $trailResult = $mt5Service->applyTrailingStops($trailStartPips, $trailPips);
        $this->line('Trailing updated: '.$trailResult['updated'].', skipped: '.$trailResult['skipped']);
        if ($trailResult['updated'] > 0) {
            BotTradeLog::query()->create([
                'event_type' => 'trailing_update',
                'status' => 'success',
                'message' => 'Trailing stop updated on '.$trailResult['updated'].' positions.',
            ]);
        }

        if (!empty($trailResult['errors'])) {
            foreach ($trailResult['errors'] as $error) {
                $this->warn('Trailing error '.$error['symbol'].' #'.$error['position_id'].': '.$error['error']);
                BotTradeLog::query()->create([
                    'event_type' => 'trailing_update',
                    'status' => 'failed',
                    'symbol' => $error['symbol'] ?? null,
                    'message' => 'Trailing stop update failed.',
                    'error_message' => $error['error'] ?? null,
                ]);
            }
        }

        $openSnapshot = $mt5Service->getOpenTradeSnapshot();
        $positions = is_array($openSnapshot['positions'] ?? null) ? $openSnapshot['positions'] : [];
        if (!$testMode && count($positions) >= $maxOpenPositions) {
            $msg = 'Skipped entries: max open positions reached ('.count($positions).'/'.$maxOpenPositions.').';
            $this->line($msg);
            BotTradeLog::query()->create([
                'event_type' => 'guardrail',
                'status' => 'max_open_positions',
                'message' => $msg,
            ]);

            return 0;
        }

        $openBySymbol = [];
        foreach ($positions as $position) {
            if (is_array($position) && !empty($position['symbol'])) {
                $sym = strtoupper((string) $position['symbol']);
                $openBySymbol[$sym] = true;
                // Also index by base symbol so a plain symbol like "EURUSD" matches
                // a broker-suffixed open position like "EURUSD.a".
                $openBySymbol[$mt5Service->baseSymbol($sym)] = true;
            }
        }

        // Prefer active tickers from the database; fall back to MetaAPI symbol discovery.
        $dbTickers = Ticker::query()->active()->orderBy('symbol')->get()->keyBy(fn ($t) => strtoupper($t->symbol));
        if ($dbTickers->isNotEmpty()) {
            $symbols = array_slice($dbTickers->keys()->all(), 0, $maxSymbols);
            $this->line('Using '.count($symbols).' symbol(s) from tickers table.');
        } else {
            $symbols = array_slice($mt5Service->getForexSymbols(), 0, $maxSymbols);
            $dbTickers = collect();
            $this->line('No tickers in DB — discovered '.count($symbols).' symbol(s) from MetaAPI.');
        }
        $this->line('Scanning '.count($symbols).' symbols. Open positions: '.count($positions).'.');
        $opened = 0;
        $scanned = 0;
        $skippedNoMove = 0;
        $skippedSpread = 0;
        $skippedCooldown = 0;
        $skippedOpen = 0;
        $skippedLowScore = 0;

        $calculateBotScore = static function (float $signalDeltaPips, float $spreadPips): int {
            $signalStrengthScore = min(100.0, (abs($signalDeltaPips) / 10.0) * 100.0);
            $spreadScore = max(0.0, min(100.0, (1 - ($spreadPips / 3.0)) * 100.0));

            return (int) round(($signalStrengthScore * 0.7) + ($spreadScore * 0.3));
        };

        $extractConfidence = static function (?string $summary): ?int {
            if (!is_string($summary) || trim($summary) === '') {
                return null;
            }

            if (preg_match('/confidence[:\s]+(\d{1,3})\s*%/i', $summary, $m)) {
                return max(0, min(100, (int) $m[1]));
            }
            if (preg_match('/(\d{1,3})\s*%\s+confidence/i', $summary, $m)) {
                return max(0, min(100, (int) $m[1]));
            }
            if (preg_match('/(\d{1,3})\s*%/i', $summary, $m)) {
                return max(0, min(100, (int) $m[1]));
            }

            return null;
        };

        $logSignal = static function (array $data) use ($calculateBotScore, $minBotScore, $testMode): void {
            $payload = is_array($data['meta_payload'] ?? null) ? $data['meta_payload'] : [];

            $resolvedBotScore = null;
            if (is_numeric($payload['bot_score'] ?? null)) {
                $resolvedBotScore = (int) $payload['bot_score'];
            } elseif (is_numeric($data['signal_delta_pips'] ?? null) && is_numeric($data['spread_pips'] ?? null)) {
                $resolvedBotScore = $calculateBotScore((float) $data['signal_delta_pips'], (float) $data['spread_pips']);
            } else {
                $resolvedBotScore = 0;
            }

            if (!$testMode && $resolvedBotScore < $minBotScore) {
                return;
            }

            $payload['bot_score'] = $resolvedBotScore;
            $payload['min_bot_score'] = $minBotScore;

            BotTradeLog::query()->create(array_merge([
                'event_type' => 'signal',
                'ai_decision' => 'not_evaluated',
                'meta_payload' => $payload,
            ], $data));
        };

        foreach ($symbols as $symbol) {
            $symbol = strtoupper((string) $symbol);
            $scanned++;

            try {
                $quote = $mt5Service->getTickerPrice($symbol);
            } catch (\Throwable $e) {
                Log::warning('Auto bot quote failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
                $this->line("  {$symbol}: quote error — {$e->getMessage()}");
                $logSignal([
                    'status' => 'quote_error',
                    'symbol' => $symbol,
                    'message' => 'Signal skipped due to quote retrieval failure.',
                    'error_message' => $e->getMessage(),
                ]);
                continue;
            }

            $bid = isset($quote['bid']) ? (float) $quote['bid'] : 0.0;
            $ask = isset($quote['ask']) ? (float) $quote['ask'] : 0.0;
            if ($bid <= 0 || $ask <= 0) {
                $logSignal([
                    'status' => 'invalid_quote',
                    'symbol' => $symbol,
                    'message' => 'Signal skipped due to invalid bid/ask quote values.',
                ]);
                continue;
            }

            $base = substr($symbol, 0, 6);
            $pipSize = $dbTickers->get($symbol)?->pip_size
                ?? (str_ends_with($base, 'JPY') ? 0.01 : 0.0001);
            $minMove = $minMovePips * $pipSize;

            $cacheKey = 'auto_bot_last_bid_'.preg_replace('/[^A-Z0-9_]/', '_', $symbol);
            $lastBid = Cache::get($cacheKey);
            Cache::put($cacheKey, $bid, now()->addHours(6));

            if (!$testMode) {
                if (!is_numeric($lastBid)) {
                    $skippedNoMove++;
                    $logSignal([
                        'status' => 'no_move_rejected',
                        'symbol' => $symbol,
                        'message' => 'Signal skipped because no prior tick was available yet.',
                    ]);
                    continue;
                }

                $delta = $bid - (float) $lastBid;
                if (abs($delta) < $minMove) {
                    $skippedNoMove++;
                    $signalDeltaPips = $pipSize > 0 ? ($delta / $pipSize) : null;
                    $spreadPips = $pipSize > 0 ? (($ask - $bid) / $pipSize) : null;
                    $logSignal([
                        'status' => 'no_move_rejected',
                        'symbol' => $symbol,
                        'side' => $delta >= 0 ? 'buy' : 'sell',
                        'signal_delta_pips' => $signalDeltaPips,
                        'spread_pips' => $spreadPips,
                        'message' => 'Signal rejected because price move is below minimum threshold.',
                    ]);
                    continue;
                }
            }

            $delta = isset($lastBid) && is_numeric($lastBid) ? ($bid - (float) $lastBid) : 0;
            $side = $delta >= 0 ? 'buy' : 'sell';
            $signalDeltaPips = $pipSize > 0 ? ($delta / $pipSize) : 0;
            $spreadPips = ($ask - $bid) / $pipSize;
            $botScore = $calculateBotScore($signalDeltaPips, $spreadPips);

            if (isset($openBySymbol[$symbol])) {
                $skippedOpen++;
                if ($testMode || $botScore >= $minBotScore) {
                    $logSignal([
                        'status' => 'open_position_rejected',
                        'symbol' => $symbol,
                        'side' => $side,
                        'spread_pips' => $spreadPips,
                        'signal_delta_pips' => $signalDeltaPips,
                        'meta_payload' => ['bot_score' => $botScore, 'min_bot_score' => $minBotScore],
                        'message' => 'Signal skipped because symbol already has an open position.',
                    ]);
                }
                continue;
            }

            if (!$testMode && $botScore < $minBotScore) {
                $this->line("  {$symbol}: SCORE {$botScore}% < min {$minBotScore}% — skipped");
                $skippedLowScore++;
                continue;
            }

            if (!$testMode && $spreadPips > $maxSpreadPips) {
                $this->line("  {$symbol}: SPREAD {$spreadPips}pip > max {$maxSpreadPips}pip — skipped");
                $skippedSpread++;
                $logSignal([
                    'status' => 'spread_rejected',
                    'symbol' => $symbol,
                    'side' => $side,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'message' => 'Signal rejected due to spread filter.',
                ]);
                continue;
            }

            $lastSuccessfulTrade = BotTradeLog::query()
                ->where('event_type', 'trade_open')
                ->where('status', 'success')
                ->where('symbol', $symbol)
                ->latest('created_at')
                ->first();

            if (!$testMode && $lastSuccessfulTrade && $cooldownMinutes > 0 && $lastSuccessfulTrade->created_at?->gt(now()->subMinutes($cooldownMinutes))) {
                $remaining = now()->diffInSeconds($lastSuccessfulTrade->created_at->addMinutes($cooldownMinutes));
                $this->line("  {$symbol}: COOLDOWN — {$remaining}s remaining");
                $skippedCooldown++;
                $logSignal([
                    'status' => 'cooldown_rejected',
                    'symbol' => $symbol,
                    'side' => $side,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'message' => 'Signal rejected due to symbol cooldown.',
                ]);
                continue;
            }

            $this->line("  {$symbol}: signal {$side} — move=".number_format($signalDeltaPips,2)."pip spread=".number_format($spreadPips,2)."pip bid={$bid} ask={$ask}");

            if ($side === 'buy') {
                $entry = $ask;
                $takeProfit = round($entry + ($tpPips * $pipSize), 5);
                $stopLoss = round($entry - ($slPips * $pipSize), 5);
            } else {
                $entry = $bid;
                $takeProfit = round($entry - ($tpPips * $pipSize), 5);
                $stopLoss = round($entry + ($slPips * $pipSize), 5);
            }

            $aiProvider = null;
            $aiDecision = 'approve';
            $aiConfidence = null;
            $aiSummary = null;

            if (!$testMode && $useAiConfirm) {
                try {
                    // Fetch candle context from MetaAPI for richer AI analysis.
                    $candleContext = '';
                    try {
                        $candles1h = $mt5Service->getCandles($symbol, '1h', 20);
                        $candles15m = $mt5Service->getCandles($symbol, '15m', 10);

                        $formatCandles = static function (array $candles): string {
                            $lines = [];
                            foreach ($candles as $c) {
                                if (!is_array($c)) {
                                    continue;
                                }
                                $time = $c['time'] ?? $c['brokerTime'] ?? '?';
                                $o = isset($c['open']) ? number_format((float) $c['open'], 5) : '?';
                                $h = isset($c['high']) ? number_format((float) $c['high'], 5) : '?';
                                $l = isset($c['low']) ? number_format((float) $c['low'], 5) : '?';
                                $cl = isset($c['close']) ? number_format((float) $c['close'], 5) : '?';
                                $vol = isset($c['tickVolume']) ? (int) $c['tickVolume'] : '?';
                                $lines[] = "  {$time}: O={$o} H={$h} L={$l} C={$cl} Vol={$vol}";
                            }
                            return implode("\n", $lines);
                        };

                        if (!empty($candles1h)) {
                            $candleContext .= "\n\nLast ".count($candles1h)." x 1H candles (oldest first):\n".$formatCandles($candles1h);
                        }
                        if (!empty($candles15m)) {
                            $candleContext .= "\n\nLast ".count($candles15m)." x 15M candles (oldest first):\n".$formatCandles($candles15m);
                        }
                    } catch (\Throwable $candleError) {
                        Log::warning('Auto bot candle fetch failed', ['symbol' => $symbol, 'error' => $candleError->getMessage()]);
                    }

                    $this->line("  {$symbol}: asking AI ({$symbol} {$side} entry={$entry} TP={$takeProfit} SL={$stopLoss})...");

                    $strategyLine = $scalperMode
                        ? 'This is a scalping trade. Prefer quick in-and-out setups and reject slow/unclear setups.'
                        : 'This is a standard intraday trade.';

                    $prompt = "You are validating an automated forex trade. Reply strictly with one line starting with APPROVE or REJECT, then a short reason. "
                        ."Include a confidence percentage like 'Confidence: 85%' in your reply. "
                        .$strategyLine.' '
                        ."Symbol: {$symbol}. Side: {$side}. Entry: {$entry}. TP: {$takeProfit}. SL: {$stopLoss}. "
                        ."Spread pips: ".number_format($spreadPips, 2).". Signal move pips: ".number_format($signalDeltaPips, 2)."."
                        .$candleContext;
                    $aiResult = $aiService->ask($prompt);
                    $aiProvider = $aiResult['provider'] ?? null;
                    $aiSummary = trim((string) ($aiResult['answer'] ?? ''));
                    $aiConfidence = $extractConfidence($aiSummary);

                    $firstToken = strtoupper((string) strtok($aiSummary, " \n\t"));
                    if (!str_contains($firstToken, 'APPROVE')) {
                        $aiDecision = 'reject';
                    } elseif ($aiMinConfidence > 0) {
                        if ($aiConfidence !== null && $aiConfidence < $aiMinConfidence) {
                            $aiDecision = 'low_confidence';
                            $aiSummary .= " [Confidence {$aiConfidence}% below threshold {$aiMinConfidence}%]";
                        }
                    }
                    $this->line("  {$symbol}: AI => {$aiDecision} — {$aiSummary}");
                } catch (\Throwable $e) {
                    $aiDecision = 'reject';
                    $aiConfidence = null;
                    $aiSummary = 'AI confirmation unavailable: '.$e->getMessage();
                    $this->warn("  {$symbol}: AI error — {$e->getMessage()}");
                }
            }

            if ($aiDecision !== 'approve') {
                $logSignal([
                    'status' => 'ai_rejected',
                    'symbol' => $symbol,
                    'side' => $side,
                    'lot_size' => $lotSize,
                    'entry_price' => $entry,
                    'take_profit' => $takeProfit,
                    'stop_loss' => $stopLoss,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'ai_provider' => $aiProvider,
                    'ai_decision' => $aiDecision,
                    'ai_confidence' => $aiConfidence,
                    'ai_summary' => $aiSummary,
                    'meta_payload' => ['bot_score' => $botScore, 'min_bot_score' => $minBotScore],
                    'message' => 'Signal rejected by AI confirmation.',
                ]);
                continue;
            }

            $logSignal([
                'status' => 'confirmed',
                'symbol' => $symbol,
                'side' => $side,
                'lot_size' => $lotSize,
                'entry_price' => $entry,
                'take_profit' => $takeProfit,
                'stop_loss' => $stopLoss,
                'spread_pips' => $spreadPips,
                'signal_delta_pips' => $signalDeltaPips,
                'ai_provider' => $aiProvider,
                'ai_decision' => $aiDecision,
                'ai_confidence' => $aiConfidence,
                'ai_summary' => $aiSummary,
                'meta_payload' => ['bot_score' => $botScore, 'min_bot_score' => $minBotScore],
                'message' => 'Signal passed all filters and AI confirmation.',
            ]);

            try {
                $result = $mt5Service->placeOrder($symbol, $lotSize, $side, [[
                    'close_percent' => 100,
                    'take_profit' => $takeProfit,
                    'stop_loss' => $stopLoss,
                ]]);
                $opened++;
                $openBySymbol[$symbol] = true;
                $this->info('Opened '.$side.' '.$symbol.' TP='.$takeProfit.' SL='.$stopLoss);
                Log::info('Auto bot opened trade', ['symbol' => $symbol, 'side' => $side, 'result' => $result]);
                BotTradeLog::query()->create([
                    'event_type' => 'trade_open',
                    'status' => 'success',
                    'symbol' => $symbol,
                    'side' => $side,
                    'lot_size' => $lotSize,
                    'entry_price' => $entry,
                    'take_profit' => $takeProfit,
                    'stop_loss' => $stopLoss,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'ai_provider' => $aiProvider,
                    'ai_decision' => $aiDecision,
                    'ai_confidence' => $aiConfidence,
                    'ai_summary' => $aiSummary,
                    'meta_payload' => ['bot_score' => $botScore, 'min_bot_score' => $minBotScore],
                    'message' => 'Trade opened successfully.',
                    'meta_response' => $result,
                ]);

                if ($opened >= $maxPerCycle) {
                    $this->line('Per-cycle trade limit reached ('.$maxPerCycle.'). Waiting for next cycle.');
                    break;
                }
            } catch (\Throwable $e) {
                Log::warning('Auto bot trade failed', ['symbol' => $symbol, 'side' => $side, 'error' => $e->getMessage()]);
                BotTradeLog::query()->create([
                    'event_type' => 'trade_open',
                    'status' => 'failed',
                    'symbol' => $symbol,
                    'side' => $side,
                    'lot_size' => $lotSize,
                    'entry_price' => $entry,
                    'take_profit' => $takeProfit,
                    'stop_loss' => $stopLoss,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'ai_provider' => $aiProvider,
                    'ai_decision' => $aiDecision,
                    'ai_confidence' => $aiConfidence,
                    'ai_summary' => $aiSummary,
                    'meta_payload' => ['bot_score' => $botScore, 'min_bot_score' => $minBotScore],
                    'message' => 'Trade open failed.',
                    'error_message' => $e->getMessage(),
                ]);
            }
        }

        $this->info(
            'Cycle complete. Scanned='.$scanned
            .' opened='.$opened
            .' noMove='.$skippedNoMove
            .' spread='.$skippedSpread
            .' lowScore='.$skippedLowScore
            .' cooldown='.$skippedCooldown
            .' hasOpen='.$skippedOpen
        );

        return 0;
    };

    if ($this->option('once')) {
        return $runCycle();
    }

    while (true) {
        $code = $runCycle();
        if ($code !== 0) {
            return $code;
        }

        $this->line('Waiting 60 seconds for next cycle...');
        sleep(60);
    }
})->purpose('Run automated forex trading with TP/SL and trailing stop on MetaApi');

Schedule::command('mt5:auto-forex --once')->everyMinute()->withoutOverlapping();
