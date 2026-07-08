<?php

use App\Models\AppSetting;
use App\Models\BotTradeLog;
use App\Models\Mt5EaCommand;
use App\Models\Ticker;
use App\Services\AiService;
use App\Services\AlpacaService;
use App\Services\BotScoreCalculator;
use App\Services\Brokers\BrokerResolver;
use App\Services\Brokers\EaBridgeBroker;
use App\Services\EaBridgeService;
use App\Services\Mt5Service;
use App\Services\TradingStrategies\IndicatorMath;
use App\Services\TradingStrategies\StrategyFactory;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/**
 * routes/console.php — Bot console commands (full reference).
 *
 * Markdown copy (tables/diagrams): routes/console.md
 * Ideal production setup: IDEAL_BOT_SETUP.md
 * Mt5Service split: app/Services/console-vs-mt5service.md
 *
 * ─── ARCHITECTURE ───────────────────────────────────────────────────────────
 * This file is the bot brain (when/what/whether to trade). Broker I/O lives in
 * Mt5Service; strategy math in TradingStrategies/*; AI in AiService; logs in BotTradeLog.
 *
 * Flow: Schedule/CLI → mt5:auto-forex → resolve settings → guardrails → trailing →
 * symbol list → per-symbol quote/strategy/filters/AI → placeOrder → BotTradeLog.
 *
 * ─── COMMANDS ───────────────────────────────────────────────────────────────
 * inspire              — Laravel demo quote.
 * mt5:auto-forex       — Main automated trading loop (see below).
 * mt5:learn-policy     — Analyze history; recommend/apply min_bot_score, hours, symbols.
 *
 * Scheduler (bottom of file):
 *   mt5:auto-forex --once every minute, withoutOverlapping(120).
 * Server cron: * * * * * php artisan schedule:run
 *
 * ─── mt5:auto-forex STRUCTURE ────────────────────────────────────────────────
 * $resolveProfiles()  — Load app_settings.bot_profiles; normalize key/name; filter --bot.
 * $runCycle($profile) — One full scan/trade cycle for a single profile.
 * $runAllBots()       — Run $runCycle for each enabled profile.
 *
 * Run modes:
 *   --once     Single cycle for all profiles, then exit.
 *   (default)  Loop forever: run all profiles, sleep 60s, repeat.
 *
 * ─── CONFIG PRECEDENCE ($optionOrProfileOrSetting) ───────────────────────────
 * 1. CLI flag ONLY if explicitly on argv ($optionProvided scans $_SERVER['argv']).
 * 2. Bot profile JSON field (bot_profiles[].*).
 * 3. AppSetting DB column (bot_*).
 * 4. Hard-coded fallback in this file.
 *
 * ─── BOT PROFILES ─────────────────────────────────────────────────────────────
 * Stored in app_settings.bot_profiles. key = slug from key/name; enabled=false skipped.
 * Empty profiles → synthetic default/Default Bot. --bot= filters by key or name.
 * Every log row includes bot_key + bot_name from $botLogDefaults.
 *
 * Common profile keys: lot, tp_pips, sl_pips, trail_*, min_move_pips, max_spread_pips,
 * cooldown_minutes, session_*_utc, max_trades_per_day, max_trades_per_asset_per_day,
 * max_daily_loss_percent, ai_confirm, ai_min_confidence, max_symbols, max_open_positions,
 * max_per_cycle, min_bot_score, scalper, strategies, strategy_params, symbols,
 * signal_timeframes, entry_timeframe, trend_filter, reverse_strategy,
 * cooldown_override_ratio, preferred/blocked_hours_utc, preferred_symbols,
 * ticker_categories, *_by_category maps.
 *
 * ─── CLI OPTIONS (defaults shown; all overridable via profile/DB) ────────────
 * Trade sizing: --lot=0.01 --tp-pips=25 --sl-pips=15 --trail-start-pips=10
 *   --trail-pips=8 --trail-tp-multiplier=2 --*-by-category=forex:25,stock:160,...
 * Entry filters: --min-move-pips=3 --max-spread-pips=2.5 --cooldown-minutes=30
 *   --cooldown-override-ratio=0 (bypass cooldown when move ≥ last×ratio)
 * Session/limits: --session-start-utc=6 --session-end-utc=20 --max-trades-per-day=20
 *   --max-trades-per-asset-per-day=2 --max-daily-loss-percent=2
 * AI/scoring: --ai-confirm=1 --ai-min-confidence=70 --min-bot-score=70
 * Scanning: --max-symbols=200 --scan-delay-ms=0 --max-cycle-credits=900
 *   --max-open-positions=10 --max-per-cycle=5
 * Strategy: --strategies=momentum,sma_cross,... --scalper=1 --reverse-strategy=0
 *   --trend-filter=0 --signal-timeframes=15m --entry-timeframe=
 * Filters: --preferred-hours-utc= --blocked-hours-utc=15 --preferred-symbols=
 * Control: --bot= --test-mode (bypass ALL safety) --once
 *
 * ─── INTERNAL HELPERS (inside $runCycle) ──────────────────────────────────────
 * normalizeHourList, normalizeSymbolList, normalizeTimeframeList, normalizeStrategyList,
 * normalizeStrategyParams, normalizeCategoryNumericMap, classifySpreadCategory,
 * categoryValueOrDefault, calculateBotScore, extractConfidence, resolveTrendSide,
 * logSignal, reserveCycleCredits, isMetaApiOutageError, resolveTrailingParamsForSymbol.
 *
 * ─── INSTRUMENT CATEGORIES (classifySpreadCategory) ─────────────────────────
 * forex | stock | commodity | other — drives default TP/SL/spread/trail/min-move maps.
 * Ticker overrides (tickers table): pip_size, max_spread_pips, max_tp_pips, max_sl_pips.
 * Scalper mode caps TP/SL/spread/cooldown tighter (e.g. TP≤30, SL≤10, cooldown≤5min).
 *
 * ─── CYCLE LIFECYCLE ($runCycle) ─────────────────────────────────────────────
 * 1. Resolve settings + validate numerics
 * 2. Print diagnostics (categories, strategies, volume, credits)
 * 3. PRE-SCAN GUARDRAILS (§11)
 * 4. Trailing stops on open positions (§12)
 * 5. Fetch open positions; abort if max open (§11)
 * 6. Build symbol list (§13)
 * 7. FOR EACH SYMBOL → pipeline (§14–§20)
 * 8. Log guardrail/cycle_complete with counters (§25)
 *
 * All session/hour checks use UTC (date_default_timezone_set('UTC')).
 *
 * ─── §11 PRE-SCAN GUARDRAILS (skip entire cycle) ──────────────────────────────
 * session_block        Outside session-start/end-utc (overnight sessions supported).
 * hour_block           Current hour in blocked-hours-utc (default: 15 UTC).
 * hour_not_preferred   preferred-hours-utc set and hour not in list.
 * daily_trade_limit    openedToday >= max-trades-per-day (successful trade_open today).
 * daily_loss_limit     Drawdown from day-start equity >= max-daily-loss-percent.
 *                      Cache: auto_bot_day_start_equity_{bot_key}_{Ymd}
 * max_open_positions   Broker open count >= max-open-positions.
 * symbol_filter_block  No symbols after preferred/category filters.
 * Test mode skips: session, hours, daily trade/loss, most per-symbol filters.
 *
 * Per-symbol daily count: openedTodayBySymbol from successful trade_open today;
 * enforced in scan as asset_daily_limit_rejected (default max 2 per symbol).
 *
 * ─── §12 TRAILING STOPS ───────────────────────────────────────────────────────
 * Mt5Service::applyTrailingStops() before scan; category params via resolveTrailingParamsForSymbol.
 * Logs trailing_update success/failed. Post-open immediate trailing pass after each new trade.
 *
 * ─── §13 SYMBOL UNIVERSE ──────────────────────────────────────────────────────
 * Priority: profile.symbols[] → Ticker::active() → Mt5Service::getForexSymbols().
 * Then: preferred-symbols intersect → ticker_categories filter → round-robin window.
 * Round-robin cache: auto_bot_symbol_cursor_{bot_key} (7-day TTL).
 * openBySymbol indexes broker symbol + baseSymbol() for suffix matching.
 *
 * ─── §14 PER-SYMBOL PIPELINE ──────────────────────────────────────────────────
 * MetaAPI pause check → scan-delay → reserve quote credits → getTickerPrice
 * → spread/pip/category limits → strategy candles → strategy consensus (§15)
 * → reverse-strategy flip (§16) → bot score (§17) → volume/open/spread checks
 * → asset daily limit → cooldown → trend filter (§18) → TP/SL → AI (§19) → placeOrder (§20)
 *
 * Quote fetch: EA bridge uses per-terminal symbol mapping; MetaAPI 6-letter pairs use _SB suffix.
 * Last bid cache: auto_bot_last_bid_{bot_key}_{symbol} (6 hours).
 *
 * ─── §15 STRATEGY EVALUATION ──────────────────────────────────────────────────
 * Strategies: momentum, sma_cross, ema_cross, bollinger_reversion, vwap_reversion.
 * ALL selected strategies must signal; same side required or strategy_conflict_rejected.
 * Statuses: strategy_rejected, strategy_invalid_side, strategy_data_error.
 * Test mode: side from bid vs last bid; skips strategy classes.
 *
 * ─── §16 REVERSE STRATEGY / EXECUTION SIDE ────────────────────────────────────
 * recommendedSide = consensus side from strategies (+ trend/AI gates).
 * reverse-strategy=0 (default): executionSide = recommendedSide (trade with consensus).
 * reverse-strategy=1: executionSide is inverted (fade / contrarian test mode).
 *
 * ─── §17 BOT SCORE ────────────────────────────────────────────────────────────
 * 60% signal strength (|delta|/10 pips), 25% spread (1-spread/3), 15% volume ratio.
 * Execute when score >= min-bot-score. Log signal when score >= max(70, min-bot-score).
 *
 * ─── §18 TREND FILTER ─────────────────────────────────────────────────────────
 * When trend-filter=1: context timeframes (signal TFs minus entry TF) must align with side;
 * entry timeframe candle must also align. trend_rejected | entry_timeframe_wait.
 *
 * ─── §19 AI CONFIRMATION ──────────────────────────────────────────────────────
 * ai-confirm=1: AiService prompt with trade details + optional candle history.
 * Must start with APPROVE and meet ai-min-confidence. ai_rejected on fail/error.
 *
 * ─── §20 TRADE EXECUTION ──────────────────────────────────────────────────────
 * Mt5Service::placeOrder(symbol, lot, executionSide, [[close_percent, tp, sl]]).
 * Success: trade_open/success, trade_outcome=PENDING, increments openedTodayBySymbol.
 * Failure: trade_open/failed, trade_outcome=FAILED. Stop scan when opened >= max-per-cycle.
 *
 * ─── §21 BotTradeLog event_type / status ──────────────────────────────────────
 * event_type: guardrail | trailing_update | signal | trade_open
 * Signal statuses: quote_error, invalid_quote, strategy_*, spread_rejected,
 *   asset_daily_limit_rejected, cooldown_rejected, trend_rejected, entry_timeframe_wait,
 *   ai_rejected, confirmed, open_position_rejected, low_volume_rejected, ...
 * Guardrail statuses: session_block, hour_block, daily_trade_limit, daily_loss_limit,
 *   max_open_positions, credit_budget_stop, metaapi_cooldown_skip, cycle_complete,
 *   policy_recommendation, ...
 *
 * ─── §22 CACHE KEYS ───────────────────────────────────────────────────────────
 * auto_bot_day_start_equity_{bot_key}_{Ymd}     — daily loss baseline (2 days)
 * auto_bot_symbol_cursor_{bot_key}              — round-robin offset (7 days)
 * auto_bot_last_bid_{bot_key}_{symbol}          — momentum reference (6 hours)
 * metaapi_outage_pause_until                    — pause scans after outage (3 min)
 *
 * ─── §23 METAAPI CREDIT BUDGET ────────────────────────────────────────────────
 * Quote=50 credits, candle=50. reserveCycleCredits(); credit_budget_stop breaks loop.
 * max-cycle-credits=0 disables guard (still tracks usage in cycle_complete).
 *
 * ─── §24 OUTAGE / RATE LIMIT ──────────────────────────────────────────────────
 * 429/TOOMANYREQUESTS → stop scan cycle (stoppedByRateLimit).
 * Timeout/504/not connected → metaapi_outage_pause_until + break loop.
 *
 * ─── §25 CYCLE SUMMARY ────────────────────────────────────────────────────────
 * Console + guardrail/cycle_complete meta: scanned, opened, noMove, spread, lowScore,
 * lowVolume, cooldown, assetDailyLimit, hasOpen, rateLimitStop, creditStop, creditUsed.
 *
 * ─── §26 mt5:learn-policy ─────────────────────────────────────────────────────
 * Options: --bot --lookback-days=30 --min-resolved=20 --apply
 * Matches confirmed signals → trade_open → MetaAPI closing deals for P/L.
 * Recommends min_bot_score, preferred/blocked_hours_utc, preferred_symbols.
 * --apply merges into bot_profiles when resolved count >= min-resolved.
 *
 * ─── FAQ ──────────────────────────────────────────────────────────────────────
 * Why sell when strategy says buy? reverse-strategy=1 was enabled (contrarian mode).
 * 2 trades/asset/day? max-trades-per-asset-per-day=2 + openedTodayBySymbol check.
 * WIN/LOSS resolution? BotController reconciliation (not this file).
 * New filter? Add before placeOrder in $runCycle; log via $logSignal or guardrail.
 *
 * ─── EXAMPLES ─────────────────────────────────────────────────────────────────
 * php artisan mt5:auto-forex --once --bot=scalper --scalper=1 --trend-filter=1
 * php artisan mt5:auto-forex --once --test-mode --max-symbols=3
 * php artisan mt5:learn-policy --bot=scalper --apply
 */

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mt5:auto-forex
    {--lot=0.01 : Base lot size for each trade}
    {--tp-pips=25 : Take profit distance in pips}
    {--tp-pips-by-category= : Category TP overrides, e.g. "forex:25,stock:160,commodity:80,default:25"}
    {--sl-pips=15 : Stop loss distance in pips}
    {--sl-pips-by-category= : Category SL overrides, e.g. "forex:15,stock:80,commodity:40,default:15"}
    {--trail-start-pips=10 : Profit pips required before trailing activates}
    {--trail-start-pips-by-category= : Category trail-start overrides, e.g. "forex:10,stock:50,commodity:30,default:10"}
    {--trail-pips=8 : Trailing stop distance in pips}
    {--trail-pips-by-category= : Category trail distance overrides, e.g. "forex:8,stock:25,commodity:15,default:8"}
    {--trail-tp-multiplier= : Multiplier applied to TP when trailing first activates (default from settings, fallback 2)}
    {--trail-tp-multiplier-by-category= : Category trail TP multiplier overrides, e.g. "forex:2,stock:3,commodity:2.5,default:2"}
    {--min-move-pips=3 : Minimum move from previous tick to trigger entry}
    {--min-move-pips-by-category= : Category min-move overrides, e.g. "forex:3,stock:25,commodity:12,default:3"}
    {--max-spread-pips=2.5 : Maximum spread allowed for entries}
    {--max-spread-pips-by-category= : Category spread overrides, e.g. "forex:2.5,stock:40,commodity:15,default:2.5"}
    {--cooldown-minutes=30 : Cooldown per symbol after successful entry}
    {--session-start-utc : Start trading hour (UTC) - uses database setting if not specified}
    {--session-end-utc : End trading hour (UTC) - uses database setting if not specified}
    {--max-trades-per-day : Stop opening new trades after this daily count}
    {--max-trades-per-asset-per-day=2 : Maximum successful entries per symbol per UTC day}
    {--max-daily-loss-percent=2 : Stop opening new trades when daily drawdown exceeds this percent}
    {--ai-confirm=1 : 1 requires AI approval before entry, 0 bypasses AI confirmation}
    {--ai-min-confidence=70 : Minimum AI confidence percentage (0-100) required to approve a trade}
    {--min-bot-score=70 : Minimum bot score (0-100) required to log/execute a signal}
    {--use-adx-score=1 : 1 includes ADX trend-strength in bot score (hard-rejects below floor)}
    {--use-rsi-score=1 : 1 includes RSI trend-alignment in bot score}
    {--adx-min-floor= : Minimum ADX to allow entry (category default when omitted)}
    {--min-effective-volume= : Minimum effective volume required to place a trade (default: 0.01 x volume multiplier)}
    {--max-symbols=200 : Max symbols to scan per cycle (round-robin when total symbols exceed this value)}
    {--scan-delay-ms=0 : Milliseconds to wait between symbol scans to reduce API burst rate}
    {--max-cycle-credits=900 : Estimated MetaApi CPU credits budget per cycle (0 disables budget guard)}
    {--max-open-positions=10 : Maximum concurrent open positions (demo-safe ceiling)}
    {--max-per-cycle=5 : Maximum new trades to open in a single cycle}
    {--strategy=momentum : Legacy single strategy option (prefer --strategies)}
    {--strategies= : Comma-separated strategies (momentum,sma_cross,ema_cross,bollinger_reversion,vwap_reversion)}
    {--scalper=1 : 1 enables scalper mode (quick in/out), 0 keeps normal mode}
    {--reverse-strategy=0 : 1 inverts execution side (fade consensus); 0 trades with consensus direction}
    {--trend-filter=0 : 1 requires all selected trend timeframes to align with the signal side}
    {--signal-timeframes= : Comma-separated trend timeframes (5m,15m,30m,1h,4h). Example: 5m,15m,1h}
    {--entry-timeframe= : Final entry trigger timeframe (must be one of signal timeframes; defaults to lowest selected)}
    {--cooldown-override-ratio=0 : During cooldown, allow a new trade only when signal strength exceeds the last successful trade by this ratio}
    {--preferred-hours-utc= : Comma-separated UTC hours allowed for entries (e.g. 8,14,17)}
    {--blocked-hours-utc=15 : Comma-separated UTC hours blocked for entries (e.g. 15)}
    {--preferred-symbols= : Comma-separated symbols allowed for entries (e.g. USDJPY_SB,EURUSD_SB)}
    {--bot= : Run only one bot profile key/name from settings bot_profiles JSON}
    {--test-mode : Bypass ALL filters and AI — trade the first --max-symbols symbols at market for testing only}
    {--once : Run one cycle only}
', function (Mt5Service $mt5Service, AlpacaService $alpacaService, BrokerResolver $brokerResolver, AiService $aiService, StrategyFactory $strategyFactory, BotScoreCalculator $botScoreCalculator) {
    // ── $resolveProfiles — load/normalize bot_profiles; filter by --bot ──────────
    $resolveProfiles = function (): array {
        $db = AppSetting::singleton();
        $rawProfiles = is_array($db->bot_profiles) ? $db->bot_profiles : [];

        $profiles = [];
        foreach ($rawProfiles as $index => $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $name = trim((string) ($profile['name'] ?? ''));
            if ($name === '') {
                $name = 'Bot '.($index + 1);
            }

            $keyRaw = (string) ($profile['key'] ?? $name);
            $key = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $keyRaw)));
            $key = trim($key, '_');
            if ($key === '') {
                $key = 'bot_'.($index + 1);
            }

            $profiles[] = array_merge($profile, [
                'key' => $key,
                'name' => $name,
                'enabled' => (bool) ($profile['enabled'] ?? true),
            ]);
        }

        if (empty($profiles)) {
            $profiles[] = [
                'key' => 'default',
                'name' => 'Default Bot',
                'enabled' => true,
            ];
        }

        $selectedBot = trim((string) $this->option('bot'));
        if ($selectedBot !== '') {
            $needle = strtolower($selectedBot);
            $profiles = array_values(array_filter($profiles, static function (array $profile) use ($needle): bool {
                $key = strtolower((string) ($profile['key'] ?? ''));
                $name = strtolower((string) ($profile['name'] ?? ''));
                return $key === $needle || $name === $needle;
            }));
        }

        return array_values(array_filter($profiles, static fn (array $profile): bool => (bool) ($profile['enabled'] ?? true)));
    };

    // ── $runCycle — one full scan/trade cycle (see file header docblock) ─────────
    $runCycle = function (array $botProfile) use ($mt5Service, $aiService, $strategyFactory, $botScoreCalculator) {
        $brokerResolver = app(BrokerResolver::class);
        $alpacaService = app(AlpacaService::class);
        $db = AppSetting::singleton();
        $botKey = (string) ($botProfile['key'] ?? 'default');
        $botName = (string) ($botProfile['name'] ?? $botKey);
        $botLogDefaults = [
            'bot_key' => $botKey,
            'bot_name' => $botName,
        ];

        // ── Config helpers: $optionProvided, $optionOrProfileOrSetting, normalizers ──
        // Use CLI option only when the flag is explicitly passed; otherwise prefer DB settings.
        $optionProvided = static function (string $name): bool {
            foreach ($_SERVER['argv'] ?? [] as $arg) {
                if ($arg === '--'.$name || str_starts_with($arg, '--'.$name.'=')) {
                    return true;
                }
            }

            return false;
        };

        $optionOrProfileOrSetting = function (string $name, mixed $profileValue, mixed $settingValue, mixed $fallbackDefault) use ($optionProvided) {
            if ($optionProvided($name)) {
                return $this->option($name);
            }

            if ($profileValue !== null) {
                return $profileValue;
            }

            return $settingValue ?? $fallbackDefault;
        };

        $normalizeHourList = static function (mixed $raw): array {
            if (is_array($raw)) {
                $source = $raw;
            } else {
                $text = trim((string) $raw);
                if ($text === '') {
                    return [];
                }
                $source = explode(',', $text);
            }

            $hours = [];
            foreach ($source as $value) {
                $token = trim((string) $value);
                if ($token === '' || !is_numeric($token)) {
                    continue;
                }

                $hour = (int) $token;
                if ($hour < 0 || $hour > 23) {
                    continue;
                }

                $hours[] = $hour;
            }

            $hours = array_values(array_unique($hours));
            sort($hours);

            return $hours;
        };

        $normalizeSymbolList = static function (mixed $raw) use ($mt5Service): array {
            if (is_array($raw)) {
                $source = $raw;
            } else {
                $text = trim((string) $raw);
                if ($text === '') {
                    return [];
                }
                $source = explode(',', $text);
            }

            return array_values(array_unique(array_filter(
                array_map(
                    static fn ($symbol) => $mt5Service->baseSymbol(strtoupper(trim((string) $symbol))),
                    $source
                ),
                static fn ($symbol) => $symbol !== ''
            )));
        };

        $normalizeTimeframeList = static function (mixed $raw): array {
            $allowed = ['5m', '15m', '30m', '1h', '4h'];
            $order = array_flip($allowed);

            if (is_array($raw)) {
                $source = $raw;
            } else {
                $text = trim((string) $raw);
                if ($text === '') {
                    return [];
                }
                $source = explode(',', $text);
            }

            $timeframes = array_values(array_unique(array_filter(array_map(
                static fn ($value) => strtolower(trim((string) $value)),
                $source
            ), static fn ($value) => isset($order[$value]))));

            usort($timeframes, static fn ($a, $b) => $order[$a] <=> $order[$b]);

            return $timeframes;
        };

        $normalizeStrategyList = static function (mixed $raw, array $allowed): array {
            $order = array_flip($allowed);

            if (is_array($raw)) {
                $source = $raw;
            } else {
                $text = trim((string) $raw);
                if ($text === '') {
                    return [];
                }
                $source = explode(',', $text);
            }

            $strategies = array_values(array_unique(array_filter(array_map(
                static fn ($value) => strtolower(trim((string) $value)),
                $source
            ), static fn ($value) => isset($order[$value]))));

            usort($strategies, static fn ($a, $b) => $order[$a] <=> $order[$b]);

            return $strategies;
        };

        $normalizeStrategyParams = static function (mixed $raw): array {
            if (!is_array($raw)) {
                return [];
            }

            $normalized = [
                'sma_fast' => isset($raw['sma_fast']) ? (int) $raw['sma_fast'] : null,
                'sma_slow' => isset($raw['sma_slow']) ? (int) $raw['sma_slow'] : null,
                'ema_fast' => isset($raw['ema_fast']) ? (int) $raw['ema_fast'] : null,
                'ema_slow' => isset($raw['ema_slow']) ? (int) $raw['ema_slow'] : null,
                'bb_period' => isset($raw['bb_period']) ? (int) $raw['bb_period'] : null,
                'bb_stddev' => isset($raw['bb_stddev']) ? (float) $raw['bb_stddev'] : null,
                'vwap_period' => isset($raw['vwap_period']) ? (int) $raw['vwap_period'] : null,
                'vwap_min_distance_pips' => isset($raw['vwap_min_distance_pips']) ? (float) $raw['vwap_min_distance_pips'] : null,
            ];

            return array_filter($normalized, static fn ($value) => $value !== null);
        };

        $normalizeCategoryNumericMap = static function (mixed $raw): array {
            $result = [];

            if (is_array($raw)) {
                foreach ($raw as $key => $value) {
                    $category = strtolower(trim((string) $key));
                    if ($category === '' || !is_numeric($value)) {
                        continue;
                    }

                    $numericValue = (float) $value;
                    if ($numericValue <= 0) {
                        continue;
                    }

                    $result[$category] = $numericValue;
                }

                return $result;
            }

            $text = trim((string) $raw);
            if ($text === '') {
                return [];
            }

            foreach (explode(',', $text) as $token) {
                $pair = explode(':', (string) $token, 2);
                if (count($pair) !== 2) {
                    continue;
                }

                $category = strtolower(trim((string) $pair[0]));
                $value = trim((string) $pair[1]);
                if ($category === '' || !is_numeric($value)) {
                    continue;
                }

                $numericValue = (float) $value;
                if ($numericValue <= 0) {
                    continue;
                }

                $result[$category] = $numericValue;
            }

            return $result;
        };

        $knownFxCurrencies = ['USD', 'EUR', 'GBP', 'JPY', 'CHF', 'AUD', 'NZD', 'CAD'];

        $classifySpreadCategory = static function (string $symbol, mixed $tickerCategory) use ($knownFxCurrencies): string {
            $rawCategory = strtolower(trim((string) $tickerCategory));
            if ($rawCategory !== '') {
                if (str_contains($rawCategory, 'stock') || str_contains($rawCategory, 'equity') || str_contains($rawCategory, 'share')) {
                    return 'stock';
                }
                if (
                    str_contains($rawCategory, 'commodity') ||
                    str_contains($rawCategory, 'metal') ||
                    str_contains($rawCategory, 'energy') ||
                    str_contains($rawCategory, 'oil') ||
                    str_contains($rawCategory, 'gold') ||
                    str_contains($rawCategory, 'silver')
                ) {
                    return 'commodity';
                }
                if (
                    str_contains($rawCategory, 'index') ||
                    str_contains($rawCategory, 'indice')
                ) {
                    return 'other';
                }
                if (str_contains($rawCategory, 'crypto')) {
                    return 'crypto';
                }
                if (
                    str_contains($rawCategory, 'major') ||
                    str_contains($rawCategory, 'minor') ||
                    str_contains($rawCategory, 'forex') ||
                    str_contains($rawCategory, 'fx')
                ) {
                    return 'forex';
                }
            }

            $normalized = strtoupper(str_replace('/', '', trim((string) $symbol)));
            if (str_ends_with($normalized, '_SB')) {
                $normalized = substr($normalized, 0, -3);
            }

            if (preg_match('/^[A-Z]{6}$/', $normalized) === 1) {
                $baseCurrency = substr($normalized, 0, 3);
                $quoteCurrency = substr($normalized, 3, 3);
                if (in_array($baseCurrency, $knownFxCurrencies, true) && in_array($quoteCurrency, $knownFxCurrencies, true)) {
                    return 'forex';
                }
            }

            if (
                str_contains($normalized, 'XAU')
                || str_contains($normalized, 'XAG')
                || str_contains($normalized, 'WTI')
                || str_contains($normalized, 'BRENT')
            ) {
                return 'commodity';
            }

            return 'other';
        };

        $lotSize           = (float) $optionOrProfileOrSetting('lot', $botProfile['lot'] ?? null, $db->bot_lot ?? null, 0.01);
        $tpPips            = (float) $optionOrProfileOrSetting('tp-pips', $botProfile['tp_pips'] ?? null, $db->bot_tp_pips ?? null, 25);
        $tpPipsByCategory  = $normalizeCategoryNumericMap($optionOrProfileOrSetting(
            'tp-pips-by-category',
            $botProfile['tp_pips_by_category'] ?? null,
            null,
            []
        ));
        $slPips            = (float) $optionOrProfileOrSetting('sl-pips', $botProfile['sl_pips'] ?? null, $db->bot_sl_pips ?? null, 15);
        $slPipsByCategory  = $normalizeCategoryNumericMap($optionOrProfileOrSetting(
            'sl-pips-by-category',
            $botProfile['sl_pips_by_category'] ?? null,
            null,
            []
        ));
        $trailStartPips    = (float) $optionOrProfileOrSetting('trail-start-pips', $botProfile['trail_start_pips'] ?? null, $db->bot_trail_start_pips ?? null, 10);
        $trailStartPipsByCategory = $normalizeCategoryNumericMap($optionOrProfileOrSetting(
            'trail-start-pips-by-category',
            $botProfile['trail_start_pips_by_category'] ?? null,
            null,
            []
        ));
        $trailPips         = (float) $optionOrProfileOrSetting('trail-pips', $botProfile['trail_pips'] ?? null, $db->bot_trail_pips ?? null, 8);
        $trailPipsByCategory = $normalizeCategoryNumericMap($optionOrProfileOrSetting(
            'trail-pips-by-category',
            $botProfile['trail_pips_by_category'] ?? null,
            null,
            []
        ));
        $trailTpMultiplier = (float) $optionOrProfileOrSetting('trail-tp-multiplier', $botProfile['trail_tp_multiplier'] ?? null, $db->bot_trail_tp_multiplier ?? null, 2);
        $trailTpMultiplierByCategory = $normalizeCategoryNumericMap($optionOrProfileOrSetting(
            'trail-tp-multiplier-by-category',
            $botProfile['trail_tp_multiplier_by_category'] ?? null,
            null,
            []
        ));
        $minMovePips       = (float) $optionOrProfileOrSetting('min-move-pips', $botProfile['min_move_pips'] ?? null, $db->bot_min_move_pips ?? null, 3);
        $minMovePipsByCategory = $normalizeCategoryNumericMap($optionOrProfileOrSetting(
            'min-move-pips-by-category',
            $botProfile['min_move_pips_by_category'] ?? null,
            null,
            []
        ));
        $maxSpreadPips     = (float) $optionOrProfileOrSetting('max-spread-pips', $botProfile['max_spread_pips'] ?? null, $db->bot_max_spread_pips ?? null, 2.5);
        $maxSpreadByCategory = $normalizeCategoryNumericMap($optionOrProfileOrSetting(
            'max-spread-pips-by-category',
            $botProfile['max_spread_pips_by_category'] ?? null,
            null,
            []
        ));

        if (empty($tpPipsByCategory)) {
            $tpPipsByCategory = [
                'forex' => $tpPips,
                'stock' => max($tpPips, 160.0),
                'commodity' => max($tpPips, 80.0),
                'crypto' => max($tpPips, 150.0),
                'other' => max($tpPips, 60.0),
                'default' => $tpPips,
            ];
        } else {
            if (!isset($tpPipsByCategory['default'])) {
                $tpPipsByCategory['default'] = $tpPips;
            }
            if (!isset($tpPipsByCategory['forex'])) {
                $tpPipsByCategory['forex'] = $tpPips;
            }
        }

        if (empty($slPipsByCategory)) {
            $slPipsByCategory = [
                'forex' => $slPips,
                'stock' => max($slPips, 80.0),
                'commodity' => max($slPips, 40.0),
                'crypto' => max($slPips, 100.0),
                'other' => max($slPips, 30.0),
                'default' => $slPips,
            ];
        } else {
            if (!isset($slPipsByCategory['default'])) {
                $slPipsByCategory['default'] = $slPips;
            }
            if (!isset($slPipsByCategory['forex'])) {
                $slPipsByCategory['forex'] = $slPips;
            }
        }

        if (empty($maxSpreadByCategory)) {
            $maxSpreadByCategory = [
                'forex' => $maxSpreadPips,
                'stock' => max($maxSpreadPips, 40.0),
                'commodity' => max($maxSpreadPips, 15.0),
                'crypto' => max($maxSpreadPips, 50.0),
                'other' => max($maxSpreadPips, 10.0),
                'default' => $maxSpreadPips,
            ];
        } else {
            if (!isset($maxSpreadByCategory['default'])) {
                $maxSpreadByCategory['default'] = $maxSpreadPips;
            }
            if (!isset($maxSpreadByCategory['forex'])) {
                $maxSpreadByCategory['forex'] = $maxSpreadPips;
            }
        }

        if (empty($trailStartPipsByCategory)) {
            $trailStartPipsByCategory = [
                'forex' => $trailStartPips,
                'stock' => max($trailStartPips, 50.0),
                'commodity' => max($trailStartPips, 30.0),
                'other' => max($trailStartPips, 20.0),
                'default' => $trailStartPips,
            ];
        } else {
            if (!isset($trailStartPipsByCategory['default'])) {
                $trailStartPipsByCategory['default'] = $trailStartPips;
            }
            if (!isset($trailStartPipsByCategory['forex'])) {
                $trailStartPipsByCategory['forex'] = $trailStartPips;
            }
        }

        if (empty($trailPipsByCategory)) {
            $trailPipsByCategory = [
                'forex' => $trailPips,
                'stock' => max($trailPips, 25.0),
                'commodity' => max($trailPips, 15.0),
                'other' => max($trailPips, 10.0),
                'default' => $trailPips,
            ];
        } else {
            if (!isset($trailPipsByCategory['default'])) {
                $trailPipsByCategory['default'] = $trailPips;
            }
            if (!isset($trailPipsByCategory['forex'])) {
                $trailPipsByCategory['forex'] = $trailPips;
            }
        }

        if (empty($trailTpMultiplierByCategory)) {
            $trailTpMultiplierByCategory = [
                'forex' => $trailTpMultiplier,
                'stock' => max($trailTpMultiplier, 3.0),
                'commodity' => max($trailTpMultiplier, 2.5),
                'other' => max($trailTpMultiplier, 2.2),
                'default' => $trailTpMultiplier,
            ];
        } else {
            if (!isset($trailTpMultiplierByCategory['default'])) {
                $trailTpMultiplierByCategory['default'] = $trailTpMultiplier;
            }
            if (!isset($trailTpMultiplierByCategory['forex'])) {
                $trailTpMultiplierByCategory['forex'] = $trailTpMultiplier;
            }
        }

        if (empty($minMovePipsByCategory)) {
            $minMovePipsByCategory = [
                'forex' => $minMovePips,
                'stock' => max($minMovePips, 25.0),
                'commodity' => max($minMovePips, 12.0),
                'other' => max($minMovePips, 8.0),
                'default' => $minMovePips,
            ];
        } else {
            if (!isset($minMovePipsByCategory['default'])) {
                $minMovePipsByCategory['default'] = $minMovePips;
            }
            if (!isset($minMovePipsByCategory['forex'])) {
                $minMovePipsByCategory['forex'] = $minMovePips;
            }
        }

        $categoryValueOrDefault = static function (array $map, string $category, float $fallback): float {
            return (float) ($map[$category] ?? $map['default'] ?? $fallback);
        };
        $cooldownMinutes   = max(0, (int) $optionOrProfileOrSetting('cooldown-minutes', $botProfile['cooldown_minutes'] ?? null, $db->bot_cooldown_minutes ?? null, 30));
        $sessionStartUtc   = (int) $optionOrProfileOrSetting('session-start-utc', $botProfile['session_start_utc'] ?? null, $db->bot_session_start_utc ?? null, 6);
        $sessionEndUtc     = (int) $optionOrProfileOrSetting('session-end-utc', $botProfile['session_end_utc'] ?? null, $db->bot_session_end_utc ?? null, 20);
        $maxTradesPerDay   = max(1, (int) $optionOrProfileOrSetting('max-trades-per-day', $botProfile['max_trades_per_day'] ?? null, $db->bot_max_trades_per_day ?? null, 20));
        $maxTradesPerAssetPerDay = max(1, (int) $optionOrProfileOrSetting('max-trades-per-asset-per-day', $botProfile['max_trades_per_asset_per_day'] ?? null, null, 2));
        $maxDailyLossPercent = (float) $optionOrProfileOrSetting('max-daily-loss-percent', $botProfile['max_daily_loss_percent'] ?? null, $db->bot_max_daily_loss_percent ?? null, 2);
        $aiConfirmSetting = $optionOrProfileOrSetting(
            'ai-confirm',
            array_key_exists('ai_confirm', $botProfile) ? $botProfile['ai_confirm'] : null,
            $db->bot_ai_confirm ?? true,
            true
        );
        $useAiConfirm      = (string) $aiConfirmSetting !== '0' && (bool) $aiConfirmSetting;
        $aiMinConfidence   = (int) $optionOrProfileOrSetting('ai-min-confidence', $botProfile['ai_min_confidence'] ?? null, $db->bot_ai_min_confidence ?? null, 70);
        $minBotScore       = max(0, min(100, (int) $optionOrProfileOrSetting('min-bot-score', $botProfile['min_bot_score'] ?? null, null, 70)));
        $volumeMultiplier  = max(1, (int) ($db->mt5_volume_multiplier ?? 1));
        $defaultMinEffectiveVolume = 0.01 * $volumeMultiplier;
        $minEffectiveVolume = (float) $optionOrProfileOrSetting('min-effective-volume', $botProfile['min_effective_volume'] ?? null, null, $defaultMinEffectiveVolume);
        $enableMaxHoldSetting = $optionOrProfileOrSetting('enable-max-hold', $botProfile['enable_max_hold'] ?? null, null, 0);
        $enableMaxHold = (string) $enableMaxHoldSetting !== '0' && (bool) $enableMaxHoldSetting;
        $maxHoldMinutesRaw = $optionOrProfileOrSetting('max-hold-minutes', $botProfile['max_hold_minutes'] ?? null, null, null);
        $maxHoldMinutes = is_numeric($maxHoldMinutesRaw) && (int) $maxHoldMinutesRaw > 0
            ? (int) $maxHoldMinutesRaw
            : null;
        $maxSymbols        = max(1, (int) $optionOrProfileOrSetting('max-symbols', $botProfile['max_symbols'] ?? null, $db->bot_max_symbols ?? null, 200));
        $scanDelayMs       = max(0, (int) $optionOrProfileOrSetting('scan-delay-ms', $botProfile['scan_delay_ms'] ?? null, null, 0));
        $maxCycleCredits   = max(0, (int) $optionOrProfileOrSetting('max-cycle-credits', $botProfile['max_cycle_credits'] ?? null, $db->bot_max_cycle_credits ?? null, 900));
        $supportedStrategies = $strategyFactory->supportedKeys();
        $strategySource = $optionOrProfileOrSetting(
            'strategies',
            $botProfile['strategies'] ?? ($botProfile['strategy'] ?? null),
            $db->bot_strategies ?? ($db->bot_strategy ?? null),
            ['momentum']
        );

        if ($optionProvided('strategy') && !$optionProvided('strategies')) {
            $strategySource = [(string) $this->option('strategy')];
        }

        $selectedStrategyKeys = $normalizeStrategyList($strategySource, $supportedStrategies);
        if (empty($selectedStrategyKeys)) {
            $selectedStrategyKeys = ['momentum'];
        }
        $strategies = array_map(static fn (string $key) => $strategyFactory->make($key), $selectedStrategyKeys);
        $globalStrategyParams = $normalizeStrategyParams($db->bot_strategy_params ?? null);
        $profileStrategyParams = $normalizeStrategyParams($botProfile['strategy_params'] ?? null);
        $strategyParams = array_merge($globalStrategyParams, $profileStrategyParams);
        $scalperSetting = $optionOrProfileOrSetting('scalper', $botProfile['scalper'] ?? null, null, 1);
        $scalperMode = (string) $scalperSetting !== '0' && (bool) $scalperSetting;
        $reverseStrategySetting = $optionOrProfileOrSetting('reverse-strategy', $botProfile['reverse_strategy'] ?? null, null, 0);
        $reverseStrategy = (string) $reverseStrategySetting !== '0' && (bool) $reverseStrategySetting;
        $trendFilterSetting = $optionOrProfileOrSetting('trend-filter', $botProfile['trend_filter'] ?? null, null, 0);
        $useTrendFilter = (string) $trendFilterSetting !== '0' && (bool) $trendFilterSetting;
        $useAdxScoreSetting = $optionOrProfileOrSetting(
            'use-adx-score',
            array_key_exists('use_adx_score', $botProfile) ? $botProfile['use_adx_score'] : null,
            null,
            1
        );
        $useAdxScore = (string) $useAdxScoreSetting !== '0' && (bool) $useAdxScoreSetting;
        $useRsiScoreSetting = $optionOrProfileOrSetting(
            'use-rsi-score',
            array_key_exists('use_rsi_score', $botProfile) ? $botProfile['use_rsi_score'] : null,
            null,
            1
        );
        $useRsiScore = (string) $useRsiScoreSetting !== '0' && (bool) $useRsiScoreSetting;
        $adxMinFloorRaw = $optionOrProfileOrSetting('adx-min-floor', $botProfile['adx_min_floor'] ?? null, null, null);
        $adxMinFloor = is_numeric($adxMinFloorRaw) && (float) $adxMinFloorRaw > 0
            ? (float) $adxMinFloorRaw
            : null;
        $trendTimeframes = $normalizeTimeframeList($optionOrProfileOrSetting(
            'signal-timeframes',
            $botProfile['signal_timeframes'] ?? (isset($botProfile['signal_timeframe']) ? [(string) $botProfile['signal_timeframe']] : null),
            $db->bot_signal_timeframes ?? null,
            ['1h', '4h']
        ));
        if (empty($trendTimeframes)) {
            $trendTimeframes = ['1h', '4h'];
        }
        $entryTimeframeRaw = strtolower(trim((string) $optionOrProfileOrSetting(
            'entry-timeframe',
            $botProfile['entry_timeframe'] ?? null,
            $db->bot_entry_timeframe ?? null,
            '15m'
        )));
        $allowedEntryTimeframes = ['5m', '15m', '30m', '1h', '4h'];
        $entryTimeframe = in_array($entryTimeframeRaw, $allowedEntryTimeframes, true)
            ? $entryTimeframeRaw
            : '15m';
        // HTF trend context uses signal_timeframes only; entry_timeframe is separate (e.g. 1h+4h context, 15m entry).
        $trendContextTimeframes = array_values(array_filter(
            $trendTimeframes,
            static fn (string $timeframe): bool => $timeframe !== $entryTimeframe
        ));
        if (empty($trendContextTimeframes)) {
            $trendContextTimeframes = $trendTimeframes;
        }
        $aiContextTimeframes = array_values(array_unique(array_merge($trendContextTimeframes, [$entryTimeframe])));
        $cooldownOverrideRatio = (float) $optionOrProfileOrSetting('cooldown-override-ratio', $botProfile['cooldown_override_ratio'] ?? null, null, 0);
        $preferredHoursUtc = $normalizeHourList($optionOrProfileOrSetting('preferred-hours-utc', $botProfile['preferred_hours_utc'] ?? null, null, null));
        $blockedHoursUtc = $normalizeHourList($optionOrProfileOrSetting('blocked-hours-utc', $botProfile['blocked_hours_utc'] ?? null, null, 15));
        $preferredSymbols = $normalizeSymbolList($optionOrProfileOrSetting('preferred-symbols', $botProfile['preferred_symbols'] ?? null, null, null));
        $maxOpenPositions     = max(1, (int) $optionOrProfileOrSetting('max-open-positions', $botProfile['max_open_positions'] ?? null, $db->bot_max_open_positions ?? null, 10));
        $maxPerCycle          = max(1, (int) $optionOrProfileOrSetting('max-per-cycle', $botProfile['max_per_cycle'] ?? null, $db->bot_max_per_cycle ?? null, 5));
        $testMode             = (bool) $this->option('test-mode');
        $profileTickerCategories = isset($botProfile['ticker_categories']) && is_array($botProfile['ticker_categories'])
            ? array_values(array_unique(array_filter(array_map(static fn ($value) => strtolower(trim((string) $value)), $botProfile['ticker_categories']), static fn ($value) => $value !== '')))
            : [];
        $cycleBroker = $brokerResolver->forProfile($profileTickerCategories, $botProfile);
        $usesAlpacaCycle = $brokerResolver->usesAlpaca($cycleBroker);
        $usesEaBridgeCycle = $brokerResolver->usesEaBridge($cycleBroker);
        $usesMetaApiCycle = $brokerResolver->usesMetaApi($cycleBroker);
        $eaProfileInstanceKeys = $usesEaBridgeCycle
            ? EaBridgeService::profileInstanceKeys($botProfile)
            : [];
        $eaBridgeBrokerForScan = $usesEaBridgeCycle ? app(EaBridgeBroker::class) : null;

        if ($usesEaBridgeCycle) {
            try {
                $cycleBroker->getAccountInformation();
            } catch (\Throwable $e) {
                $this->error('EA bridge profile cannot run: '.$e->getMessage());
                BotTradeLog::query()->create(array_merge($botLogDefaults, [
                    'event_type' => 'guardrail',
                    'status' => 'broker_config_error',
                    'message' => $e->getMessage(),
                ]));

                return 0;
            }
        }

        if ($usesMetaApiCycle) {
            try {
                $cycleBroker->getAccountInformation();
            } catch (\Throwable $e) {
                $this->error('MetaApi profile cannot run: '.$e->getMessage());
                BotTradeLog::query()->create(array_merge($botLogDefaults, [
                    'event_type' => 'guardrail',
                    'status' => 'broker_config_error',
                    'message' => $e->getMessage(),
                ]));

                return 0;
            }
        }

        $minEffectiveVolumeExplicit = $optionProvided('min-effective-volume')
            || ($botProfile['min_effective_volume'] ?? null) !== null;
        if ($usesAlpacaCycle && !$minEffectiveVolumeExplicit) {
            // Alpaca crypto qty is not MT5 lots; don't block 0.001 BTC with a 0.01 floor.
            $minEffectiveVolume = min($minEffectiveVolume, max(0.0001, $lotSize * $volumeMultiplier));
        }
        if ($usesAlpacaCycle && !$optionProvided('scalper') && ($botProfile['scalper'] ?? null) === null) {
            $scalperMode = false;
        }

        if ($usesAlpacaCycle) {
            try {
                $alpacaService->assertConfigured();
            } catch (\Throwable $e) {
                $this->error('Alpaca crypto profile cannot run: '.$e->getMessage());
                BotTradeLog::query()->create(array_merge($botLogDefaults, [
                    'event_type' => 'guardrail',
                    'status' => 'broker_config_error',
                    'message' => $e->getMessage(),
                ]));

                return 1;
            }
        }

        $this->info('Running bot: '.$botName.' ('.$botKey.')');
        if ($usesAlpacaCycle) {
            $this->line('Broker: Alpaca paper crypto');
        } elseif ($usesEaBridgeCycle) {
            $this->line('Broker: EA Bridge (LaravelBridge)');
            if ($eaProfileInstanceKeys !== []) {
                $instanceSummaries = [];
                foreach ($eaProfileInstanceKeys as $instanceKey) {
                    try {
                        $scanInstance = $eaBridgeBrokerForScan?->forInstance($instanceKey);
                        $instanceSummaries[] = $scanInstance
                            ? $scanInstance->instanceLabel().' ['.$scanInstance->instanceKey().']'
                            : $instanceKey;
                    } catch (\Throwable $e) {
                        $instanceSummaries[] = $instanceKey.' (offline: '.$e->getMessage().')';
                    }
                }
                $this->line('EA scan instances: '.implode(' | ', $instanceSummaries));
            }
        } else {
            $this->line('Broker: MetaApi (cloud MT5)');
        }

        if ($scalperMode) {
            // Scalper defaults: 1:3 R:R (SL=10pip, TP=30pip).
            $tpPips = min($tpPips, 30.0);
            $slPips = min($slPips, 10.0);
            $trailStartPips = min($trailStartPips, 15.0);
            $trailPips = min($trailPips, 8.0);
            $minMovePips = min($minMovePips, 1.5);
            $maxSpreadPips = min($maxSpreadPips, 5.0);
            foreach ($maxSpreadByCategory as $category => $spreadLimit) {
                $maxSpreadByCategory[$category] = min((float) $spreadLimit, 50.0);
            }
            foreach ($tpPipsByCategory as $category => $tpLimit) {
                $tpPipsByCategory[$category] = min((float) $tpLimit, 300.0);
            }
            foreach ($slPipsByCategory as $category => $slLimit) {
                $slPipsByCategory[$category] = min((float) $slLimit, 150.0);
            }
            foreach ($trailStartPipsByCategory as $category => $trailStartLimit) {
                $trailStartPipsByCategory[$category] = min((float) $trailStartLimit, 400.0);
            }
            foreach ($trailPipsByCategory as $category => $trailLimit) {
                $trailPipsByCategory[$category] = min((float) $trailLimit, 200.0);
            }
            foreach ($trailTpMultiplierByCategory as $category => $multiplierLimit) {
                $trailTpMultiplierByCategory[$category] = min((float) $multiplierLimit, 10.0);
            }
            foreach ($minMovePipsByCategory as $category => $minMoveLimit) {
                $minMovePipsByCategory[$category] = min((float) $minMoveLimit, 200.0);
            }
            $cooldownMinutes = min($cooldownMinutes, 5);
        }

        if (
            $lotSize <= 0 ||
            $tpPips <= 0 ||
            $slPips <= 0 ||
            $trailStartPips <= 0 ||
            $trailPips <= 0 ||
            $trailTpMultiplier < 1 ||
            $minMovePips <= 0 ||
            $maxSpreadPips <= 0 ||
            $maxDailyLossPercent <= 0 ||
            $cooldownOverrideRatio < 0 ||
            $minEffectiveVolume <= 0
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

        $this->line('TP limits by category: '.json_encode($tpPipsByCategory));
        $this->line('SL limits by category: '.json_encode($slPipsByCategory));
        $this->line('Trail-start limits by category: '.json_encode($trailStartPipsByCategory));
        $this->line('Trail limits by category: '.json_encode($trailPipsByCategory));
        $this->line('Trail TP multiplier by category: '.json_encode($trailTpMultiplierByCategory));
        $this->line('Min-move limits by category: '.json_encode($minMovePipsByCategory));
        $this->line('Spread limits by category: '.json_encode($maxSpreadByCategory));

        if ($useTrendFilter) {
            $this->line('Trend filter ON  context='.strtoupper(implode(',', $trendContextTimeframes)).'  entry='.strtoupper($entryTimeframe));
        }

        if ($cooldownOverrideRatio > 0) {
            $this->line('Cooldown override ON  ratio='.$cooldownOverrideRatio);
        }

        $this->line('Volume settings  multiplier='.$volumeMultiplier.'  minEffectiveVolume='.$minEffectiveVolume);
        $this->line('Cycle credit budget '.($maxCycleCredits > 0 ? $maxCycleCredits : 'OFF'));
        $this->line('Strategies '.strtoupper(implode(',', $selectedStrategyKeys)).' on '.strtoupper($entryTimeframe));
        $this->line('Reverse strategy '.($reverseStrategy ? 'ON' : 'OFF'));
        $this->line('AI confirmation '.($useAiConfirm ? 'ON' : 'OFF'));

        // ── §11 PRE-SCAN GUARDRAILS (session, hours, daily trade/loss limits) ─────
        // Force UTC timezone to ensure correct time comparison
        date_default_timezone_set('UTC');
        $currentHourUtc = (int) \Carbon\Carbon::now('UTC')->format('G');
        $inSession = $sessionStartUtc <= $sessionEndUtc
            ? ($currentHourUtc >= $sessionStartUtc && $currentHourUtc <= $sessionEndUtc)
            : ($currentHourUtc >= $sessionStartUtc || $currentHourUtc <= $sessionEndUtc);

        if (!$testMode && !$inSession) {
            $msg = "Skipped cycle: outside trading session ({$sessionStartUtc}:00-{$sessionEndUtc}:59 UTC). Current UTC hour: {$currentHourUtc}.";
            $this->warn($msg);
            BotTradeLog::query()->create(array_merge($botLogDefaults, [
                'event_type' => 'guardrail',
                'status' => 'session_block',
                'message' => $msg,
                'meta_payload' => [
                    'session_start_utc' => $sessionStartUtc,
                    'session_end_utc' => $sessionEndUtc,
                    'current_hour_utc' => $currentHourUtc,
                    'in_session' => false,
                    'preferred_hours_utc' => $preferredHoursUtc,
                    'blocked_hours_utc' => $blockedHoursUtc,
                    'preferred_symbols' => $preferredSymbols,
                    'test_mode' => $testMode,
                    'scalper_mode' => $scalperMode,
                    'strategies' => $selectedStrategyKeys,
                    'strategy_params' => $strategyParams,
                    'trend_filter' => $useTrendFilter,
                    'trend_timeframes' => $trendTimeframes,
                    'ai_confirm' => $useAiConfirm,
                    'max_symbols' => $maxSymbols,
                    'max_per_cycle' => $maxPerCycle,
                ],
            ]));
            return 0;
        }

        if (!$testMode && in_array($currentHourUtc, $blockedHoursUtc, true)) {
            $msg = "Skipped cycle: blocked UTC hour {$currentHourUtc} by hour-performance guardrail.";
            $this->warn($msg);
            BotTradeLog::query()->create(array_merge($botLogDefaults, [
                'event_type' => 'guardrail',
                'status' => 'hour_block',
                'message' => $msg,
                'meta_payload' => [
                    'current_hour_utc' => $currentHourUtc,
                    'blocked_hours_utc' => $blockedHoursUtc,
                    'preferred_hours_utc' => $preferredHoursUtc,
                ],
            ]));

            return 0;
        }

        if (!$testMode && !empty($preferredHoursUtc) && !in_array($currentHourUtc, $preferredHoursUtc, true)) {
            $msg = "Skipped cycle: UTC hour {$currentHourUtc} is outside preferred entry hours.";
            $this->warn($msg);
            BotTradeLog::query()->create(array_merge($botLogDefaults, [
                'event_type' => 'guardrail',
                'status' => 'hour_not_preferred',
                'message' => $msg,
                'meta_payload' => [
                    'current_hour_utc' => $currentHourUtc,
                    'preferred_hours_utc' => $preferredHoursUtc,
                    'blocked_hours_utc' => $blockedHoursUtc,
                ],
            ]));

            return 0;
        }

        $todayStart = now()->startOfDay();
        $openedToday = BotTradeLog::query()
            ->where('bot_key', $botKey)
            ->where('event_type', 'trade_open')
            ->where('status', 'success')
            ->where('created_at', '>=', $todayStart)
            ->count();

        $openedTodayBySymbol = BotTradeLog::query()
            ->where('bot_key', $botKey)
            ->where('event_type', 'trade_open')
            ->where('status', 'success')
            ->where('created_at', '>=', $todayStart)
            ->pluck('symbol')
            ->map(static fn ($symbol) => strtoupper((string) $symbol))
            ->countBy()
            ->all();

        if (!$testMode && $openedToday >= $maxTradesPerDay) {
            $msg = "Skipped entries: daily max trades reached ({$openedToday}/{$maxTradesPerDay}).";
            $this->warn($msg);
            BotTradeLog::query()->create(array_merge($botLogDefaults, [
                'event_type' => 'guardrail',
                'status' => 'daily_trade_limit',
                'message' => $msg,
            ]));
            return 0;
        }

        if ($testMode) {
            $this->line('TEST MODE: skipping daily loss guard.');
        }

        try {
            $accountInfo = $cycleBroker->getAccountInformation();
            $equity = (float) ($accountInfo['equity'] ?? $accountInfo['balance'] ?? 0);
            $accountCash = (float) ($accountInfo['balance'] ?? $accountInfo['cash'] ?? $equity);
            $baselineKey = 'auto_bot_day_start_equity_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($botKey)).'_'.now()->format('Ymd');
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
                    BotTradeLog::query()->create(array_merge($botLogDefaults, [
                        'event_type' => 'guardrail',
                        'status' => 'daily_loss_limit',
                        'message' => $msg,
                    ]));
                    return 0;
                }
            }
        } catch (\Throwable $e) {
            $this->warn('Account info unavailable, skipping daily loss guard: '.$e->getMessage());
            $accountCash = null;
        }

        if (!isset($accountCash)) {
            $accountCash = null;
        }

        // ── §12 TRAILING STOPS (pre-scan + category-aware params) ───────────────────
        $tickerCategoryMapForTrailing = Ticker::query()
            ->select(['symbol', 'category'])
            ->get()
            ->mapWithKeys(static fn ($ticker) => [strtoupper((string) $ticker->symbol) => $ticker->category])
            ->all();

        $resolveTrailingParamsForSymbol = static function (string $symbol) use (
            $tickerCategoryMapForTrailing,
            $classifySpreadCategory,
            $categoryValueOrDefault,
            $trailStartPipsByCategory,
            $trailPipsByCategory,
            $trailTpMultiplierByCategory,
            $trailStartPips,
            $trailPips,
            $trailTpMultiplier
        ): array {
            $normalizedSymbol = strtoupper((string) $symbol);
            $category = $classifySpreadCategory($normalizedSymbol, $tickerCategoryMapForTrailing[$normalizedSymbol] ?? null);

            return [
                'start_pips' => $categoryValueOrDefault($trailStartPipsByCategory, $category, $trailStartPips),
                'trail_pips' => $categoryValueOrDefault($trailPipsByCategory, $category, $trailPips),
                'tp_multiplier' => $categoryValueOrDefault($trailTpMultiplierByCategory, $category, $trailTpMultiplier),
            ];
        };

        if (!$usesAlpacaCycle && !$usesEaBridgeCycle) {
            $this->info('Running trailing stop updates...');
            $trailResult = $mt5Service->applyTrailingStops($trailStartPips, $trailPips, $trailTpMultiplier, $resolveTrailingParamsForSymbol);
            $this->line('Trailing updated: '.$trailResult['updated'].', skipped: '.$trailResult['skipped']);
            if ($trailResult['updated'] > 0) {
                BotTradeLog::query()->create(array_merge($botLogDefaults, [
                    'event_type' => 'trailing_update',
                    'status' => 'success',
                    'message' => 'Trailing stop updated on '.$trailResult['updated'].' positions.',
                ]));
            }

            if (!empty($trailResult['errors'])) {
                foreach ($trailResult['errors'] as $error) {
                    $this->warn('Trailing error '.$error['symbol'].' #'.$error['position_id'].': '.$error['error']);
                    BotTradeLog::query()->create(array_merge($botLogDefaults, [
                        'event_type' => 'trailing_update',
                        'status' => 'failed',
                        'symbol' => $error['symbol'] ?? null,
                        'message' => 'Trailing stop update failed.',
                        'error_message' => $error['error'] ?? null,
                    ]));
                }
            }

            if (!$testMode && $enableMaxHold && $maxHoldMinutes !== null) {
                $this->info('Running max-hold close pass...');
                $holdCutoff = now()->subMinutes($maxHoldMinutes);
                $alreadyClosedByMaxHold = BotTradeLog::query()
                    ->where('bot_key', $botKey)
                    ->where('event_type', 'guardrail')
                    ->where('status', 'max_hold_closed')
                    ->whereNotNull('position_id')
                    ->pluck('position_id')
                    ->filter()
                    ->values()
                    ->all();
                $expiredTrades = BotTradeLog::query()
                    ->where('bot_key', $botKey)
                    ->where('event_type', 'trade_open')
                    ->where('status', 'success')
                    ->where('trade_outcome', 'PENDING')
                    ->whereNotNull('position_id')
                    ->where('created_at', '<=', $holdCutoff)
                    ->when(
                        !empty($alreadyClosedByMaxHold),
                        static fn ($query) => $query->whereNotIn('position_id', $alreadyClosedByMaxHold)
                    )
                    ->orderBy('created_at')
                    ->get();

                $closedByMaxHold = 0;
                foreach ($expiredTrades as $tradeLog) {
                    $positionId = trim((string) ($tradeLog->position_id ?? ''));
                    if ($positionId === '') {
                        continue;
                    }

                    try {
                        $mt5CloseResult = $mt5Service->closePosition($positionId);
                        $closedByMaxHold++;
                        $symbol = $tradeLog->symbol ? strtoupper((string) $tradeLog->symbol) : null;
                        $this->line('Max hold closed '.$positionId.($symbol ? ' '.$symbol : '').' after '.$maxHoldMinutes.' minute(s).');
                        BotTradeLog::query()->create(array_merge($botLogDefaults, [
                            'event_type' => 'guardrail',
                            'status' => 'max_hold_closed',
                            'symbol' => $tradeLog->symbol,
                            'side' => $tradeLog->side,
                            'position_id' => $positionId,
                            'linked_trade' => $tradeLog->linked_trade,
                            'lot_size' => $tradeLog->lot_size,
                            'entry_price' => $tradeLog->entry_price,
                            'take_profit' => $tradeLog->take_profit,
                            'stop_loss' => $tradeLog->stop_loss,
                            'message' => 'Trade closed because max hold time was reached.',
                            'meta_payload' => [
                                'max_hold_minutes' => $maxHoldMinutes,
                                'opened_at' => optional($tradeLog->created_at)->toDateTimeString(),
                                'closed_by' => 'max_hold_minutes',
                            ],
                            'meta_response' => $mt5CloseResult,
                        ]));
                    } catch (\Throwable $e) {
                        $this->warn('Max hold close failed #'.$positionId.': '.$e->getMessage());
                        BotTradeLog::query()->create(array_merge($botLogDefaults, [
                            'event_type' => 'guardrail',
                            'status' => 'max_hold_close_failed',
                            'symbol' => $tradeLog->symbol,
                            'side' => $tradeLog->side,
                            'position_id' => $positionId,
                            'linked_trade' => $tradeLog->linked_trade,
                            'message' => 'Trade close failed after max hold time was reached.',
                            'error_message' => $e->getMessage(),
                            'meta_payload' => [
                                'max_hold_minutes' => $maxHoldMinutes,
                                'opened_at' => optional($tradeLog->created_at)->toDateTimeString(),
                                'close_failed_by' => 'max_hold_minutes',
                            ],
                        ]));
                    }
                }

                if ($closedByMaxHold > 0) {
                    $this->line('Max hold closed: '.$closedByMaxHold);
                }
            }
        } else {
            $this->line('Skipping MT5 trailing updates (Alpaca bracket orders manage TP/SL).');
        }

        // ── §11 max open positions + §13 SYMBOL UNIVERSE ───────────────────────────
        $positions = [];
        if ($usesEaBridgeCycle && count($eaProfileInstanceKeys) > 1) {
            $eaBridgeBroker = app(EaBridgeBroker::class);
            foreach ($eaProfileInstanceKeys as $instanceKey) {
                try {
                    $instanceSnapshot = $eaBridgeBroker->forInstance($instanceKey)->getOpenTradeSnapshot();
                    $instancePositions = $instanceSnapshot['positions'] ?? null;
                    if (is_array($instancePositions) && array_is_list($instancePositions)) {
                        foreach ($instancePositions as $position) {
                            $positions[] = $position;
                        }
                    }
                } catch (\Throwable $e) {
                    $this->warn('  Could not load open positions for instance '.$instanceKey.': '.$e->getMessage());
                }
            }
        } else {
            $openSnapshot = $cycleBroker->getOpenTradeSnapshot();
            $positionsPayload = $openSnapshot['positions'] ?? null;
            $positions = (is_array($positionsPayload) && array_is_list($positionsPayload)) ? $positionsPayload : [];
        }

        if (!$testMode && count($positions) >= $maxOpenPositions) {
            $msg = 'Skipped entries: max open positions reached ('.count($positions).'/'.$maxOpenPositions.').';
            $this->line($msg);
            BotTradeLog::query()->create(array_merge($botLogDefaults, [
                'event_type' => 'guardrail',
                'status' => 'max_open_positions',
                'message' => $msg,
            ]));

            return 0;
        }

        $openBySymbol = [];
        $openQtyBySymbol = [];
        foreach ($positions as $position) {
            if (is_array($position) && !empty($position['symbol'])) {
                $sym = strtoupper((string) $position['symbol']);
                $positionQty = isset($position['qty']) ? abs((float) $position['qty']) : 0.0;
                $openBySymbol[$sym] = true;
                $openQtyBySymbol[$sym] = $positionQty;
                // Also index by base symbol so a plain symbol like "EURUSD" matches
                // a broker-suffixed open position like "EURUSD.a".
                $baseSym = $cycleBroker->baseSymbol($sym);
                $openBySymbol[$baseSym] = true;
                $openQtyBySymbol[$baseSym] = max((float) ($openQtyBySymbol[$baseSym] ?? 0), $positionQty);
            }
        }

        // Prefer active tickers from the database; fall back to MetaAPI symbol discovery.
        $dbTickers = Ticker::query()->active()->orderBy('symbol')->get()->keyBy(fn ($t) => strtoupper($t->symbol));
        $profileSymbols = isset($botProfile['symbols']) && is_array($botProfile['symbols'])
            ? array_values(array_filter(array_map(
                static fn ($s) => $mt5Service->baseSymbol(strtoupper(trim((string) $s))),
                $botProfile['symbols']
            ), static fn ($s) => $s !== ''))
            : [];

        if (!empty($profileSymbols)) {
            $symbols = $profileSymbols;
            $this->line('Using '.count($symbols).' symbol(s) from bot profile list.');
        } elseif ($dbTickers->isNotEmpty()) {
            $symbols = $dbTickers->keys()->all();
            $this->line('Using '.count($symbols).' symbol(s) from tickers table.');
        } else {
            if ($usesAlpacaCycle) {
                $symbols = ['BTC/USD'];
                $this->line('No crypto tickers in DB — defaulting to BTC/USD. Add tickers for more symbols.');
            } else {
                $symbols = $mt5Service->getForexSymbols();
                $dbTickers = collect();
                $this->line('No tickers in DB — discovered '.count($symbols).' symbol(s) from MetaAPI.');
            }
        }

        if (!empty($preferredSymbols)) {
            $symbols = array_values(array_intersect($symbols, $preferredSymbols));
            $this->line('Applied preferred symbol filter: '.count($symbols).' symbol(s) remain.');
        }

        if (!empty($profileTickerCategories)) {
            $symbols = array_values(array_filter($symbols, function (string $symbol) use ($dbTickers, $classifySpreadCategory, $profileTickerCategories): bool {
                $tickerCategory = $dbTickers->get($symbol)?->category;
                $resolvedCategory = $classifySpreadCategory($symbol, $tickerCategory);
                return in_array($resolvedCategory, $profileTickerCategories, true);
            }));

            $this->line('Applied profile ticker category filter ('.implode(',', $profileTickerCategories).'): '.count($symbols).' symbol(s) remain.');
        }

        $totalSymbols = count($symbols);
        if (!$testMode && $totalSymbols > $maxSymbols) {
            $cursorKey = 'auto_bot_symbol_cursor_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($botKey));
            $start = ((int) Cache::get($cursorKey, 0)) % $totalSymbols;
            $window = [];
            for ($i = 0; $i < $maxSymbols; $i++) {
                $window[] = $symbols[($start + $i) % $totalSymbols];
            }

            $nextStart = ($start + $maxSymbols) % $totalSymbols;
            Cache::put($cursorKey, $nextStart, now()->addDays(7));

            $symbols = array_values(array_unique($window));
            $this->line('Applied round-robin symbol window: start='.$start.', size='.count($symbols).' of '.$totalSymbols.' (next='.$nextStart.').');
        } elseif ($testMode && $totalSymbols > $maxSymbols) {
            $symbols = array_slice($symbols, 0, $maxSymbols);
            $this->line('TEST MODE symbol cap applied: '.count($symbols).' of '.$totalSymbols.' symbol(s).');
        }

        $symbols = array_values(array_unique(array_map(
            static fn (string $s): string => $mt5Service->baseSymbol($s),
            $symbols
        )));

        if (!$testMode && empty($symbols)) {
            $msg = 'Skipped cycle: no symbols available after preferred symbol filter.';
            $this->warn($msg);
            BotTradeLog::query()->create(array_merge($botLogDefaults, [
                'event_type' => 'guardrail',
                'status' => 'symbol_filter_block',
                'message' => $msg,
                'meta_payload' => [
                    'preferred_symbols' => $preferredSymbols,
                    'profile_ticker_categories' => $profileTickerCategories,
                ],
            ]));

            return 0;
        }

        $this->line('Scanning '.count($symbols).' symbols. Open positions: '.count($positions).'.');
        // Scan counters + bot score / AI helpers (§17–§19)
        $opened = 0;
        $scanned = 0;
        $skippedNoMove = 0;
        $skippedSpread = 0;
        $skippedCooldown = 0;
        $skippedAssetDailyLimit = 0;
        $skippedOpen = 0;
        $skippedLowScore = 0;
        $skippedAdxRejected = 0;
        $skippedLowVolume = 0;
        $stoppedByRateLimit = false;
        $stoppedByCreditBudget = false;
        $cycleCreditsUsed = 0;
        $creditCostQuote = 50;
        $creditCostCandle = 50;

        $calculateBotScore = static function (
            float $signalDeltaPips,
            float $spreadPips,
            string $spreadCategory,
            float $maxSpreadForSymbol,
            ?float $slPipsForSymbol = null,
            ?string $side = null,
            ?float $adx = null,
            ?float $rsiHtf = null,
            ?float $rsiEntry = null,
        ) use ($botScoreCalculator, $useAdxScore, $useRsiScore, $adxMinFloor): array {
            return $botScoreCalculator->calculate(
                $signalDeltaPips,
                $spreadPips,
                $spreadCategory,
                $maxSpreadForSymbol,
                $slPipsForSymbol,
                $side,
                $adx,
                $rsiHtf,
                $rsiEntry,
                $useAdxScore,
                $useRsiScore,
                $adxMinFloor,
            );
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

        $resolveTrendSide = static function (array $candles): ?string {
            if (empty($candles)) {
                return null;
            }

            $lastCandle = $candles[array_key_last($candles)] ?? null;
            if (!is_array($lastCandle)) {
                return null;
            }

            $open = isset($lastCandle['open']) ? (float) $lastCandle['open'] : null;
            $close = isset($lastCandle['close']) ? (float) $lastCandle['close'] : null;

            if ($open === null || $close === null || $open === $close) {
                return null;
            }

            return $close > $open ? 'buy' : 'sell';
        };

        $logSignal = static function (array $data) use ($calculateBotScore, $minBotScore, $testMode, $volumeMultiplier, $minEffectiveVolume, $lotSize, $botLogDefaults, $botKey, $botName, $selectedStrategyKeys, $trendTimeframes, $strategyParams): void {
            $payload = is_array($data['meta_payload'] ?? null) ? $data['meta_payload'] : [];
            $status = (string) ($data['status'] ?? '');

            $resolvedBotScore = null;
            if (is_numeric($payload['bot_score'] ?? null)) {
                $resolvedBotScore = (int) $payload['bot_score'];
            } elseif (is_numeric($data['signal_delta_pips'] ?? null) && is_numeric($data['spread_pips'] ?? null)) {
                $spreadCategoryForScore = (string) ($payload['spread_category'] ?? 'forex');
                $maxSpreadForScore = (float) (
                    $payload['max_spread_pips_for_symbol']
                    ?? $payload['max_spread_for_symbol']
                    ?? 2.5
                );
                $slForScore = isset($payload['sl_pips_for_symbol']) && is_numeric($payload['sl_pips_for_symbol'])
                    ? (float) $payload['sl_pips_for_symbol']
                    : null;
                $components = is_array($payload['score_components'] ?? null) ? $payload['score_components'] : [];
                $sideForScore = isset($data['side']) ? (string) $data['side'] : null;
                $adxForScore = is_numeric($components['adx'] ?? null)
                    ? (float) $components['adx']
                    : (is_numeric($payload['adx'] ?? null) ? (float) $payload['adx'] : null);
                $rsiHtfForScore = is_numeric($components['rsi_htf'] ?? null)
                    ? (float) $components['rsi_htf']
                    : (is_numeric($payload['rsi_htf'] ?? null) ? (float) $payload['rsi_htf'] : null);
                $rsiEntryForScore = is_numeric($components['rsi_entry'] ?? null)
                    ? (float) $components['rsi_entry']
                    : (is_numeric($payload['rsi_entry'] ?? null) ? (float) $payload['rsi_entry'] : null);
                $scoreResult = $calculateBotScore(
                    (float) $data['signal_delta_pips'],
                    (float) $data['spread_pips'],
                    $spreadCategoryForScore,
                    $maxSpreadForScore,
                    $slForScore,
                    $sideForScore,
                    $adxForScore,
                    $rsiHtfForScore,
                    $rsiEntryForScore,
                );
                $resolvedBotScore = $scoreResult['score'];
                $payload['score_components'] = $scoreResult['components'];
            } else {
                $resolvedBotScore = 0;
            }

            $alertLogMinScore = (int) $minBotScore;
            if (!$testMode && $resolvedBotScore < $alertLogMinScore) {
                return;
            }

            $payload['bot_score'] = $resolvedBotScore;
            $payload['min_bot_score'] = $alertLogMinScore;
            if (!isset($payload['volume_multiplier'])) {
                $payload['volume_multiplier'] = $volumeMultiplier;
            }
            if (!isset($payload['min_effective_volume'])) {
                $payload['min_effective_volume'] = $minEffectiveVolume;
            }
            if (!isset($payload['effective_volume'])) {
                $baseLotForPayload = is_numeric($data['lot_size'] ?? null) ? (float) $data['lot_size'] : $lotSize;
                $payload['effective_volume'] = $baseLotForPayload * $volumeMultiplier;
            }
            if (!isset($payload['bot_key'])) {
                $payload['bot_key'] = $botKey;
            }
            if (!isset($payload['bot_name'])) {
                $payload['bot_name'] = $botName;
            }
            if (!isset($payload['strategies'])) {
                $payload['strategies'] = $selectedStrategyKeys;
            }
            if (!isset($payload['trend_timeframes'])) {
                $payload['trend_timeframes'] = $trendTimeframes;
            }
            if (!isset($payload['strategy_params'])) {
                $payload['strategy_params'] = $strategyParams;
            }

            BotTradeLog::query()->create(array_merge($botLogDefaults, [
                'event_type' => 'signal',
                'ai_decision' => 'not_evaluated',
                'meta_payload' => $payload,
            ], $data));
        };

        $reserveCycleCredits = function (int $cost, string $phase, string $symbol) use (&$cycleCreditsUsed, $maxCycleCredits, &$stoppedByCreditBudget, $botLogDefaults, $usesAlpacaCycle): bool {
            if ($usesAlpacaCycle) {
                return true;
            }
            if ($maxCycleCredits <= 0) {
                $cycleCreditsUsed += $cost;

                return true;
            }

            if (($cycleCreditsUsed + $cost) > $maxCycleCredits) {
                $stoppedByCreditBudget = true;
                $message = 'Cycle credit budget reached; stopping remaining symbol scans.';
                $this->warn('  '.$message.' used='.$cycleCreditsUsed.' required='.$cost.' budget='.$maxCycleCredits.' phase='.$phase.' symbol='.$symbol);

                BotTradeLog::query()->create(array_merge($botLogDefaults, [
                    'event_type' => 'guardrail',
                    'status' => 'credit_budget_stop',
                    'symbol' => $symbol,
                    'message' => $message,
                    'meta_payload' => [
                        'phase' => $phase,
                        'credit_cost' => $cost,
                        'cycle_credits_used' => $cycleCreditsUsed,
                        'max_cycle_credits' => $maxCycleCredits,
                    ],
                ]));

                return false;
            }

            $cycleCreditsUsed += $cost;

            return true;
        };

        $metaApiPauseKey = 'metaapi_outage_pause_until';
        $metaApiPauseMinutes = 3;
        $isMetaApiOutageError = static function (string $message): bool {
            $upper = strtoupper($message);

            return str_contains($upper, 'TIMEOUTERROR')
                || str_contains($upper, 'OPERATION TIMED OUT')
                || str_contains($upper, 'CURL ERROR 28')
                || str_contains($upper, 'GATEWAY TIMEOUT')
                || str_contains($upper, ' 504 ')
                || str_contains($upper, 'NOT CONNE')
                || str_contains($upper, 'NOT CONNECTED')
                || str_contains($upper, 'ECONNRESET')
                || str_contains($upper, 'CONNECTION RESET');
        };

        $logEaScanResolution = function (string $canonicalSymbol, array $resolution): void {
            $attempts = is_array($resolution['attempts'] ?? null) ? $resolution['attempts'] : [];
            if ($attempts === []) {
                return;
            }

            foreach ($attempts as $attempt) {
                if (! is_array($attempt)) {
                    continue;
                }

                $label = (string) ($attempt['instance_label'] ?? 'unknown');
                $brokerSymbol = (string) ($attempt['broker_symbol'] ?? $canonicalSymbol);
                $key = (string) ($attempt['instance_key'] ?? '-');
                $ok = (bool) ($attempt['ok'] ?? false);

                if ($ok) {
                    $this->line('    instance '.$label.' ['.$key.'] symbol '.$brokerSymbol.': quote ok');

                    continue;
                }

                $error = trim((string) ($attempt['error'] ?? 'quote unavailable'));
                $this->line('    instance '.$label.' ['.$key.'] symbol '.$brokerSymbol.': '.$error);
            }
        };

        // ── §14–§20 PER-SYMBOL SCAN LOOP (quote → strategy → filters → AI → order) ──
        foreach ($symbols as $symbol) {
            if (!$usesEaBridgeCycle) {
                $pauseUntilRaw = Cache::get($metaApiPauseKey);
                if (is_string($pauseUntilRaw) && trim($pauseUntilRaw) !== '') {
                    try {
                        $pauseUntil = \Carbon\Carbon::parse($pauseUntilRaw);
                        if ($pauseUntil->isFuture()) {
                            $remaining = now()->diffInSeconds($pauseUntil);
                            $message = 'MetaApi outage cooldown active; skipping symbol scans for this cycle.';
                            $this->warn('  '.$message.' remaining='.$remaining.'s');

                            BotTradeLog::query()->create(array_merge($botLogDefaults, [
                                'event_type' => 'guardrail',
                                'status' => 'metaapi_cooldown_skip',
                                'message' => $message,
                                'meta_payload' => [
                                    'pause_until' => $pauseUntil->toIso8601String(),
                                    'remaining_seconds' => $remaining,
                                ],
                            ]));

                            break;
                        }
                    } catch (\Throwable) {
                        Cache::forget($metaApiPauseKey);
                    }
                }
            }

            if ($scanDelayMs > 0 && $scanned > 0) {
                usleep($scanDelayMs * 1000);
            }

            $symbol = $mt5Service->baseSymbol(strtoupper((string) $symbol));
            $symbolScanBroker = $cycleBroker;
            $brokerSymbol = $symbol;
            $scanResolution = null;
            $scanMetaPayload = [];

            if ($usesEaBridgeCycle && $eaBridgeBrokerForScan !== null) {
                $scanResolution = app(EaBridgeService::class)->resolveScanBrokerDetailed(
                    $symbol,
                    $eaProfileInstanceKeys,
                    $eaBridgeBrokerForScan
                );
                $scanMetaPayload = [
                    'ea_canonical_symbol' => $scanResolution['canonical_symbol'] ?? $symbol,
                    'ea_broker_symbol' => $scanResolution['broker_symbol'] ?? null,
                    'ea_instance_key' => $scanResolution['instance_key'] ?? null,
                    'ea_instance_label' => $scanResolution['instance_label'] ?? null,
                    'ea_scan_attempts' => $scanResolution['attempts'] ?? [],
                ];

                if ($scanResolution['broker'] === null) {
                    $errorMessage = (string) ($scanResolution['error'] ?? 'Quote unavailable on all attached instances.');
                    $scanned++;
                    $this->line('  SCANNED: '.$symbol.' — no quote on attached instances');
                    $logEaScanResolution($symbol, $scanResolution);
                    Log::warning('Auto bot quote failed on all EA instances', [
                        'symbol' => $symbol,
                        'attempts' => $scanResolution['attempts'] ?? [],
                        'error' => $errorMessage,
                    ]);
                    $this->line("  {$symbol}: quote error — {$errorMessage}");
                    $logSignal([
                        'status' => 'quote_error',
                        'symbol' => $symbol,
                        'message' => 'Signal skipped due to quote retrieval failure.',
                        'error_message' => $errorMessage,
                        'meta_payload' => $scanMetaPayload,
                    ]);
                    continue;
                }

                $symbolScanBroker = $scanResolution['broker'];
                $brokerSymbol = (string) ($scanResolution['broker_symbol'] ?? $symbolScanBroker->toBrokerSymbol($symbol));
            } elseif (! $usesAlpacaCycle && ! $usesEaBridgeCycle) {
                $brokerSymbol = preg_match('/^[A-Z]{6}$/', $symbol) === 1 ? $symbol.'_SB' : $symbol;
            }

            $scanned++;
            if ($usesEaBridgeCycle && $scanResolution !== null) {
                $this->line(
                    '  SCANNED: '.$symbol.' via '
                    .($scanResolution['instance_label'] ?? 'EA instance')
                    .' ['.($scanResolution['instance_key'] ?? '-').'] as '.$brokerSymbol
                );
                if (count($scanResolution['attempts'] ?? []) > 1) {
                    $logEaScanResolution($symbol, $scanResolution);
                }
            } else {
                $this->line('  SCANNED: '.$symbol.($brokerSymbol !== $symbol ? ' ('.$brokerSymbol.')' : ''));
            }

            if (!$reserveCycleCredits($creditCostQuote, 'quote', $symbol)) {
                break;
            }

            try {
                $quote = $symbolScanBroker->getTickerPrice($symbol);
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
                Log::warning('Auto bot quote failed', ['symbol' => $symbol, 'error' => $errorMessage]);
                $this->line("  {$symbol}: quote error — {$errorMessage}");
                if ($usesEaBridgeCycle && $scanResolution !== null) {
                    $logEaScanResolution($symbol, $scanResolution);
                }
                $logSignal([
                    'status' => 'quote_error',
                    'symbol' => $symbol,
                    'message' => 'Signal skipped due to quote retrieval failure.',
                    'error_message' => $errorMessage,
                    'meta_payload' => $scanMetaPayload,
                ]);

                $upperError = strtoupper($errorMessage);
                if (str_contains($upperError, 'TOOMANYREQUESTS') || str_contains($upperError, '429')) {
                    $stoppedByRateLimit = true;
                    $this->warn('  Rate limit detected; stopping remaining symbol scans for this cycle.');
                    break;
                }

                if (!$usesAlpacaCycle && $isMetaApiOutageError($errorMessage)) {
                    $pauseUntil = now()->addMinutes($metaApiPauseMinutes);
                    Cache::put($metaApiPauseKey, $pauseUntil->toIso8601String(), $pauseUntil);
                    $this->warn('  MetaApi connectivity outage detected; pausing scans for '.$metaApiPauseMinutes.' minutes.');
                    break;
                }

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

            $ticker = $dbTickers->get($symbol);
            $tickerCategory = $ticker?->category;
            $spreadCategory = $classifySpreadCategory($symbol, $tickerCategory);
            $tickerPipOverride = is_numeric($ticker?->pip_size) ? (float) $ticker->pip_size : null;
            $pipSize = $mt5Service->resolvePipSize($symbol, is_string($tickerCategory) ? $tickerCategory : null, $tickerPipOverride);
            $pricePrecision = $mt5Service->pricePrecisionForPipSize($pipSize);

            $cacheKey = 'auto_bot_last_bid_'.preg_replace('/[^a-z0-9_]/', '_', strtolower($botKey)).'_'.preg_replace('/[^A-Z0-9_]/', '_', $symbol);
            $lastBid = Cache::get($cacheKey);
            Cache::put($cacheKey, $bid, now()->addHours(6));
            $spreadPips = ($ask - $bid) / $pipSize;
            $tickerSpreadOverride = $ticker?->max_spread_pips;
            $tickerTpOverride = $ticker?->max_tp_pips;
            $tickerSlOverride = $ticker?->max_sl_pips;
            $maxSpreadForSymbol = (float) (
                (is_numeric($tickerSpreadOverride) ? (float) $tickerSpreadOverride : null)
                ?? $maxSpreadByCategory[$spreadCategory]
                ?? $maxSpreadByCategory['default']
                ?? $maxSpreadPips
            );
            $tpPipsForSymbol = (float) (
                (is_numeric($tickerTpOverride) ? (float) $tickerTpOverride : null)
                ??
                $tpPipsByCategory[$spreadCategory]
                ?? $tpPipsByCategory['default']
                ?? $tpPips
            );
            $slPipsForSymbol = (float) (
                (is_numeric($tickerSlOverride) ? (float) $tickerSlOverride : null)
                ??
                $slPipsByCategory[$spreadCategory]
                ?? $slPipsByCategory['default']
                ?? $slPips
            );
            $minMovePipsForSymbol = $categoryValueOrDefault($minMovePipsByCategory, $spreadCategory, $minMovePips);

            $candlesForStrategy = [];
            $primaryTimeframe = $entryTimeframe;
            $requiredCandleCount = max(array_map(static fn ($s) => $s->requiredCandles(), $strategies));
            if ($requiredCandleCount > 0) {
                if (!$reserveCycleCredits($creditCostCandle, 'strategy_candles', $symbol)) {
                    break;
                }

                try {
                    $candlesForStrategy = $symbolScanBroker->getCandles($symbol, $primaryTimeframe, $requiredCandleCount);
                } catch (\Throwable $e) {
                    $skippedNoMove++;
                    $logSignal([
                        'status' => 'strategy_data_error',
                        'symbol' => $symbol,
                        'spread_pips' => $spreadPips,
                        'message' => 'Signal skipped because strategy data could not be loaded.',
                        'error_message' => $e->getMessage(),
                        'meta_payload' => [
                            'strategies' => $selectedStrategyKeys,
                            'strategy_timeframe' => $primaryTimeframe,
                            'strategy_params' => $strategyParams,
                        ],
                    ]);
                    continue;
                }
            }

            if ($testMode) {
                $delta = isset($lastBid) && is_numeric($lastBid) ? ($bid - (float) $lastBid) : 0.0;
                $side = $delta >= 0 ? 'buy' : 'sell';
                if ($usesAlpacaCycle) {
                    $heldQty = (float) ($openQtyBySymbol[strtoupper($symbol)] ?? $openQtyBySymbol[$cycleBroker->baseSymbol($symbol)] ?? 0);
                    if ($side === 'sell' && $heldQty <= 0) {
                        $side = 'buy';
                    }
                }
                $signalDeltaPips = $pipSize > 0 ? abs($delta / $pipSize) : 0.0;
            } else {
                // ── §15 STRATEGY EVALUATION (all selected strategies must agree) ─────
                $strategyResults = [];
                $strategySides = [];
                $strategySignalStrengths = [];
                $strategyDebug = [];
                $strategyConsoleLines = [];
                $strategyRejectedKeys = [];
                $strategyInvalidSideKeys = [];

                foreach ($strategies as $strategy) {
                    $result = $strategy->evaluate([
                        'symbol' => $symbol,
                        'bid' => $bid,
                        'ask' => $ask,
                        'last_bid' => $lastBid,
                        'pip_size' => $pipSize,
                        'min_move_pips' => $minMovePipsForSymbol,
                        'strategy_params' => $strategyParams,
                        'candles' => $candlesForStrategy,
                        'timeframe' => $primaryTimeframe,
                    ]);

                    $strategyKey = $strategy->key();
                    $strategyResults[$strategyKey] = $result;

                    $signaled = (bool) ($result['signal'] ?? false);
                    $resultStatus = (string) ($result['status'] ?? ($signaled ? 'signal' : 'strategy_rejected'));
                    $resultMessage = trim((string) ($result['message'] ?? ''));
                    $resultSide = strtolower(trim((string) ($result['side'] ?? '')));
                    $resultDeltaPips = isset($result['signal_delta_pips']) && is_numeric($result['signal_delta_pips'])
                        ? abs((float) $result['signal_delta_pips'])
                        : null;

                    $strategyDebug[$strategyKey] = [
                        'signal' => $signaled,
                        'status' => $resultStatus,
                        'side' => $resultSide !== '' ? $resultSide : null,
                        'signal_delta_pips' => $resultDeltaPips,
                        'message' => $resultMessage,
                    ];

                    if ($signaled) {
                        if (!in_array($resultSide, ['buy', 'sell'], true)) {
                            $strategyInvalidSideKeys[] = $strategyKey;
                            $strategyConsoleLines[] = "{$strategyKey}: INVALID_SIDE status={$resultStatus}";
                            continue;
                        }

                        $strategySides[] = $resultSide;
                        $strategySignalStrengths[] = $resultDeltaPips ?? 0.0;
                        $deltaText = $resultDeltaPips !== null ? number_format($resultDeltaPips, 2).'pip' : 'n/a';
                        $strategyConsoleLines[] = "{$strategyKey}: OK side={$resultSide} move={$deltaText}";
                        continue;
                    }

                    $strategyRejectedKeys[] = $strategyKey;
                    $reasonText = $resultMessage !== '' ? $resultMessage : 'conditions not met';
                    $strategyConsoleLines[] = "{$strategyKey}: NO status={$resultStatus} reason={$reasonText}";
                }

                $this->line("  {$symbol}: strategy diagnostics");
                foreach ($strategyConsoleLines as $strategyConsoleLine) {
                    $this->line('    - '.$strategyConsoleLine);
                }

                if (!empty($strategyRejectedKeys)) {
                    $skippedNoMove++;
                    $logSignal([
                        'status' => 'strategy_rejected',
                        'symbol' => $symbol,
                        'spread_pips' => $spreadPips,
                        'message' => 'Signal rejected because one or more selected strategies did not confirm: '.implode(', ', $strategyRejectedKeys).'.',
                        'meta_payload' => [
                            'strategies' => $selectedStrategyKeys,
                            'strategy_timeframe' => $primaryTimeframe,
                            'strategy_params' => $strategyParams,
                            'strategy_results' => $strategyResults,
                            'strategy_debug' => $strategyDebug,
                            'strategy_rejected_keys' => array_values($strategyRejectedKeys),
                        ],
                    ]);
                    continue;
                }

                if (!empty($strategyInvalidSideKeys)) {
                    $skippedNoMove++;
                    $logSignal([
                        'status' => 'strategy_invalid_side',
                        'symbol' => $symbol,
                        'spread_pips' => $spreadPips,
                        'message' => 'Signal rejected because one or more strategies returned an invalid side: '.implode(', ', $strategyInvalidSideKeys).'.',
                        'meta_payload' => [
                            'strategies' => $selectedStrategyKeys,
                            'strategy_timeframe' => $primaryTimeframe,
                            'strategy_params' => $strategyParams,
                            'strategy_results' => $strategyResults,
                            'strategy_debug' => $strategyDebug,
                            'strategy_invalid_side_keys' => array_values($strategyInvalidSideKeys),
                        ],
                    ]);
                    continue;
                }

                if (count(array_unique($strategySides)) !== 1) {
                    $skippedNoMove++;
                    $logSignal([
                        'status' => 'strategy_conflict_rejected',
                        'symbol' => $symbol,
                        'spread_pips' => $spreadPips,
                        'message' => 'Signal rejected because selected strategies disagree on direction.',
                        'meta_payload' => [
                            'strategies' => $selectedStrategyKeys,
                            'strategy_timeframe' => $primaryTimeframe,
                            'strategy_params' => $strategyParams,
                            'strategy_results' => $strategyResults,
                            'strategy_debug' => $strategyDebug,
                        ],
                    ]);
                    continue;
                }

                $side = $strategySides[0];
                $signalDeltaPips = !empty($strategySignalStrengths)
                    ? (float) (array_sum($strategySignalStrengths) / count($strategySignalStrengths))
                    : 0.0;
            }

            $adxValue = null;
            $rsiHtfValue = null;
            $rsiEntryValue = null;
            $indicatorPeriod = 14;
            $adxMinBars = 60;
            $rsiMinBars = 30;

            if (!$testMode && ($useAdxScore || $useRsiScore)) {
                if ($useAdxScore) {
                    $adxCandles = count($candlesForStrategy) >= ($indicatorPeriod * 2)
                        ? $candlesForStrategy
                        : [];

                    if (count($adxCandles) < $adxMinBars) {
                        if ($reserveCycleCredits($creditCostCandle, 'adx_entry_'.$entryTimeframe, $symbol)) {
                            try {
                                $adxCandles = $symbolScanBroker->getCandles($symbol, $entryTimeframe, $adxMinBars);
                            } catch (\Throwable $e) {
                                Log::warning('Auto bot ADX candle fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
                                $adxCandles = [];
                            }
                        }
                    }

                    if (!empty($adxCandles)) {
                        $adxValue = IndicatorMath::adx($adxCandles, $indicatorPeriod);
                    }
                }

                if ($useRsiScore) {
                    $rsiHtfTimeframe = $trendContextTimeframes[0] ?? '1h';
                    if ($reserveCycleCredits($creditCostCandle, 'rsi_htf_'.$rsiHtfTimeframe, $symbol)) {
                        try {
                            $rsiHtfCandles = $symbolScanBroker->getCandles($symbol, $rsiHtfTimeframe, $rsiMinBars);
                            $rsiHtfValue = IndicatorMath::rsi(IndicatorMath::closeSeries($rsiHtfCandles), $indicatorPeriod);
                        } catch (\Throwable $e) {
                            Log::warning('Auto bot RSI HTF candle fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
                        }
                    }

                    $rsiEntryCandles = count($candlesForStrategy) >= ($indicatorPeriod + 1)
                        ? $candlesForStrategy
                        : [];
                    if (count($rsiEntryCandles) < $rsiMinBars) {
                        if ($reserveCycleCredits($creditCostCandle, 'rsi_entry_'.$entryTimeframe, $symbol)) {
                            try {
                                $rsiEntryCandles = $symbolScanBroker->getCandles($symbol, $entryTimeframe, $rsiMinBars);
                            } catch (\Throwable $e) {
                                Log::warning('Auto bot RSI entry candle fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
                                $rsiEntryCandles = [];
                            }
                        }
                    }

                    if (!empty($rsiEntryCandles)) {
                        $rsiEntryValue = IndicatorMath::rsi(IndicatorMath::closeSeries($rsiEntryCandles), $indicatorPeriod);
                    }
                }
            }

            $effectiveVolume = $lotSize * $volumeMultiplier;
            $scoreResult = $calculateBotScore(
                $signalDeltaPips,
                $spreadPips,
                $spreadCategory,
                $maxSpreadForSymbol,
                $slPipsForSymbol,
                $side,
                $adxValue,
                $rsiHtfValue,
                $rsiEntryValue,
            );
            $botScore = $scoreResult['score'];
            $scoreMetaPayload = static fn () => [
                'bot_score' => $botScore,
                'min_bot_score' => $minBotScore,
                'score_components' => $scoreResult['components'],
                'spread_category' => $spreadCategory,
                'max_spread_pips_for_symbol' => $maxSpreadForSymbol,
                'sl_pips_for_symbol' => $slPipsForSymbol,
                'volume_multiplier' => $volumeMultiplier,
                'effective_volume' => $effectiveVolume,
                'min_effective_volume' => $minEffectiveVolume,
            ];

            if (!$testMode && $scoreResult['hard_reject']) {
                $this->line("  {$symbol}: ADX ".($adxValue !== null ? number_format($adxValue, 1) : 'n/a').' below floor — skipped');
                $skippedAdxRejected++;
                $logSignal([
                    'status' => 'adx_rejected',
                    'symbol' => $symbol,
                    'side' => $side,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'meta_payload' => $scoreMetaPayload(),
                    'message' => 'Signal rejected because ADX is below the minimum trend-strength floor.',
                ]);
                continue;
            }

            if (!$testMode && $effectiveVolume < $minEffectiveVolume) {
                $this->line("  {$symbol}: VOLUME {$effectiveVolume} < min {$minEffectiveVolume} — skipped");
                $skippedLowVolume++;
                $logSignal([
                    'status' => 'low_volume_rejected',
                    'symbol' => $symbol,
                    'side' => $side,
                    'lot_size' => $lotSize,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'meta_payload' => $scoreMetaPayload(),
                ]);
                continue;
            }

            if (isset($openBySymbol[$symbol])) {
                $skippedOpen++;
                if ($testMode || $botScore >= $minBotScore) {
                    $logSignal([
                        'status' => 'open_position_rejected',
                        'symbol' => $symbol,
                        'side' => $side,
                        'spread_pips' => $spreadPips,
                        'signal_delta_pips' => $signalDeltaPips,
                        'meta_payload' => $scoreMetaPayload(),
                        'message' => 'Signal skipped because symbol already has an open position.',
                    ]);
                }
                continue;
            }

            if (!$testMode && $botScore < $minBotScore) {
                $this->line("  {$symbol}: SCORE {$botScore}% < min {$minBotScore}% — skipped");
                $skippedLowScore++;
                $logSignal([
                    'status' => 'low_score_rejected',
                    'symbol' => $symbol,
                    'side' => $side,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'meta_payload' => $scoreMetaPayload(),
                    'message' => 'Signal rejected because bot score is below minimum threshold.',
                ]);
                continue;
            }

            if (!$testMode && $spreadPips > $maxSpreadForSymbol) {
                $this->line("  {$symbol}: SPREAD {$spreadPips}pip > max {$maxSpreadForSymbol}pip ({$spreadCategory}) — skipped");
                $skippedSpread++;
                $logSignal([
                    'status' => 'spread_rejected',
                    'symbol' => $symbol,
                    'side' => $side,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'meta_payload' => array_merge($scoreMetaPayload(), [
                        'ticker_category' => $tickerCategory,
                        'ticker_spread_override' => is_numeric($tickerSpreadOverride) ? (float) $tickerSpreadOverride : null,
                        'ticker_tp_override' => is_numeric($tickerTpOverride) ? (float) $tickerTpOverride : null,
                        'ticker_sl_override' => is_numeric($tickerSlOverride) ? (float) $tickerSlOverride : null,
                        'max_spread_pips_by_category' => $maxSpreadByCategory,
                    ]),
                    'message' => 'Signal rejected due to spread filter.',
                ]);
                continue;
            }

            $symbolKey = strtoupper($symbol);
            $symbolTradesToday = (int) ($openedTodayBySymbol[$symbolKey] ?? 0);
            // ── Per-asset daily limit (default max-trades-per-asset-per-day=2) ────────
            if (!$testMode && $symbolTradesToday >= $maxTradesPerAssetPerDay) {
                $this->line("  {$symbol}: ASSET DAILY LIMIT — {$symbolTradesToday}/{$maxTradesPerAssetPerDay} trades today");
                $skippedAssetDailyLimit++;
                $logSignal([
                    'status' => 'asset_daily_limit_rejected',
                    'symbol' => $symbol,
                    'side' => $side,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'meta_payload' => [
                        'symbol_trades_today' => $symbolTradesToday,
                        'max_trades_per_asset_per_day' => $maxTradesPerAssetPerDay,
                    ],
                    'message' => 'Signal rejected because this symbol reached the daily trade limit.',
                ]);
                continue;
            }

            $lastSuccessfulTrade = BotTradeLog::query()
                ->where('bot_key', $botKey)
                ->where('event_type', 'trade_open')
                ->where('status', 'success')
                ->where('symbol', $symbol)
                ->latest('created_at')
                ->first();

            if (!$testMode && $lastSuccessfulTrade && $cooldownMinutes > 0 && $lastSuccessfulTrade->created_at?->gt(now()->subMinutes($cooldownMinutes))) {
                $remaining = now()->diffInSeconds($lastSuccessfulTrade->created_at->addMinutes($cooldownMinutes));
                $lastSuccessfulMovePips = abs((float) ($lastSuccessfulTrade->signal_delta_pips ?? 0));
                $currentMovePips = abs($signalDeltaPips);
                $requiredOverrideMovePips = $cooldownOverrideRatio > 0
                    ? ($lastSuccessfulMovePips * $cooldownOverrideRatio)
                    : null;
                $canOverrideCooldown = $cooldownOverrideRatio > 0
                    && $lastSuccessfulMovePips > 0
                    && $currentMovePips >= $requiredOverrideMovePips;

                if (!$canOverrideCooldown) {
                    $this->line("  {$symbol}: COOLDOWN — {$remaining}s remaining");
                    $skippedCooldown++;
                    $logSignal([
                        'status' => 'cooldown_rejected',
                        'symbol' => $symbol,
                        'side' => $side,
                        'spread_pips' => $spreadPips,
                        'signal_delta_pips' => $signalDeltaPips,
                        'meta_payload' => [
                            'last_successful_signal_delta_pips' => $lastSuccessfulMovePips,
                            'cooldown_override_ratio' => $cooldownOverrideRatio,
                            'required_override_move_pips' => $requiredOverrideMovePips,
                            'remaining_cooldown_seconds' => $remaining,
                        ],
                        'message' => 'Signal rejected due to symbol cooldown.',
                    ]);
                    continue;
                }

                $this->line("  {$symbol}: cooldown override — move=".number_format($currentMovePips, 2)."pip required=".number_format((float) $requiredOverrideMovePips, 2)."pip");
            }

            if (!$testMode && $useTrendFilter) {
                // ── §18 TREND FILTER (context + entry timeframe alignment) ────────────
                try {
                    $trendByTimeframe = [];
                    foreach ($trendContextTimeframes as $timeframe) {
                        if (!$reserveCycleCredits($creditCostCandle, 'trend_context_'.$timeframe, $symbol)) {
                            break 2;
                        }

                        $candles = $symbolScanBroker->getCandles($symbol, $timeframe, 1);
                        $trendByTimeframe[$timeframe] = $resolveTrendSide($candles);
                    }

                    $aligned = collect($trendByTimeframe)->every(static fn ($trend) => $trend === $side);
                    if (!$aligned) {
                        $logSignal([
                            'status' => 'trend_rejected',
                            'symbol' => $symbol,
                            'side' => $side,
                            'spread_pips' => $spreadPips,
                            'signal_delta_pips' => $signalDeltaPips,
                            'meta_payload' => [
                                'trend_by_timeframe' => $trendByTimeframe,
                                'trend_filter' => true,
                                'trend_timeframes' => $trendTimeframes,
                                'trend_context_timeframes' => $trendContextTimeframes,
                                'entry_timeframe' => $entryTimeframe,
                            ],
                            'message' => 'Signal rejected because higher timeframe trend context is not aligned with the signal side.',
                        ]);
                        continue;
                    }

                    if (!$reserveCycleCredits($creditCostCandle, 'entry_timeframe_'.$entryTimeframe, $symbol)) {
                        break;
                    }

                    $entryCandles = $symbolScanBroker->getCandles($symbol, $entryTimeframe, 1);
                    $entryTrendSide = $resolveTrendSide($entryCandles);
                    if ($entryTrendSide !== $side) {
                        $logSignal([
                            'status' => 'entry_timeframe_wait',
                            'symbol' => $symbol,
                            'side' => $side,
                            'spread_pips' => $spreadPips,
                            'signal_delta_pips' => $signalDeltaPips,
                            'meta_payload' => [
                                'trend_filter' => true,
                                'trend_timeframes' => $trendTimeframes,
                                'trend_context_timeframes' => $trendContextTimeframes,
                                'trend_by_timeframe' => $trendByTimeframe,
                                'entry_timeframe' => $entryTimeframe,
                                'entry_timeframe_trend' => $entryTrendSide,
                            ],
                            'message' => 'Signal is waiting for entry timeframe trigger alignment.',
                        ]);
                        continue;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Auto bot trend filter failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
                    $this->warn("  {$symbol}: trend filter data unavailable - {$e->getMessage()}");
                    $logSignal([
                        'status' => 'trend_rejected',
                        'symbol' => $symbol,
                        'side' => $side,
                        'spread_pips' => $spreadPips,
                        'signal_delta_pips' => $signalDeltaPips,
                        'error_message' => $e->getMessage(),
                        'meta_payload' => [
                            'trend_filter' => true,
                            'trend_timeframes' => $trendTimeframes,
                            'trend_context_timeframes' => $trendContextTimeframes,
                            'entry_timeframe' => $entryTimeframe,
                        ],
                        'message' => 'Signal rejected because trend filter data was unavailable.',
                    ]);
                    continue;
                }
            }

            $recommendedSide = $side;
            $executionSide = $reverseStrategy
                ? ($recommendedSide === 'buy' ? 'sell' : 'buy')
                : $recommendedSide;

            $this->line("  {$symbol}: signal {$recommendedSide}".($executionSide !== $recommendedSide ? " => executing {$executionSide}" : '').' — move='.number_format($signalDeltaPips, 2).'pip spread='.number_format($spreadPips, 2)."pip bid={$bid} ask={$ask}");

            if ($executionSide === 'buy') {
                $entry = $ask;
                $takeProfit = round($entry + ($tpPipsForSymbol * $pipSize), $pricePrecision);
                $stopLoss = round($entry - ($slPipsForSymbol * $pipSize), $pricePrecision);
            } else {
                $entry = $bid;
                $takeProfit = round($entry - ($tpPipsForSymbol * $pipSize), $pricePrecision);
                $stopLoss = round($entry + ($slPipsForSymbol * $pipSize), $pricePrecision);
            }

            $aiProvider = null;
            $aiDecision = 'approve';
            $aiConfidence = null;
            $aiSummary = null;

            if (!$testMode && $useAiConfirm) {
                // ── §19 AI CONFIRMATION ───────────────────────────────────────────────
                try {
                    // Fetch candle context from MetaAPI for richer AI analysis.
                    $candleContext = '';
                    try {
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

                        foreach ($aiContextTimeframes as $index => $timeframe) {
                            $limit = $index === 0 ? 20 : 10;
                            if (!$reserveCycleCredits($creditCostCandle, 'ai_context_'.$timeframe, $symbol)) {
                                break 2;
                            }

                            $candles = $symbolScanBroker->getCandles($symbol, $timeframe, $limit);
                            if (!empty($candles)) {
                                $candleContext .= "\n\nLast ".count($candles)." x ".strtoupper($timeframe)." candles (oldest first):\n".$formatCandles($candles);
                            }
                        }
                    } catch (\Throwable $candleError) {
                        Log::warning('Auto bot candle fetch failed', ['symbol' => $symbol, 'error' => $candleError->getMessage()]);
                    }

                    $this->line("  {$symbol}: asking AI ({$symbol} recommended={$recommendedSide} executing={$executionSide} entry={$entry} TP={$takeProfit} SL={$stopLoss})...");

                    $strategyLine = $scalperMode
                        ? 'This is a scalping trade. Prefer quick in-and-out setups and reject slow/unclear setups.'
                        : 'This is a standard intraday trade.';

                    $prompt = "You are validating an automated forex trade. Reply strictly with one line starting with APPROVE or REJECT, then a short reason. "
                        ."Include a confidence percentage like 'Confidence: 85%' in your reply. "
                        .$strategyLine.' '
                        ."Symbol: {$symbol}. Recommended side: {$recommendedSide}. Executing side: {$executionSide}. Entry: {$entry}. TP: {$takeProfit}. SL: {$stopLoss}. "
                        ."Spread pips: ".number_format($spreadPips, 2).". Signal move pips: ".number_format($signalDeltaPips, 2).". "
                        ."TP pips: ".number_format($tpPipsForSymbol, 2).". SL pips: ".number_format($slPipsForSymbol, 2)."."
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
                    'side' => $executionSide,
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
                    'meta_payload' => array_merge($scoreMetaPayload(), [
                        'recommended_side' => $recommendedSide,
                        'execution_side' => $executionSide,
                        'ticker_category' => $tickerCategory,
                        'tp_pips_for_symbol' => $tpPipsForSymbol,
                    ]),
                    'message' => 'Signal rejected by AI confirmation.',
                ]);
                continue;
            }

            $logSignal([
                'status' => 'confirmed',
                'symbol' => $symbol,
                'side' => $executionSide,
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
                'meta_payload' => array_merge($scoreMetaPayload(), [
                    'recommended_side' => $recommendedSide,
                    'execution_side' => $executionSide,
                    'ticker_category' => $tickerCategory,
                    'tp_pips_for_symbol' => $tpPipsForSymbol,
                ]),
                'message' => 'Signal passed all filters and AI confirmation.',
            ]);

            if ($usesAlpacaCycle) {
                $heldQty = (float) ($openQtyBySymbol[strtoupper($symbol)] ?? $openQtyBySymbol[$cycleBroker->baseSymbol($symbol)] ?? 0);
                if ($executionSide === 'sell' && $heldQty <= 0) {
                    $this->line("  {$symbol}: SELL skipped — Alpaca spot crypto cannot short without holdings.");
                    $logSignal([
                        'status' => 'alpaca_short_not_supported',
                        'symbol' => $symbol,
                        'side' => $executionSide,
                        'spread_pips' => $spreadPips,
                        'signal_delta_pips' => $signalDeltaPips,
                        'meta_payload' => array_merge($scoreMetaPayload(), [
                            'recommended_side' => $recommendedSide,
                            'execution_side' => $executionSide,
                        ]),
                        'message' => 'Sell signal skipped because Alpaca spot crypto cannot open a short without existing holdings.',
                    ]);
                    continue;
                }

                if ($executionSide === 'buy') {
                    $requiredNotional = $alpacaService->estimateBuyNotionalUsd($symbol, $lotSize, $ask);
                    $availableCash = (float) ($accountCash ?? 0);
                    if ($requiredNotional > 0 && $availableCash > 0 && $requiredNotional > ($availableCash * 0.995)) {
                        $this->line(
                            "  {$symbol}: BUY skipped — insufficient cash (need ~$"
                            .number_format($requiredNotional, 2)
                            .', have ~$'
                            .number_format($availableCash, 2)
                            .').'
                        );
                        $logSignal([
                            'status' => 'insufficient_balance',
                            'symbol' => $symbol,
                            'side' => $executionSide,
                            'spread_pips' => $spreadPips,
                            'signal_delta_pips' => $signalDeltaPips,
                            'meta_payload' => array_merge($scoreMetaPayload(), [
                                'recommended_side' => $recommendedSide,
                                'execution_side' => $executionSide,
                                'required_notional_usd' => round($requiredNotional, 2),
                                'available_cash_usd' => round($availableCash, 2),
                            ]),
                            'message' => 'Buy skipped because Alpaca account cash is below required order notional.',
                        ]);
                        continue;
                    }
                }
            }

            try {
                // ── §20 TRADE EXECUTION (broker placeOrder) ───────────────────────────
                $executionBrokers = [$cycleBroker];
                if ($usesEaBridgeCycle && count($eaProfileInstanceKeys) > 1) {
                    $executionBrokers = [];
                    $eaBridgeBroker = app(EaBridgeBroker::class);
                    foreach ($eaProfileInstanceKeys as $instanceKey) {
                        try {
                            $executionBrokers[] = $eaBridgeBroker->forInstance($instanceKey);
                        } catch (\Throwable $instanceError) {
                            $this->warn('  EA instance '.$instanceKey.' skipped for execution: '.$instanceError->getMessage());
                        }
                    }

                    if ($executionBrokers === []) {
                        throw new \RuntimeException('No selected EA instances are online for execution.');
                    }
                }

                $exitLegs = [[
                    'close_percent' => 100,
                    'take_profit' => $takeProfit,
                    'stop_loss' => $stopLoss,
                ]];

                $opened++;
                $openBySymbol[$symbol] = true;
                $openedTodayBySymbol[$symbolKey] = $symbolTradesToday + 1;

                foreach ($executionBrokers as $brokerIndex => $execBroker) {
                    $result = $execBroker->placeOrder($symbol, $lotSize, $executionSide, $exitLegs);
                    $isEaQueued = ($result['mode'] ?? '') === 'ea_queued';
                    $firstOrder = is_array($result['orders'][0] ?? null) ? $result['orders'][0] : null;
                    $firstResponse = is_array($firstOrder['response'] ?? null) ? $firstOrder['response'] : [];
                    $orderId = trim((string) ($firstResponse['orderId'] ?? ''));
                    $positionId = trim((string) ($firstResponse['positionId'] ?? ''));
                    $tradeRef = $positionId !== '' ? $positionId : ($orderId !== '' ? $orderId : (string) $symbol);
                    $executedLotSize = is_numeric($result['order_qty'] ?? null) ? (float) $result['order_qty'] : $lotSize;

                    $openMessage = ($isEaQueued ? 'Queued ' : 'Opened ').$executionSide.' '.$symbol.' (recommended '.$recommendedSide.') TP='.$takeProfit.' SL='.$stopLoss;
                    if (count($executionBrokers) > 1) {
                        $execLabel = method_exists($execBroker, 'instanceLabel') ? (string) $execBroker->instanceLabel() : ('instance '.($brokerIndex + 1));
                        $execKey = method_exists($execBroker, 'instanceKey') ? (string) ($execBroker->instanceKey() ?? '-') : '-';
                        $openMessage .= ' → '.$execLabel.' ['.$execKey.']';
                    }
                    if ($isEaQueued) {
                        $queuedSymbol = Mt5EaCommand::query()->where('id', (int) ($result['command_id'] ?? 0))->value('symbol');
                        if (is_string($queuedSymbol) && $queuedSymbol !== '') {
                            $openMessage .= ' as '.$queuedSymbol;
                        }
                        $openMessage .= ' [EA command #'.(int) ($result['command_id'] ?? 0).']';
                    }
                    if ($executedLotSize > $lotSize) {
                        $openMessage .= ' qty='.$executedLotSize.' (profile lot '.$lotSize.' bumped for Alpaca $10 min notional)';
                    }
                    $this->info($openMessage);
                    Log::info('Auto bot opened trade', [
                        'symbol' => $symbol,
                        'recommended_side' => $recommendedSide,
                        'side' => $executionSide,
                        'result' => $result,
                        'broker_index' => $brokerIndex,
                    ]);

                    $tradeLog = BotTradeLog::query()->create(array_merge($botLogDefaults, [
                        'event_type' => 'trade_open',
                        'status' => $isEaQueued ? 'pending' : 'success',
                        'symbol' => $symbol,
                        'side' => $executionSide,
                        'order_id' => $orderId !== '' ? $orderId : null,
                        'position_id' => $positionId !== '' ? $positionId : null,
                        'linked_trade' => 'TRADE #'.$tradeRef,
                        'trade_outcome' => 'PENDING',
                        'lot_size' => $executedLotSize,
                        'entry_price' => $entry,
                        'take_profit' => $takeProfit,
                        'stop_loss' => $stopLoss,
                        'spread_pips' => $spreadPips,
                        'signal_delta_pips' => $signalDeltaPips,
                        'ai_provider' => $aiProvider,
                        'ai_decision' => $aiDecision,
                        'ai_confidence' => $aiConfidence,
                        'ai_summary' => $aiSummary,
                        'meta_payload' => array_merge($scoreMetaPayload(), [
                            'recommended_side' => $recommendedSide,
                            'execution_side' => $executionSide,
                            'execution_mode' => $isEaQueued ? 'ea_bridge' : ($usesAlpacaCycle ? 'alpaca' : 'metaapi'),
                            'ea_command_id' => $isEaQueued ? (int) ($result['command_id'] ?? 0) : null,
                            'mirrored_instance_index' => count($executionBrokers) > 1 ? $brokerIndex : null,
                            'mirrored_instance_total' => count($executionBrokers) > 1 ? count($executionBrokers) : null,
                        ]),
                        'meta_response' => $result,
                        'message' => $isEaQueued
                            ? ($brokerIndex === 0
                                ? 'Trade queued for EA bridge execution.'
                                : 'Trade mirrored to additional EA instance.')
                            : 'Trade opened via broker API.',
                    ]));

                    if ($isEaQueued && ! empty($result['command_id'])) {
                        Mt5EaCommand::query()
                            ->where('id', (int) $result['command_id'])
                            ->update([
                                'bot_trade_log_id' => $tradeLog->id,
                                'bot_key' => $botKey,
                            ]);
                    }
                }

                if (!$usesEaBridgeCycle) {
                    // Run one immediate trailing pass so new trades do not wait for the next cycle.
                    try {
                        $postOpenTrail = $mt5Service->applyTrailingStops($trailStartPips, $trailPips, $trailTpMultiplier, $resolveTrailingParamsForSymbol);
                        if (($postOpenTrail['updated'] ?? 0) > 0) {
                            $this->line('Post-open trailing updated: '.$postOpenTrail['updated']);
                        }
                    } catch (\Throwable $trailError) {
                        $this->warn('Post-open trailing pass failed: '.$trailError->getMessage());
                    }
                }

                if ($opened >= $maxPerCycle) {
                    $this->line('Per-cycle trade limit reached ('.$maxPerCycle.'). Waiting for next cycle.');
                    break;
                }
            } catch (\Throwable $e) {
                $this->warn("  {$symbol}: trade failed — ".$e->getMessage());
                Log::warning('Auto bot trade failed', [
                    'symbol' => $symbol,
                    'recommended_side' => $recommendedSide,
                    'side' => $executionSide,
                    'error' => $e->getMessage(),
                ]);
                BotTradeLog::query()->create(array_merge($botLogDefaults, [
                    'event_type' => 'trade_open',
                    'status' => 'failed',
                    'symbol' => $symbol,
                    'side' => $executionSide,
                
                    'trade_outcome' => 'FAILED',
                    'trade_resolved_at' => now(),

                   
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
                    'meta_payload' => array_merge($scoreMetaPayload(), [
                        'recommended_side' => $recommendedSide,
                        'execution_side' => $executionSide,
                    ]),
                    'message' => 'Trade open failed.',
                    'error_message' => $e->getMessage(),
                ]));
            }
        }

        // ── §25 CYCLE SUMMARY (console output + guardrail/cycle_complete log) ────────
        $this->info(
            'Cycle complete ['.$botName.']. Scanned='.$scanned
            .' opened='.$opened
            .' noMove='.$skippedNoMove
            .' spread='.$skippedSpread
            .' lowScore='.$skippedLowScore
            .' adxRejected='.$skippedAdxRejected
            .' lowVolume='.$skippedLowVolume
            .' cooldown='.$skippedCooldown
            .' assetDailyLimit='.$skippedAssetDailyLimit
            .' hasOpen='.$skippedOpen
            .' rateLimitStop='.(int) $stoppedByRateLimit
            .' creditStop='.(int) $stoppedByCreditBudget
            .' creditUsed='.$cycleCreditsUsed
            .' creditBudget='.($maxCycleCredits > 0 ? $maxCycleCredits : 'off')
        );

        BotTradeLog::query()->create(array_merge($botLogDefaults, [
            'event_type' => 'guardrail',
            'status' => 'cycle_complete',
            'message' => 'Cycle complete with diagnostic counters recorded.',
            'meta_payload' => [
                'scanned' => $scanned,
                'opened' => $opened,
                'skipped_no_move' => $skippedNoMove,
                'skipped_spread' => $skippedSpread,
                'skipped_low_score' => $skippedLowScore,
                'skipped_adx_rejected' => $skippedAdxRejected,
                'skipped_low_volume' => $skippedLowVolume,
                'skipped_cooldown' => $skippedCooldown,
                'skipped_asset_daily_limit' => $skippedAssetDailyLimit,
                'skipped_open_position' => $skippedOpen,
                'stopped_by_rate_limit' => $stoppedByRateLimit,
                'stopped_by_credit_budget' => $stoppedByCreditBudget,
                'cycle_credits_used' => $cycleCreditsUsed,
                'max_cycle_credits' => $maxCycleCredits,
                'session_start_utc' => $sessionStartUtc,
                'session_end_utc' => $sessionEndUtc,
                'current_hour_utc' => $currentHourUtc,
                'in_session' => $inSession,
                'test_mode' => $testMode,
                'scalper_mode' => $scalperMode,
                'strategies' => $selectedStrategyKeys,
                'strategy_params' => $strategyParams,
                'trend_filter' => $useTrendFilter,
                'trend_timeframes' => $trendTimeframes,
                'trend_context_timeframes' => $trendContextTimeframes,
                'entry_timeframe' => $entryTimeframe,
                'ai_confirm' => $useAiConfirm,
                'max_symbols' => $maxSymbols,
                'max_per_cycle' => $maxPerCycle,
                'max_open_positions' => $maxOpenPositions,
                'min_move_pips' => $minMovePips,
                'min_move_pips_by_category' => $minMovePipsByCategory,
                'max_spread_pips' => $maxSpreadPips,
                'trail_start_pips_by_category' => $trailStartPipsByCategory,
                'trail_pips_by_category' => $trailPipsByCategory,
                'trail_tp_multiplier_by_category' => $trailTpMultiplierByCategory,
                'preferred_hours_utc' => $preferredHoursUtc,
                'blocked_hours_utc' => $blockedHoursUtc,
                'preferred_symbols' => $preferredSymbols,
            ],
        ]));

        return 0;
    };

    // ── $runAllBots — run $runCycle for each enabled profile; --once or 60s loop ─
    $runAllBots = function () use ($resolveProfiles, $runCycle) {
        $profiles = $resolveProfiles();
        if (empty($profiles)) {
            $this->error('No enabled bot profiles found.');
            return 1;
        }

        foreach ($profiles as $profile) {
            $code = $runCycle($profile);
            if ($code !== 0) {
                return $code;
            }
        }

        return 0;
    };

    if ($this->option('once')) {
        return $runAllBots();
    }

    while (true) {
        $code = $runAllBots();
        if ($code !== 0) {
            return $code;
        }

        $this->line('Waiting 60 seconds for next cycle...');
        sleep(60);
    }
})->purpose('Run automated forex trading with TP/SL and trailing stop on MetaApi');

// ── §26 mt5:learn-policy — analyze history; recommend/apply profile tuning ───────
Artisan::command('mt5:learn-policy
    {--bot= : Bot profile key/name to tune}
    {--lookback-days=30 : Number of days to analyze}
    {--min-resolved=20 : Minimum resolved trades needed before applying changes}
    {--apply : Apply recommendation to selected bot profile}
', function (Mt5Service $mt5Service) {
    $settings = AppSetting::singleton();
    $lookbackDays = max(7, (int) $this->option('lookback-days'));
    $minResolved = max(10, (int) $this->option('min-resolved'));
    $apply = (bool) $this->option('apply');

    $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];
    $hasStoredProfiles = !empty($profiles);
    if (!$hasStoredProfiles) {
        $profiles = [[
            'key' => 'default',
            'name' => 'Default Bot',
            'enabled' => true,
        ]];
    }

    $selectedBot = strtolower(trim((string) $this->option('bot')));
    if ($selectedBot === '') {
        $latestBotKey = strtolower((string) (BotTradeLog::query()->latest()->value('bot_key') ?? ''));
        $selectedBot = $latestBotKey !== '' ? $latestBotKey : strtolower((string) ($profiles[0]['key'] ?? ''));
    }

    $profileIndex = collect($profiles)->search(static function (array $profile) use ($selectedBot): bool {
        $key = strtolower((string) ($profile['key'] ?? ''));
        $name = strtolower((string) ($profile['name'] ?? ''));
        return $key === $selectedBot || $name === $selectedBot;
    });

    if ($profileIndex === false) {
        $this->error('Bot profile not found for selector: '.$selectedBot);
        return 1;
    }

    $profile = $profiles[$profileIndex];
    $botKey = (string) ($profile['key'] ?? 'default');
    $botName = (string) ($profile['name'] ?? $botKey);
    $botLogDefaults = [
        'bot_key' => $botKey,
        'bot_name' => $botName,
    ];

    $normalizeSymbolForMatch = static function (?string $rawSymbol): string {
        $symbol = strtoupper(trim((string) $rawSymbol));
        if ($symbol === '') {
            return '';
        }

        $symbol = preg_replace('/[^A-Z0-9._-]/', '', $symbol) ?? '';
        foreach (['.', '_', '-'] as $separator) {
            if (str_contains($symbol, $separator)) {
                $symbol = explode($separator, $symbol)[0] ?? $symbol;
                break;
            }
        }

        if (preg_match('/^[A-Z]{6}/', $symbol, $matches) === 1) {
            return $matches[0];
        }

        return $symbol;
    };

    $from = now()->subDays($lookbackDays);
    $signals = BotTradeLog::query()
        ->where('bot_key', $botKey)
        ->where('event_type', 'signal')
        ->where('status', 'confirmed')
        ->where('created_at', '>=', $from)
        ->orderBy('created_at')
        ->get();

    if ($signals->isEmpty()) {
        $this->warn('No confirmed signals found in lookback window.');
        return 0;
    }

    $symbols = $signals
        ->pluck('symbol')
        ->filter()
        ->map(static fn ($symbol) => strtoupper((string) $symbol))
        ->unique()
        ->values();

    $tradeCandidates = BotTradeLog::query()
        ->where('bot_key', $botKey)
        ->where('event_type', 'trade_open')
        ->where('created_at', '>=', $from)
        ->when($symbols->isNotEmpty(), static fn ($query) => $query->whereIn('symbol', $symbols->all()))
        ->orderBy('created_at')
        ->get();

    $tradeBuckets = [];
    foreach ($tradeCandidates as $tradeLog) {
        $bucketKey = $normalizeSymbolForMatch((string) ($tradeLog->symbol ?? ''))
            .'|'.strtolower((string) ($tradeLog->side ?? ''))
            .'|'.strtolower((string) ($tradeLog->bot_key ?? $tradeLog->bot_name ?? 'default'));
        if (!isset($tradeBuckets[$bucketKey])) {
            $tradeBuckets[$bucketKey] = [];
        }
        $tradeBuckets[$bucketKey][] = $tradeLog;
    }

    $deals = [];
    try {
        $deals = $mt5Service->getHistoryDeals($from->copy()->subDays(2), now());
    } catch (\Throwable $e) {
        $this->warn('History deals unavailable: '.$e->getMessage());
    }

    $closingDealsBySymbol = [];
    $closingDealsByPosition = [];
    foreach ($deals as $deal) {
        if (!is_array($deal)) {
            continue;
        }

        $entry = strtoupper((string) ($deal['entryType'] ?? $deal['entry'] ?? ''));
        if (!str_contains($entry, 'OUT')) {
            continue;
        }

        $timeRaw = $deal['brokerTime'] ?? $deal['time'] ?? null;
        if (!$timeRaw) {
            continue;
        }

        try {
            $dealTime = \Carbon\Carbon::parse((string) $timeRaw);
        } catch (\Throwable) {
            continue;
        }

        $record = [
            'time' => $dealTime,
            'profit' => (float) ($deal['profit'] ?? 0),
            'used' => false,
        ];

        $symbolKey = $normalizeSymbolForMatch((string) ($deal['symbol'] ?? ''));
        if ($symbolKey !== '') {
            $closingDealsBySymbol[$symbolKey][] = $record;
        }

        $positionId = trim((string) ($deal['positionId'] ?? $deal['position_id'] ?? ''));
        if ($positionId !== '') {
            $closingDealsByPosition[$positionId][] = $record;
        }
    }

    foreach ($closingDealsBySymbol as $symbolKey => $symbolDeals) {
        usort($symbolDeals, static fn ($a, $b) => $a['time']->lessThan($b['time']) ? -1 : 1);
        $closingDealsBySymbol[$symbolKey] = $symbolDeals;
    }
    foreach ($closingDealsByPosition as $positionId => $positionDeals) {
        usort($positionDeals, static fn ($a, $b) => $a['time']->lessThan($b['time']) ? -1 : 1);
        $closingDealsByPosition[$positionId] = $positionDeals;
    }

    $resolved = [];
    foreach ($signals as $signal) {
        $bucketKey = $normalizeSymbolForMatch((string) ($signal->symbol ?? ''))
            .'|'.strtolower((string) ($signal->side ?? ''))
            .'|'.strtolower((string) ($signal->bot_key ?? $signal->bot_name ?? 'default'));

        $candidateTrades = $tradeBuckets[$bucketKey] ?? [];
        $matchedTrade = null;
        foreach ($candidateTrades as $idx => $trade) {
            if ($trade->created_at?->lt($signal->created_at)) {
                continue;
            }
            if ($trade->created_at?->gt($signal->created_at?->copy()->addMinutes(30))) {
                break;
            }

            $matchedTrade = $trade;
            unset($tradeBuckets[$bucketKey][$idx]);
            break;
        }

        if (!$matchedTrade || $matchedTrade->status !== 'success') {
            continue;
        }

        $tradeResponse = is_array($matchedTrade->meta_response) ? $matchedTrade->meta_response : [];
        $firstOrder = is_array($tradeResponse['orders'][0] ?? null) ? $tradeResponse['orders'][0] : [];
        $response = is_array($firstOrder['response'] ?? null) ? $firstOrder['response'] : [];
        $positionId = trim((string) ($response['positionId'] ?? ''));

        $profit = null;
        if ($positionId !== '' && isset($closingDealsByPosition[$positionId])) {
            foreach ($closingDealsByPosition[$positionId] as $dealIdx => $deal) {
                if (($deal['used'] ?? false) === true) {
                    continue;
                }
                if ($deal['time']->lt($matchedTrade->created_at)) {
                    continue;
                }
                if ($deal['time']->gt($matchedTrade->created_at->copy()->addDays(7))) {
                    break;
                }

                $closingDealsByPosition[$positionId][$dealIdx]['used'] = true;
                $profit = (float) ($deal['profit'] ?? 0);
                break;
            }
        }

        if ($profit === null) {
            $symbolKey = $normalizeSymbolForMatch((string) ($signal->symbol ?? ''));
            if ($symbolKey !== '' && isset($closingDealsBySymbol[$symbolKey])) {
                foreach ($closingDealsBySymbol[$symbolKey] as $dealIdx => $deal) {
                    if (($deal['used'] ?? false) === true) {
                        continue;
                    }
                    if ($deal['time']->lt($matchedTrade->created_at)) {
                        continue;
                    }
                    if ($deal['time']->gt($matchedTrade->created_at->copy()->addDays(7))) {
                        break;
                    }

                    $closingDealsBySymbol[$symbolKey][$dealIdx]['used'] = true;
                    $profit = (float) ($deal['profit'] ?? 0);
                    break;
                }
            }
        }

        if ($profit === null) {
            continue;
        }

        $metaPayload = is_array($signal->meta_payload) ? $signal->meta_payload : [];
        $botScore = is_numeric($metaPayload['bot_score'] ?? null) ? (int) $metaPayload['bot_score'] : null;

        $resolved[] = [
            'time' => $signal->created_at,
            'hour' => (int) $signal->created_at?->copy()->utc()->format('G'),
            'symbol' => strtoupper((string) ($signal->symbol ?? '')),
            'profit' => $profit,
            'bot_score' => $botScore,
        ];
    }

    $resolvedCount = count($resolved);
    if ($resolvedCount < $minResolved) {
        $this->warn("Resolved trades ({$resolvedCount}) below minimum {$minResolved}; recommendation will be informational only.");
    }

    $currentMinScore = isset($profile['min_bot_score']) ? (int) $profile['min_bot_score'] : 70;
    $scoreCandidates = [80, 85, 90, 92, 95];
    $scoreStats = [];
    $bestScore = $currentMinScore;
    $bestScoreNet = PHP_FLOAT_MIN;

    foreach ($scoreCandidates as $candidate) {
        $rows = array_values(array_filter($resolved, static fn (array $row): bool => is_numeric($row['bot_score']) && (int) $row['bot_score'] >= $candidate));
        if (count($rows) < max(10, (int) floor($minResolved / 2))) {
            continue;
        }

        $net = array_sum(array_column($rows, 'profit'));
        $wins = count(array_filter($rows, static fn (array $row): bool => (float) $row['profit'] > 0));
        $count = count($rows);
        $scoreStats[] = [
            'candidate' => $candidate,
            'count' => $count,
            'net_pnl' => round($net, 2),
            'win_rate' => round(($wins * 100) / max(1, $count), 1),
        ];

        if ($net > $bestScoreNet) {
            $bestScoreNet = $net;
            $bestScore = $candidate;
        }
    }

    $hourBuckets = [];
    foreach ($resolved as $row) {
        $hour = (int) ($row['hour'] ?? -1);
        if ($hour < 0 || $hour > 23) {
            continue;
        }

        if (!isset($hourBuckets[$hour])) {
            $hourBuckets[$hour] = [];
        }
        $hourBuckets[$hour][] = $row;
    }

    $preferredHours = [];
    $blockedHours = [];
    foreach ($hourBuckets as $hour => $rows) {
        $count = count($rows);
        if ($count < 5) {
            continue;
        }

        $net = array_sum(array_column($rows, 'profit'));
        $wins = count(array_filter($rows, static fn (array $row): bool => (float) $row['profit'] > 0));
        $winRate = ($wins * 100.0) / max(1, $count);

        if ($net > 0 && $winRate >= 45.0) {
            $preferredHours[] = ['hour' => $hour, 'net' => $net, 'count' => $count];
        }
        if ($net < -20 && $winRate < 40.0) {
            $blockedHours[] = $hour;
        }
    }

    usort($preferredHours, static fn (array $a, array $b): int => $b['net'] <=> $a['net']);
    $preferredHours = array_values(array_map(static fn (array $row): int => (int) $row['hour'], array_slice($preferredHours, 0, 4)));
    sort($blockedHours);
    $blockedHours = array_values(array_unique($blockedHours));

    $symbolBuckets = [];
    foreach ($resolved as $row) {
        $symbol = strtoupper((string) ($row['symbol'] ?? ''));
        if ($symbol === '') {
            continue;
        }
        if (!isset($symbolBuckets[$symbol])) {
            $symbolBuckets[$symbol] = [];
        }
        $symbolBuckets[$symbol][] = $row;
    }

    $preferredSymbols = [];
    foreach ($symbolBuckets as $symbol => $rows) {
        $count = count($rows);
        if ($count < 5) {
            continue;
        }
        $net = array_sum(array_column($rows, 'profit'));
        if ($net <= 0) {
            continue;
        }

        $preferredSymbols[] = ['symbol' => $symbol, 'net' => $net];
    }
    usort($preferredSymbols, static fn (array $a, array $b): int => $b['net'] <=> $a['net']);
    $preferredSymbols = array_values(array_map(static fn (array $row): string => $row['symbol'], array_slice($preferredSymbols, 0, 4)));

    $recommendation = [
        'min_bot_score' => $bestScore,
        'preferred_hours_utc' => $preferredHours,
        'blocked_hours_utc' => $blockedHours,
        'preferred_symbols' => $preferredSymbols,
    ];

    $this->info("Auto-learning summary for {$botName} ({$botKey})");
    $this->line("Lookback days: {$lookbackDays}");
    $this->line("Resolved trades: {$resolvedCount}");
    $this->line('Recommended min_bot_score: '.$recommendation['min_bot_score']);
    $this->line('Recommended preferred_hours_utc: '.(!empty($recommendation['preferred_hours_utc']) ? implode(',', $recommendation['preferred_hours_utc']) : 'none'));
    $this->line('Recommended blocked_hours_utc: '.(!empty($recommendation['blocked_hours_utc']) ? implode(',', $recommendation['blocked_hours_utc']) : 'none'));
    $this->line('Recommended preferred_symbols: '.(!empty($recommendation['preferred_symbols']) ? implode(',', $recommendation['preferred_symbols']) : 'none'));

    $applied = false;
    if ($apply && $resolvedCount >= $minResolved) {
        $profiles[$profileIndex] = array_merge($profiles[$profileIndex], $recommendation);
        $settings->bot_profiles = $profiles;
        $settings->save();
        $applied = true;
        $this->info($hasStoredProfiles
            ? 'Recommendation applied to bot profile.'
            : 'Recommendation applied and default bot profile created.');
    } elseif ($apply) {
        $this->warn('Apply flag ignored because resolved trades are below minimum threshold.');
    }

    BotTradeLog::query()->create(array_merge($botLogDefaults, [
        'event_type' => 'guardrail',
        'status' => 'policy_recommendation',
        'message' => $applied
            ? 'Auto-learning policy recommendation generated and applied.'
            : 'Auto-learning policy recommendation generated (shadow mode).',
        'meta_payload' => [
            'lookback_days' => $lookbackDays,
            'min_resolved' => $minResolved,
            'resolved_trades' => $resolvedCount,
            'score_stats' => $scoreStats,
            'recommendation' => $recommendation,
            'applied' => $applied,
        ],
    ]));

    return 0;
})->purpose('Learn bot policy recommendations from recent resolved trades.');

Artisan::command('bot:prune-logs
    {--signal-days=1 : Delete scan/signal and guardrail logs older than N days}
    {--trade-days=90 : Delete resolved trade_open logs older than N days}
    {--no-cache : Skip pruning database cache temp entries}
    {--no-files : Skip pruning old storage/log files}
    {--dry-run : Show what would be deleted without deleting}
', function () {
    $dryRun = (bool) $this->option('dry-run');
    $stats = app(\App\Services\BotLogPruner::class)->prune(
        signalDays: max(1, (int) $this->option('signal-days')),
        tradeDays: max(30, (int) $this->option('trade-days')),
        pruneCache: ! $this->option('no-cache'),
        pruneFiles: ! $this->option('no-files'),
        dryRun: $dryRun,
    );

    $prefix = $dryRun ? 'Would prune' : 'Pruned';
    $this->info($prefix.' bot temp logs:');
    $this->line('  signals/guardrails: '.(int) $stats['bot_signals_deleted']);
    $this->line('  resolved trades: '.(int) $stats['bot_trades_deleted']);
    $this->line('  cache expired: '.(int) $stats['cache_expired_deleted']);
    $this->line('  cache temp keys: '.(int) $stats['cache_temp_deleted']);
    $this->line('  cache locks: '.(int) $stats['cache_locks_deleted']);
    $this->line('  log files removed: '.(int) $stats['log_files_deleted']);
    $this->line('  log files truncated: '.(int) $stats['log_files_truncated']);

    return 0;
})->purpose('Delete auto-forex temp logs, cache entries, and old log files.');

// ── §27 SCHEDULER — mt5:auto-forex --once every minute, lock 120 min ─────────────
Schedule::command('mt5:auto-forex --once')
    ->name('mt5-auto-forex-once')
    ->everyMinute()
    ->withoutOverlapping(120);

Schedule::command('bot:prune-logs')
    ->name('bot-prune-logs-daily')
    ->dailyAt('03:00')
    ->withoutOverlapping(30);

Artisan::command('ea:token {instance? : Instance key} {--regenerate : Force a new token}', function () {
    $service = app(\App\Services\EaBridgeService::class);
    $key = trim((string) $this->argument('instance'));

    if ($key === '') {
        $instances = \App\Models\Mt5EaTerminal::query()->orderBy('display_name')->get();
        if ($instances->isEmpty()) {
            $this->error('No MT5 instances. Create one at /ea-bridge first.');

            return 1;
        }

        $this->line('MT5 instance tokens (use instance key with --regenerate):');
        foreach ($instances as $instance) {
            $this->line('- '.$instance->instance_key.' · '.$instance->label().' · '.($instance->isOnline() ? 'online' : 'offline'));
        }

        return 0;
    }

    $terminal = \App\Models\Mt5EaTerminal::query()->where('instance_key', $key)->first();
    if ($terminal === null) {
        $this->error('Unknown instance key: '.$key);

        return 1;
    }

    $token = $this->option('regenerate')
        ? $service->regenerateTerminalToken($terminal)
        : (string) $terminal->api_token;

    $this->line('Instance: '.$terminal->label().' ('.$terminal->instance_key.')');
    $this->line('Token:');
    $this->line($token);
    $this->line('Poll URL: '.url('/api/ea/poll'));

    return 0;
})->purpose('Show or regenerate per-instance MT5 EA API tokens.');
