<?php

use App\Models\AppSetting;
use App\Models\BotTradeLog;
use App\Models\Ticker;
use App\Services\AiService;
use App\Services\Mt5Service;
use App\Services\TradingStrategies\StrategyFactory;
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
    {--max-daily-loss-percent=2 : Stop opening new trades when daily drawdown exceeds this percent}
    {--ai-confirm=1 : 1 requires AI approval before entry, 0 bypasses AI confirmation}
    {--ai-min-confidence=70 : Minimum AI confidence percentage (0-100) required to approve a trade}
    {--min-bot-score=70 : Minimum bot score (0-100) required to log/execute a signal}
    {--min-effective-volume= : Minimum effective volume required to place a trade (default: 0.01 x volume multiplier)}
    {--max-symbols=200 : Max symbols to scan per cycle (round-robin when total symbols exceed this value)}
    {--scan-delay-ms=0 : Milliseconds to wait between symbol scans to reduce API burst rate}
    {--max-cycle-credits=900 : Estimated MetaApi CPU credits budget per cycle (0 disables budget guard)}
    {--max-open-positions=10 : Maximum concurrent open positions (demo-safe ceiling)}
    {--max-per-cycle=5 : Maximum new trades to open in a single cycle}
    {--strategy=momentum : Legacy single strategy option (prefer --strategies)}
    {--strategies= : Comma-separated strategies (momentum,sma_cross,ema_cross,bollinger_reversion,vwap_reversion)}
    {--scalper=1 : 1 enables scalper mode (quick in/out), 0 keeps normal mode}
    {--reverse-strategy=0 : 1 flips strategy direction (buy<->sell), 0 keeps normal direction}
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
', function (Mt5Service $mt5Service, AiService $aiService, StrategyFactory $strategyFactory) {
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

    $runCycle = function (array $botProfile) use ($mt5Service, $aiService, $strategyFactory) {
        $db = AppSetting::singleton();
        $botKey = (string) ($botProfile['key'] ?? 'default');
        $botName = (string) ($botProfile['name'] ?? $botKey);
        $botLogDefaults = [
            'bot_key' => $botKey,
            'bot_name' => $botName,
        ];

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

        $normalizeSymbolList = static function (mixed $raw): array {
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
                array_map(static fn ($symbol) => strtoupper(trim((string) $symbol)), $source),
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
                    str_contains($rawCategory, 'indice') ||
                    str_contains($rawCategory, 'crypto')
                ) {
                    return 'other';
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
        $maxDailyLossPercent = (float) $optionOrProfileOrSetting('max-daily-loss-percent', $botProfile['max_daily_loss_percent'] ?? null, $db->bot_max_daily_loss_percent ?? null, 2);
        $aiConfirmSetting = $optionOrProfileOrSetting('ai-confirm', $botProfile['ai_confirm'] ?? null, $db->bot_ai_confirm ?? true, true);
        $useAiConfirm      = (string) $aiConfirmSetting !== '0' && (bool) $aiConfirmSetting;
        $aiMinConfidence   = (int) $optionOrProfileOrSetting('ai-min-confidence', $botProfile['ai_min_confidence'] ?? null, $db->bot_ai_min_confidence ?? null, 70);
        $minBotScore       = max(0, min(100, (int) $optionOrProfileOrSetting('min-bot-score', $botProfile['min_bot_score'] ?? null, null, 70)));
        $volumeMultiplier  = max(1, (int) ($db->mt5_volume_multiplier ?? 1));
        $defaultMinEffectiveVolume = 0.01 * $volumeMultiplier;
        $minEffectiveVolume = (float) $optionOrProfileOrSetting('min-effective-volume', $botProfile['min_effective_volume'] ?? null, null, $defaultMinEffectiveVolume);
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
        $trendTimeframes = $normalizeTimeframeList($optionOrProfileOrSetting(
            'signal-timeframes',
            $botProfile['signal_timeframes'] ?? (isset($botProfile['signal_timeframe']) ? [(string) $botProfile['signal_timeframe']] : null),
            $db->bot_signal_timeframes ?? null,
            ['15m']
        ));
        if (empty($trendTimeframes)) {
            $trendTimeframes = ['15m'];
        }
        $entryTimeframeRaw = strtolower(trim((string) $optionOrProfileOrSetting(
            'entry-timeframe',
            $botProfile['entry_timeframe'] ?? null,
            $db->bot_entry_timeframe ?? null,
            $trendTimeframes[0]
        )));
        $entryTimeframe = in_array($entryTimeframeRaw, $trendTimeframes, true)
            ? $entryTimeframeRaw
            : $trendTimeframes[0];
        $trendContextTimeframes = $trendTimeframes;
        if (count($trendContextTimeframes) > 1) {
            $trendContextTimeframes = array_values(array_filter(
                $trendContextTimeframes,
                static fn (string $timeframe): bool => $timeframe !== $entryTimeframe
            ));
        }
        if (empty($trendContextTimeframes)) {
            $trendContextTimeframes = [$entryTimeframe];
        }
        $cooldownOverrideRatio = (float) $optionOrProfileOrSetting('cooldown-override-ratio', $botProfile['cooldown_override_ratio'] ?? null, null, 0);
        $preferredHoursUtc = $normalizeHourList($optionOrProfileOrSetting('preferred-hours-utc', $botProfile['preferred_hours_utc'] ?? null, null, null));
        $blockedHoursUtc = $normalizeHourList($optionOrProfileOrSetting('blocked-hours-utc', $botProfile['blocked_hours_utc'] ?? null, null, 15));
        $preferredSymbols = $normalizeSymbolList($optionOrProfileOrSetting('preferred-symbols', $botProfile['preferred_symbols'] ?? null, null, null));
        $maxOpenPositions     = max(1, (int) $optionOrProfileOrSetting('max-open-positions', $botProfile['max_open_positions'] ?? null, $db->bot_max_open_positions ?? null, 10));
        $maxPerCycle          = max(1, (int) $optionOrProfileOrSetting('max-per-cycle', $botProfile['max_per_cycle'] ?? null, $db->bot_max_per_cycle ?? null, 5));
        $testMode             = (bool) $this->option('test-mode');

        $this->info('Running bot: '.$botName.' ('.$botKey.')');

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
            $accountInfo = $mt5Service->getAccountInformation();
            $equity = (float) ($accountInfo['equity'] ?? $accountInfo['balance'] ?? 0);
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
        }

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

        $openSnapshot = $mt5Service->getOpenTradeSnapshot();
        $positionsPayload = $openSnapshot['positions'] ?? null;
        $positions = (is_array($positionsPayload) && array_is_list($positionsPayload)) ? $positionsPayload : [];
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
        $profileSymbols = isset($botProfile['symbols']) && is_array($botProfile['symbols'])
            ? array_values(array_filter(array_map(static fn ($s) => strtoupper(trim((string) $s)), $botProfile['symbols']), static fn ($s) => $s !== ''))
            : [];
        $profileTickerCategories = isset($botProfile['ticker_categories']) && is_array($botProfile['ticker_categories'])
            ? array_values(array_unique(array_filter(array_map(static fn ($value) => strtolower(trim((string) $value)), $botProfile['ticker_categories']), static fn ($value) => $value !== '')))
            : [];

        if (!empty($profileSymbols)) {
            $symbols = $profileSymbols;
            $this->line('Using '.count($symbols).' symbol(s) from bot profile list.');
        } elseif ($dbTickers->isNotEmpty()) {
            $symbols = $dbTickers->keys()->all();
            $this->line('Using '.count($symbols).' symbol(s) from tickers table.');
        } else {
            $symbols = $mt5Service->getForexSymbols();
            $dbTickers = collect();
            $this->line('No tickers in DB — discovered '.count($symbols).' symbol(s) from MetaAPI.');
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
        $opened = 0;
        $scanned = 0;
        $skippedNoMove = 0;
        $skippedSpread = 0;
        $skippedCooldown = 0;
        $skippedOpen = 0;
        $skippedLowScore = 0;
        $skippedLowVolume = 0;
        $stoppedByRateLimit = false;
        $stoppedByCreditBudget = false;
        $cycleCreditsUsed = 0;
        $creditCostQuote = 50;
        $creditCostCandle = 50;

        $calculateBotScore = static function (float $signalDeltaPips, float $spreadPips, float $effectiveVolume, float $minEffectiveVolume): int {
            $signalStrengthScore = min(100.0, (abs($signalDeltaPips) / 10.0) * 100.0);
            $spreadScore = max(0.0, min(100.0, (1 - ($spreadPips / 3.0)) * 100.0));
            $volumeScore = max(0.0, min(100.0, ($effectiveVolume / $minEffectiveVolume) * 100.0));

            return (int) round(($signalStrengthScore * 0.6) + ($spreadScore * 0.25) + ($volumeScore * 0.15));
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
                $baseLotForScore = is_numeric($data['lot_size'] ?? null) ? (float) $data['lot_size'] : $lotSize;
                $effectiveVolumeForScore = $baseLotForScore * $volumeMultiplier;
                $resolvedBotScore = $calculateBotScore((float) $data['signal_delta_pips'], (float) $data['spread_pips'], $effectiveVolumeForScore, $minEffectiveVolume);
            } else {
                $resolvedBotScore = 0;
            }

            $alertLogMinScore = max(70, (int) $minBotScore);
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

        $reserveCycleCredits = function (int $cost, string $phase, string $symbol) use (&$cycleCreditsUsed, $maxCycleCredits, &$stoppedByCreditBudget, $botLogDefaults): bool {
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

        foreach ($symbols as $symbol) {
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
                    // Ignore malformed pause values and continue.
                }
            }

            if ($scanDelayMs > 0 && $scanned > 0) {
                usleep($scanDelayMs * 1000);
            }

            $symbol = strtoupper((string) $symbol);
            $quoteSymbol = preg_match('/^[A-Z]{6}$/', $symbol) === 1
                ? $symbol.'_SB'
                : $symbol;
            $scanned++;
            $this->line("  SCANNED: {$quoteSymbol}");

            if (!$reserveCycleCredits($creditCostQuote, 'quote', $quoteSymbol)) {
                break;
            }

            try {
                $quote = $mt5Service->getTickerPrice($quoteSymbol);
            } catch (\Throwable $e) {
                $errorMessage = $e->getMessage();
                Log::warning('Auto bot quote failed', ['symbol' => $symbol, 'error' => $errorMessage]);
                $this->line("  {$quoteSymbol}: quote error — {$errorMessage}");
                $logSignal([
                    'status' => 'quote_error',
                    'symbol' => $symbol,
                    'message' => 'Signal skipped due to quote retrieval failure.',
                    'error_message' => $errorMessage,
                ]);

                $upperError = strtoupper($errorMessage);
                if (str_contains($upperError, 'TOOMANYREQUESTS') || str_contains($upperError, '429')) {
                    $stoppedByRateLimit = true;
                    $this->warn('  Rate limit detected; stopping remaining symbol scans for this cycle.');
                    break;
                }

                if ($isMetaApiOutageError($errorMessage)) {
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
                    $candlesForStrategy = $mt5Service->getCandles($symbol, $primaryTimeframe, $requiredCandleCount);
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
                $signalDeltaPips = $pipSize > 0 ? abs($delta / $pipSize) : 0.0;
            } else {
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

            if ($reverseStrategy) {
                $side = $side === 'buy' ? 'sell' : 'buy';
            }
            $effectiveVolume = $lotSize * $volumeMultiplier;
            $botScore = $calculateBotScore($signalDeltaPips, $spreadPips, $effectiveVolume, $minEffectiveVolume);

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
                    'meta_payload' => [
                        'bot_score' => $botScore,
                        'min_bot_score' => $minBotScore,
                        'volume_multiplier' => $volumeMultiplier,
                        'effective_volume' => $effectiveVolume,
                        'min_effective_volume' => $minEffectiveVolume,
                    ],
                    'message' => 'Signal rejected because effective volume is below minimum threshold.',
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
                        'meta_payload' => [
                            'bot_score' => $botScore,
                            'min_bot_score' => $minBotScore,
                            'volume_multiplier' => $volumeMultiplier,
                            'effective_volume' => $effectiveVolume,
                            'min_effective_volume' => $minEffectiveVolume,
                        ],
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

            if (!$testMode && $spreadPips > $maxSpreadForSymbol) {
                $this->line("  {$symbol}: SPREAD {$spreadPips}pip > max {$maxSpreadForSymbol}pip ({$spreadCategory}) — skipped");
                $skippedSpread++;
                $logSignal([
                    'status' => 'spread_rejected',
                    'symbol' => $symbol,
                    'side' => $side,
                    'spread_pips' => $spreadPips,
                    'signal_delta_pips' => $signalDeltaPips,
                    'meta_payload' => [
                        'ticker_category' => $tickerCategory,
                        'ticker_spread_override' => is_numeric($tickerSpreadOverride) ? (float) $tickerSpreadOverride : null,
                        'ticker_tp_override' => is_numeric($tickerTpOverride) ? (float) $tickerTpOverride : null,
                        'ticker_sl_override' => is_numeric($tickerSlOverride) ? (float) $tickerSlOverride : null,
                        'spread_category' => $spreadCategory,
                        'max_spread_pips_for_symbol' => $maxSpreadForSymbol,
                        'max_spread_pips_by_category' => $maxSpreadByCategory,
                    ],
                    'message' => 'Signal rejected due to spread filter.',
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
                try {
                    $trendByTimeframe = [];
                    foreach ($trendContextTimeframes as $timeframe) {
                        if (!$reserveCycleCredits($creditCostCandle, 'trend_context_'.$timeframe, $symbol)) {
                            break 2;
                        }

                        $candles = $mt5Service->getCandles($symbol, $timeframe, 1);
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

                    $entryCandles = $mt5Service->getCandles($symbol, $entryTimeframe, 1);
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
            $executionSide = $recommendedSide === 'buy' ? 'sell' : 'buy';

            $this->line("  {$symbol}: signal {$recommendedSide} => executing {$executionSide} — move=".number_format($signalDeltaPips,2)."pip spread=".number_format($spreadPips,2)."pip bid={$bid} ask={$ask}");

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

                        foreach ($trendTimeframes as $index => $timeframe) {
                            $limit = $index === 0 ? 20 : 10;
                            if (!$reserveCycleCredits($creditCostCandle, 'ai_context_'.$timeframe, $symbol)) {
                                break 2;
                            }

                            $candles = $mt5Service->getCandles($symbol, $timeframe, $limit);
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
                    'meta_payload' => [
                        'bot_score' => $botScore,
                        'min_bot_score' => $minBotScore,
                        'volume_multiplier' => $volumeMultiplier,
                        'effective_volume' => $effectiveVolume,
                        'min_effective_volume' => $minEffectiveVolume,
                        'recommended_side' => $recommendedSide,
                        'execution_side' => $executionSide,
                        'ticker_category' => $tickerCategory,
                        'tp_pips_for_symbol' => $tpPipsForSymbol,
                        'sl_pips_for_symbol' => $slPipsForSymbol,
                    ],
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
                'meta_payload' => [
                    'bot_score' => $botScore,
                    'min_bot_score' => $minBotScore,
                    'volume_multiplier' => $volumeMultiplier,
                    'effective_volume' => $effectiveVolume,
                    'min_effective_volume' => $minEffectiveVolume,
                    'recommended_side' => $recommendedSide,
                    'execution_side' => $executionSide,
                    'ticker_category' => $tickerCategory,
                    'tp_pips_for_symbol' => $tpPipsForSymbol,
                    'sl_pips_for_symbol' => $slPipsForSymbol,
                ],
                'message' => 'Signal passed all filters and AI confirmation.',
            ]);

            try {
                $result = $mt5Service->placeOrder($symbol, $lotSize, $executionSide, [[
                    'close_percent' => 100,
                    'take_profit' => $takeProfit,
                    'stop_loss' => $stopLoss,
                ]]);
                $opened++;
                $openBySymbol[$symbol] = true;
                $firstOrder = is_array($result['orders'][0] ?? null) ? $result['orders'][0] : null;
                $firstResponse = is_array($firstOrder['response'] ?? null) ? $firstOrder['response'] : [];
                $orderId = trim((string) ($firstResponse['orderId'] ?? ''));
                $positionId = trim((string) ($firstResponse['positionId'] ?? ''));
                $tradeRef = $positionId !== '' ? $positionId : ($orderId !== '' ? $orderId : (string) $symbol);

                $this->info('Opened '.$executionSide.' '.$symbol.' (recommended '.$recommendedSide.') TP='.$takeProfit.' SL='.$stopLoss);
                Log::info('Auto bot opened trade', [
                    'symbol' => $symbol,
                    'recommended_side' => $recommendedSide,
                    'side' => $executionSide,
                    'result' => $result,
                ]);
                BotTradeLog::query()->create(array_merge($botLogDefaults, [
                    'event_type' => 'trade_open',
                    'status' => 'success',
                    'symbol' => $symbol,
                    'side' => $executionSide,
                    'order_id' => $orderId !== '' ? $orderId : null,
                    'position_id' => $positionId !== '' ? $positionId : null,
                    'linked_trade' => 'TRADE #'.$tradeRef,
                    'trade_outcome' => 'PENDING',
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
                    'meta_payload' => [
                        'bot_score' => $botScore,
                        'min_bot_score' => $minBotScore,
                        'volume_multiplier' => $volumeMultiplier,
                        'effective_volume' => $effectiveVolume,
                        'min_effective_volume' => $minEffectiveVolume,
                        'recommended_side' => $recommendedSide,
                        'execution_side' => $executionSide,
                    ],
                    'message' => 'Trade opened successfully.',
                    'meta_response' => $result,
                ]));

                // Run one immediate trailing pass so new trades do not wait for the next cycle.
                try {
                    $postOpenTrail = $mt5Service->applyTrailingStops($trailStartPips, $trailPips, $trailTpMultiplier, $resolveTrailingParamsForSymbol);
                    if (($postOpenTrail['updated'] ?? 0) > 0) {
                        $this->line('Post-open trailing updated: '.$postOpenTrail['updated']);
                    }
                } catch (\Throwable $trailError) {
                    $this->warn('Post-open trailing pass failed: '.$trailError->getMessage());
                }

                if ($opened >= $maxPerCycle) {
                    $this->line('Per-cycle trade limit reached ('.$maxPerCycle.'). Waiting for next cycle.');
                    break;
                }
            } catch (\Throwable $e) {
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
                    'meta_payload' => [
                        'bot_score' => $botScore,
                        'min_bot_score' => $minBotScore,
                        'volume_multiplier' => $volumeMultiplier,
                        'effective_volume' => $effectiveVolume,
                        'min_effective_volume' => $minEffectiveVolume,
                        'recommended_side' => $recommendedSide,
                        'execution_side' => $executionSide,
                    ],
                    'message' => 'Trade open failed.',
                    'error_message' => $e->getMessage(),
                ]));
            }
        }

        $this->info(
            'Cycle complete ['.$botName.']. Scanned='.$scanned
            .' opened='.$opened
            .' noMove='.$skippedNoMove
            .' spread='.$skippedSpread
            .' lowScore='.$skippedLowScore
            .' lowVolume='.$skippedLowVolume
            .' cooldown='.$skippedCooldown
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
                'skipped_low_volume' => $skippedLowVolume,
                'skipped_cooldown' => $skippedCooldown,
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

Schedule::command('mt5:auto-forex --once')
    ->name('mt5-auto-forex-once')
    ->everyMinute()
    ->withoutOverlapping(120);
