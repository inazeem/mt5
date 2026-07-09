<?php

namespace App\Http\Controllers;

use App\Models\BotTradeLog;
use App\Models\AppSetting;
use App\Models\Mt5EaTerminal;
use App\Services\Brokers\BrokerResolver;
use App\Services\Brokers\EaBridgeBroker;
use App\Services\EaBridgeService;
use App\Services\Mt5Service;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BotController extends Controller
{
    private const ALLOWED_SIGNAL_TIMEFRAMES = ['5m', '15m', '30m', '1h', '4h'];
    private const ALLOWED_STRATEGIES = ['momentum', 'sma_cross', 'ema_cross', 'bollinger_reversion', 'vwap_reversion'];
    private const ANALYTICS_SLOW_MS = 1000;
    private const MAX_HISTORY_SYNC_CALLS_PER_DAY = 2;
    private const ANALYTICS_LIVE_POSITIONS_CACHE_KEY = 'bot_analytics_live_positions_v5';

    public function index(Mt5Service $mt5Service)
    {
        $settings = AppSetting::singleton();
        $botProfile = $this->resolveHealthBotProfile($settings);
        $openSnapshot = null;
        $tickerPrice = null;
        $topForexSymbols = ['EURUSD', 'GBPUSD', 'USDJPY', 'USDCHF', 'USDCAD', 'AUDUSD', 'NZDUSD', 'EURJPY'];
        $defaultSymbol = strtoupper((string) old('symbol', 'GBPUSD'));

        try {
            $openSnapshot = $this->openSnapshotCached($mt5Service, true, $botProfile);
        } catch (Throwable $e) {
            $openSnapshot = [
                'error' => $e->getMessage(),
            ];
        }

        try {
            $tickerPrice = $mt5Service->getTickerPrice($defaultSymbol);
        } catch (Throwable $e) {
            $tickerPrice = [
                'symbol' => $defaultSymbol,
                'error' => $e->getMessage(),
            ];
        }

        try {
            $topForexSymbols = $mt5Service->getTopForexSymbols();
        } catch (Throwable) {
            // Keep defaults if symbols list is unavailable.
        }

        return view('bot.index', compact('settings', 'openSnapshot', 'tickerPrice', 'topForexSymbols'));
    }

    public function price(Request $request, Mt5Service $mt5Service)
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        try {
            $price = $mt5Service->getTickerPrice(strtoupper($validated['symbol']));

            return response()->json([
                'ok' => true,
                'data' => $price,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function updateAutoSettings(Request $request)
    {
        $validated = $request->validate([
            'mt5_volume_multiplier' => ['nullable', 'integer', 'min:1'],
            'bot_lot' => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'bot_tp_pips' => ['nullable', 'numeric', 'min:0.1'],
            'bot_sl_pips' => ['nullable', 'numeric', 'min:0.1'],
            'bot_trail_start_pips' => ['nullable', 'numeric', 'min:0.1'],
            'bot_trail_pips' => ['nullable', 'numeric', 'min:0.1'],
            'bot_trail_tp_multiplier' => ['nullable', 'numeric', 'min:1', 'max:10'],
            'bot_min_move_pips' => ['nullable', 'numeric', 'min:0.1'],
            'bot_max_spread_pips' => ['nullable', 'numeric', 'min:0.1'],
            'bot_cooldown_minutes' => ['nullable', 'integer', 'min:0'],
            'bot_session_start_utc' => ['nullable', 'integer', 'min:0', 'max:23'],
            'bot_session_end_utc' => ['nullable', 'integer', 'min:0', 'max:23'],
            'bot_max_trades_per_day' => ['nullable', 'integer', 'min:1'],
            'bot_max_daily_loss_percent' => ['nullable', 'numeric', 'min:0.1'],
            'bot_ai_confirm' => ['nullable', 'boolean'],
            'bot_max_symbols' => ['nullable', 'integer', 'min:1'],
            'bot_ai_min_confidence' => ['nullable', 'integer', 'min:0', 'max:100'],
            'bot_strategies' => ['nullable', 'array'],
            'bot_strategies.*' => ['required', 'in:momentum,sma_cross,ema_cross,bollinger_reversion,vwap_reversion'],
            'bot_signal_timeframes' => ['nullable', 'array'],
            'bot_signal_timeframes.*' => ['required', 'in:5m,15m,30m,1h,4h'],
            'bot_entry_timeframe' => ['nullable', 'in:5m,15m,30m,1h,4h'],
        ]);

        $validated['bot_ai_confirm'] = $request->boolean('bot_ai_confirm');
        $validated['bot_strategies'] = $this->normalizeStrategies($validated['bot_strategies'] ?? null) ?? ['momentum'];
        $validated['bot_signal_timeframes'] = $this->normalizeSignalTimeframes($validated['bot_signal_timeframes'] ?? null) ?? ['1h', '4h'];
        $entryTimeframe = strtolower(trim((string) ($validated['bot_entry_timeframe'] ?? '')));
        $allowedEntryTimeframes = ['5m', '15m', '30m', '1h', '4h'];
        $validated['bot_entry_timeframe'] = in_array($entryTimeframe, $allowedEntryTimeframes, true)
            ? $entryTimeframe
            : '15m';
        $validated['bot_strategy'] = $validated['bot_strategies'][0] ?? 'momentum';

        $settings = AppSetting::singleton();
        $settings->fill($validated);
        $settings->save();

        return redirect()->route('bot.index')->with('status', 'Auto-bot settings saved.');
    }

    public function store(Request $request, Mt5Service $mt5Service)
    {
        $validated = $request->validate([
            'symbol' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'lot_size' => ['required', 'numeric', 'min:0.01', 'max:100'],
            'side' => ['required', 'in:buy,sell'],
            'exit_legs' => ['nullable', 'array', 'max:20'],
            'exit_legs.*.close_percent' => ['nullable', 'numeric', 'gt:0', 'lte:100'],
            'exit_legs.*.take_profit' => ['nullable', 'numeric'],
            'exit_legs.*.stop_loss' => ['nullable', 'numeric'],
        ]);

        $exitLegs = collect($validated['exit_legs'] ?? [])
            ->filter(function ($leg) {
                if (!is_array($leg)) {
                    return false;
                }

                return !empty($leg['close_percent'])
                    || !empty($leg['take_profit'])
                    || !empty($leg['stop_loss']);
            })
            ->map(function ($leg) {
                return [
                    'close_percent' => isset($leg['close_percent']) ? (float) $leg['close_percent'] : null,
                    'take_profit' => isset($leg['take_profit']) && $leg['take_profit'] !== null ? (float) $leg['take_profit'] : null,
                    'stop_loss' => isset($leg['stop_loss']) && $leg['stop_loss'] !== null ? (float) $leg['stop_loss'] : null,
                ];
            })
            ->values()
            ->all();

        try {
            $result = $mt5Service->placeOrder(
                strtoupper($validated['symbol']),
                (float) $validated['lot_size'],
                $validated['side'],
                $exitLegs
            );

            $openSnapshot = $mt5Service->getOpenTradeSnapshot();

            return redirect()->route('bot.index')
                ->with('status', 'Trade request sent to MT5 demo server.')
                ->with('trade_result', $result)
                ->with('open_snapshot', $openSnapshot);
        } catch (Throwable $e) {
            return redirect()->route('bot.index')
                ->withInput()
                ->withErrors(['trade' => $e->getMessage()]);
        }
    }

    public function closePosition(Request $request, Mt5Service $mt5Service)
    {
        $validated = $request->validate([
            'position_id' => ['required', 'string', 'max:50'],
        ]);

        try {
            $result = $mt5Service->closePosition($validated['position_id']);
            $openSnapshot = $mt5Service->getOpenTradeSnapshot();

            return redirect()->route('bot.index')
                ->with('status', "Position {$validated['position_id']} close request sent.")
                ->with('trade_result', $result)
                ->with('open_snapshot', $openSnapshot);
        } catch (Throwable $e) {
            return redirect()->route('bot.index')
                ->withErrors(['trade' => $e->getMessage()]);
        }
    }

    public function analytics(Mt5Service $mt5Service)
    {
        $settings = AppSetting::singleton();

        try {
            $payload = $this->buildAnalyticsPayload($mt5Service, true, 'page');
        } catch (Throwable $e) {
            logger()->error('analytics page payload failed', [
                'error' => $e->getMessage(),
            ]);
            $payload = $this->analyticsFallbackPayload($e->getMessage());
        }

        $openSnapshot = $payload['openSnapshot'];
        $positions = $payload['positions'];
        $stats = $payload['stats'];

        return view('bot.analytics', compact('settings', 'openSnapshot', 'positions', 'stats'));
    }

    public function analyticsLive(Mt5Service $mt5Service)
    {
        try {
            $payload = $this->buildAnalyticsPayload($mt5Service, true, 'live');
        } catch (Throwable $e) {
            logger()->error('analytics live payload failed', [
                'error' => $e->getMessage(),
            ]);
            $payload = $this->analyticsFallbackPayload($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'updated_at' => now()->toIso8601String(),
            'open_error' => $payload['openSnapshot']['error'] ?? null,
            'stats' => $payload['stats'],
            'positions' => $payload['positions'],
        ]);
    }

    public function health(Request $request, Mt5Service $mt5Service)
    {
        $settings = AppSetting::singleton();
        $botProfile = $this->resolveHealthBotProfile($settings);

        $validated = $request->validate([
            'symbol' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        $profileSymbols = isset($botProfile['symbols']) && is_array($botProfile['symbols'])
            ? array_values(array_filter(array_map(static fn ($symbol) => $mt5Service->baseSymbol((string) $symbol), $botProfile['symbols'])))
            : [];

        $healthSymbol = strtoupper((string) ($validated['symbol'] ?? ($profileSymbols[0] ?? 'GBPUSD')));
        if ($healthSymbol === '') {
            $healthSymbol = 'GBPUSD';
        }

        $sessionStartUtc = (int) ($botProfile['session_start_utc'] ?? $settings->bot_session_start_utc ?? 6);
        $sessionEndUtc = (int) ($botProfile['session_end_utc'] ?? $settings->bot_session_end_utc ?? 20);
        $allowedSignalTimeframes = ['5m', '15m', '30m', '1h', '4h'];
        $timeframeSource = $botProfile['signal_timeframes']
            ?? (isset($botProfile['signal_timeframe']) ? [(string) $botProfile['signal_timeframe']] : null)
            ?? $settings->bot_signal_timeframes
            ?? ['1h', '4h'];
        $signalTimeframes = array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            is_array($timeframeSource) ? $timeframeSource : []
        ), static fn ($value) => in_array($value, $allowedSignalTimeframes, true))));
        if (empty($signalTimeframes)) {
            $signalTimeframes = ['1h', '4h'];
        }
        $entryTimeframe = strtolower(trim((string) ($botProfile['entry_timeframe'] ?? $settings->bot_entry_timeframe ?? '15m')));
        if (!in_array($entryTimeframe, $allowedSignalTimeframes, true)) {
            $entryTimeframe = '15m';
        }
        $currentHourUtc = (int) now('UTC')->format('G');
        $inSession = $sessionStartUtc <= $sessionEndUtc
            ? ($currentHourUtc >= $sessionStartUtc && $currentHourUtc <= $sessionEndUtc)
            : ($currentHourUtc >= $sessionStartUtc || $currentHourUtc <= $sessionEndUtc);

        $runtime = [
            'bot_key' => (string) ($botProfile['key'] ?? 'default'),
            'bot_name' => (string) ($botProfile['name'] ?? ($botProfile['key'] ?? 'Default')),
            'strategies' => isset($botProfile['strategies']) && is_array($botProfile['strategies'])
                ? $botProfile['strategies']
                : (isset($botProfile['strategy']) && (string) $botProfile['strategy'] !== ''
                    ? [(string) $botProfile['strategy']]
                    : (is_array($settings->bot_strategies ?? null) && !empty($settings->bot_strategies)
                        ? $settings->bot_strategies
                        : [(string) ($settings->bot_strategy ?? 'momentum')])),
            'strategy_params' => array_merge(
                is_array($settings->bot_strategy_params ?? null) ? $settings->bot_strategy_params : [],
                is_array($botProfile['strategy_params'] ?? null) ? $botProfile['strategy_params'] : []
            ),
            'session_start_utc' => $sessionStartUtc,
            'session_end_utc' => $sessionEndUtc,
            'current_hour_utc' => $currentHourUtc,
            'in_session' => $inSession,
            'trend_filter' => (bool) ($botProfile['trend_filter'] ?? false),
            'ai_confirm' => array_key_exists('ai_confirm', $botProfile) ? (bool) $botProfile['ai_confirm'] : (bool) ($settings->bot_ai_confirm ?? true),
            'max_symbols' => (int) ($botProfile['max_symbols'] ?? $settings->bot_max_symbols ?? 200),
            'max_per_cycle' => (int) ($botProfile['max_per_cycle'] ?? $settings->bot_max_per_cycle ?? 5),
            'max_open_positions' => (int) ($botProfile['max_open_positions'] ?? $settings->bot_max_open_positions ?? 10),
            'min_move_pips' => (float) ($botProfile['min_move_pips'] ?? $settings->bot_min_move_pips ?? 3),
            'max_spread_pips' => (float) ($botProfile['max_spread_pips'] ?? $settings->bot_max_spread_pips ?? 2.5),
            'signal_timeframes' => $signalTimeframes,
            'entry_timeframe' => $entryTimeframe,
            'health_symbol' => $healthSymbol,
        ];

        $checks = [];

        $healthSnapshot = $this->openSnapshotCached($mt5Service, true, $botProfile);
        $healthBroker = $this->resolveUiBrokerContext($botProfile);

        try {
            if (($healthBroker['type'] ?? '') === 'ea_bridge') {
                $terminals = is_array($healthSnapshot['terminals'] ?? null) ? $healthSnapshot['terminals'] : [];
                $online = collect($terminals)->where('ok', true)->count();
                $checks[] = [
                    'name' => 'Accounts',
                    'ok' => $online > 0,
                    'detail' => 'Online instances='.$online.'/'.count($terminals),
                ];
            } else {
                $account = $mt5Service->getAccountInformation();
                $checks[] = [
                    'name' => 'Account',
                    'ok' => true,
                    'detail' => 'Balance='.(float) ($account['balance'] ?? 0).' Equity='.(float) ($account['equity'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            $checks[] = [
                'name' => 'Accounts',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        $checks[] = [
            'name' => 'Open snapshot',
            'ok' => empty($healthSnapshot['error']),
            'detail' => empty($healthSnapshot['error'])
                ? 'Positions='.count($healthSnapshot['positions'] ?? []).' Orders='.count($healthSnapshot['orders'] ?? [])
                : (string) $healthSnapshot['error'],
        ];

        try {
            $quote = $mt5Service->getTickerPrice($healthSymbol);
            $checks[] = [
                'name' => 'Quote '.$healthSymbol,
                'ok' => true,
                'detail' => 'Bid='.(string) ($quote['bid'] ?? '?').' Ask='.(string) ($quote['ask'] ?? '?').' Symbol='.(string) ($quote['symbol'] ?? $healthSymbol),
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'name' => 'Quote '.$healthSymbol,
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        foreach ($signalTimeframes as $timeframe) {
            try {
                $candles = $mt5Service->getCandles($healthSymbol, $timeframe, 1);
                $lastCandle = is_array($candles[array_key_last($candles)] ?? null) ? $candles[array_key_last($candles)] : [];
                $checks[] = [
                    'name' => 'Candles '.$healthSymbol.' '.$timeframe,
                    'ok' => true,
                    'detail' => 'Fetched '.count($candles).' candle(s). Last time='.(string) ($lastCandle['time'] ?? $lastCandle['brokerTime'] ?? 'n/a'),
                ];
            } catch (Throwable $e) {
                $checks[] = [
                    'name' => 'Candles '.$healthSymbol.' '.$timeframe,
                    'ok' => false,
                    'detail' => $e->getMessage(),
                ];
            }
        }

        $recentIssues = BotTradeLog::query()
            ->where(function ($query) {
                $query->where('event_type', 'guardrail')
                    ->orWhereNotNull('error_message')
                    ->orWhere('status', 'like', '%_rejected')
                    ->orWhereIn('status', ['quote_error', 'invalid_quote']);
            })
            ->latest()
            ->limit(30)
            ->get();

        $latestCycleSummary = BotTradeLog::query()
            ->where('event_type', 'guardrail')
            ->where('status', 'cycle_complete')
            ->latest()
            ->first();

        return view('bot.health', compact('settings', 'runtime', 'checks', 'recentIssues', 'latestCycleSummary', 'healthSymbol'));
    }

    public function alerts(Request $request, Mt5Service $mt5Service)
    {
        $this->reconcilePendingTradeOutcomes($mt5Service);

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

        $validated = $request->validate([
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date'],
            'event_type' => ['nullable', 'string', 'in:signal,trade_open,trailing_update,guardrail'],
            'symbol'     => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]*$/'],
            'bot'        => ['nullable', 'string', 'max:120'],
            'per_page'   => ['nullable', 'integer', 'in:25,50,100,200'],
        ]);

        $dateFrom  = !empty($validated['date_from']) ? $validated['date_from'] : null;
        $dateTo    = !empty($validated['date_to'])   ? $validated['date_to']   : null;
        $eventType = $validated['event_type'] ?? 'signal';
        $symbol    = !empty($validated['symbol']) ? strtoupper($validated['symbol']) : null;
        $botFilter = !empty($validated['bot']) ? trim((string) $validated['bot']) : null;
        $perPage   = (int) ($validated['per_page'] ?? 50);

        $logsQuery = BotTradeLog::query()->latest();

        if ($dateFrom) {
            $logsQuery->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $logsQuery->whereDate('created_at', '<=', $dateTo);
        }
        if ($eventType) {
            $logsQuery->where('event_type', $eventType);
        }
        if ($symbol) {
            $logsQuery->where('symbol', 'like', $symbol.'%');
        }
        if ($botFilter) {
            $logsQuery->where(function ($query) use ($botFilter) {
                $query->where('bot_key', $botFilter)
                    ->orWhere('bot_name', $botFilter);
            });
        }

        // Keep the page focused, but never hide diagnostic failures.
        $logsQuery->where(function ($query) {
            $query->where('event_type', '!=', 'signal')
            ->orWhereNotNull('error_message')
            ->orWhere('status', 'like', '%_rejected')
            ->orWhereIn('status', ['quote_error', 'invalid_quote'])
                ->orWhere('meta_payload->bot_score', '>=', 70);
        });

        $recentLogs = $logsQuery->paginate($perPage)->withQueryString();

        $botOptions = BotTradeLog::query()
            ->whereNotNull('bot_key')
            ->select(['bot_key', 'bot_name'])
            ->distinct()
            ->orderBy('bot_name')
            ->get();

        $this->enrichAlertLogs($recentLogs->getCollection(), $mt5Service, $botFilter);

        $alertsSummary = [
            'won' => 0.0,
            'lost' => 0.0,
            'net' => 0.0,
            'resolved_count' => 0,
        ];

        foreach ($recentLogs->getCollection() as $log) {
            if (!is_numeric($log->trade_pnl ?? null)) {
                continue;
            }

            $pnl = (float) $log->trade_pnl;
            $alertsSummary['net'] += $pnl;
            $alertsSummary['resolved_count']++;

            if ($pnl >= 0) {
                $alertsSummary['won'] += $pnl;
            } else {
                $alertsSummary['lost'] += abs($pnl);
            }
        }

        return view('bot.alerts', compact('recentLogs', 'dateFrom', 'dateTo', 'eventType', 'symbol', 'botFilter', 'botOptions', 'perPage', 'alertsSummary'));
    }

    public function exportCsv(Request $request, Mt5Service $mt5Service)
    {
        $this->reconcilePendingTradeOutcomes($mt5Service);

        $validated = $request->validate([
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date'],
            'event_type' => ['nullable', 'string', 'in:signal,trade_open,trailing_update,guardrail'],
            'symbol'     => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]*$/'],
            'bot'        => ['nullable', 'string', 'max:120'],
        ]);

        $dateFrom = !empty($validated['date_from']) ? $validated['date_from'] : null;
        $dateTo = !empty($validated['date_to']) ? $validated['date_to'] : null;
        $eventType = $validated['event_type'] ?? 'signal';
        $symbol = !empty($validated['symbol']) ? strtoupper($validated['symbol']) : null;
        $botFilter = !empty($validated['bot']) ? trim((string) $validated['bot']) : null;

        $logsQuery = BotTradeLog::query()->latest();

        if ($dateFrom) {
            $logsQuery->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $logsQuery->whereDate('created_at', '<=', $dateTo);
        }
        if ($eventType) {
            $logsQuery->where('event_type', $eventType);
        }
        if ($symbol) {
            $logsQuery->where('symbol', 'like', $symbol.'%');
        }
        if ($botFilter) {
            $logsQuery->where(function ($query) use ($botFilter) {
                $query->where('bot_key', $botFilter)
                    ->orWhere('bot_name', $botFilter);
            });
        }

        $logsQuery->where(function ($query) {
            $query->where('event_type', '!=', 'signal')
                ->orWhereNotNull('error_message')
                ->orWhere('status', 'like', '%_rejected')
                ->orWhereIn('status', ['quote_error', 'invalid_quote'])
                ->orWhere('meta_payload->bot_score', '>=', 70);
        });

        $logs = $logsQuery->get();

        $this->enrichAlertLogs($logs, $mt5Service, $botFilter);

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="bot_trade_logs_'.now()->format('Ymd_His').'.csv"',
        ];

        $callback = function () use ($logs) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'id', 'created_at', 'bot_key', 'bot_name', 'event_type', 'status', 'symbol', 'side',
                'lot_size', 'entry_price', 'stop_loss', 'take_profit',
                'linked_trade', 'trade_outcome', 'trade_pnl', 'ai_confidence', 'bot_score', 'alert_reasoning',
                'spread_pips', 'signal_delta_pips', 'ai_provider', 'ai_decision',
                'ai_summary', 'message', 'error_message',
            ]);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->created_at?->toDateTimeString(),
                    $log->bot_key,
                    $log->bot_name,
                    $log->event_type,
                    $log->status,
                    $log->symbol,
                    $log->side,
                    $log->lot_size,
                    $log->entry_price,
                    $log->stop_loss,
                    $log->take_profit,
                    $log->linked_trade,
                    $log->trade_outcome,
                    $log->trade_pnl,
                    $log->ai_confidence,
                    $log->bot_score,
                    $log->alert_reasoning,
                    $log->spread_pips,
                    $log->signal_delta_pips,
                    $log->ai_provider,
                    $log->ai_decision,
                    $log->ai_summary,
                    $log->message,
                    $log->error_message,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }

    private function enrichAlertLogs(Collection $logs, Mt5Service $mt5Service, ?string $botFilter = null): Collection
    {
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

        $symbols = $logs
            ->pluck('symbol')
            ->filter()
            ->map(static fn ($symbol) => strtoupper((string) $symbol))
            ->unique()
            ->values();

        $timeStart = $logs->min('created_at');
        $timeEnd = $logs->max('created_at');

        $tradeCandidates = collect();
        if ($timeStart && $timeEnd && $symbols->isNotEmpty()) {
            $tradeCandidatesQuery = BotTradeLog::query()
                ->where('event_type', 'trade_open')
                ->whereIn('symbol', $symbols->all())
                ->whereBetween('created_at', [$timeStart, $timeEnd->copy()->addDay()]);

            if ($botFilter) {
                $tradeCandidatesQuery->where(function ($query) use ($botFilter) {
                    $query->where('bot_key', $botFilter)
                        ->orWhere('bot_name', $botFilter);
                });
            }

            $tradeCandidates = $tradeCandidatesQuery
                ->orderBy('created_at')
                ->get();
        }

        $tradeBuckets = [];
        foreach ($tradeCandidates as $tradeLog) {
            $key = $normalizeSymbolForMatch((string) ($tradeLog->symbol ?? ''))
                .'|'.strtolower((string) ($tradeLog->side ?? ''))
                .'|'.strtolower((string) ($tradeLog->bot_key ?? $tradeLog->bot_name ?? 'default'));
            if (!isset($tradeBuckets[$key])) {
                $tradeBuckets[$key] = [];
            }
            $tradeBuckets[$key][] = $tradeLog;
        }

        $buildReasoning = static function (?string $aiSummary, ?string $message, ?string $errorMessage, array $metaPayload): string {
            $parts = [];

            $strategies = [];
            if (isset($metaPayload['strategies']) && is_array($metaPayload['strategies'])) {
                $strategies = array_values(array_filter(array_map(
                    static fn ($value) => strtoupper(trim((string) $value)),
                    $metaPayload['strategies']
                ), static fn ($value) => $value !== ''));
            } elseif (!empty($metaPayload['strategy'])) {
                $strategies = [strtoupper(trim((string) $metaPayload['strategy']))];
            }

            if (!empty($strategies)) {
                $parts[] = 'Strategies: '.implode(', ', $strategies);
            }

            if (!empty($metaPayload['strategy_timeframe'])) {
                $parts[] = 'Strategy timeframe: '.strtoupper((string) $metaPayload['strategy_timeframe']);
            }

            if (isset($metaPayload['strategy_results']) && is_array($metaPayload['strategy_results'])) {
                foreach ($metaPayload['strategy_results'] as $strategyKey => $result) {
                    if (!is_array($result)) {
                        continue;
                    }

                    $status = (string) ($result['status'] ?? ($result['signal'] ?? false ? 'signal_ok' : 'strategy_rejected'));
                    $side = (string) ($result['side'] ?? '-');
                    $reason = trim((string) ($result['message'] ?? ''));
                    $label = strtoupper((string) $strategyKey);
                    $line = $label.': '.strtoupper($status).' side='.strtoupper($side);
                    if ($reason !== '') {
                        $line .= ' reason='.$reason;
                    }

                    $parts[] = $line;
                }
            }

            foreach ([$aiSummary, $message, $errorMessage] as $value) {
                $text = trim((string) $value);
                if ($text === '' || in_array($text, $parts, true)) {
                    continue;
                }

                $parts[] = $text;
            }

            return implode("\n", $parts);
        };

        return $logs->transform(static function (BotTradeLog $log) use (&$tradeBuckets, $normalizeSymbolForMatch, $buildReasoning) {
            $delta = is_numeric($log->signal_delta_pips) ? abs((float) $log->signal_delta_pips) : null;
            $spread = is_numeric($log->spread_pips) ? (float) $log->spread_pips : null;
            $metaPayload = is_array($log->meta_payload) ? $log->meta_payload : [];

            if (is_numeric($metaPayload['bot_score'] ?? null)) {
                $botScore = (int) $metaPayload['bot_score'];
            } else {
                $signalStrengthScore = $delta !== null ? min(100.0, ($delta / 10.0) * 100.0) : 0.0;
                $spreadScore = $spread !== null ? max(0.0, min(100.0, (1 - ($spread / 3.0)) * 100.0)) : 0.0;
                $botScore = (int) round(($signalStrengthScore * 0.7) + ($spreadScore * 0.3));
            }

            $log->linked_trade = trim((string) ($log->linked_trade ?? '')) !== '' ? (string) $log->linked_trade : '-';
            $storedOutcome = strtoupper(trim((string) ($log->trade_outcome ?? '')));
            $log->trade_outcome = $storedOutcome !== '' ? $storedOutcome : '-';
            $log->trade_pnl = is_numeric($log->trade_pnl ?? null) ? (float) $log->trade_pnl : null;

            if ($log->trade_outcome === 'ERROR' && empty($log->trade_resolved_at)) {
                $log->trade_outcome = 'PENDING';
            }

            $bucketKey = $normalizeSymbolForMatch((string) ($log->symbol ?? ''))
                .'|'.strtolower((string) ($log->side ?? ''))
                .'|'.strtolower((string) ($log->bot_key ?? $log->bot_name ?? 'default'));
            $candidateTrades = $tradeBuckets[$bucketKey] ?? [];
            $matchedTrade = null;

            foreach ($candidateTrades as $idx => $trade) {
                if ($trade->created_at?->lt($log->created_at)) {
                    continue;
                }
                if ($trade->created_at?->gt($log->created_at?->copy()->addMinutes(30))) {
                    break;
                }

                $matchedTrade = $trade;
                unset($tradeBuckets[$bucketKey][$idx]);
                break;
            }

            if ($matchedTrade) {
                $tradeResponse = $matchedTrade->meta_response;
                $orderId = null;
                $positionId = null;
                if (is_array($tradeResponse)) {
                    $firstOrder = is_array($tradeResponse['orders'][0] ?? null) ? $tradeResponse['orders'][0] : null;
                    $response = is_array($firstOrder['response'] ?? null) ? $firstOrder['response'] : [];
                    $orderId = $response['orderId'] ?? null;
                    $positionId = $response['positionId'] ?? null;
                }

                $ref = trim((string) ($matchedTrade->linked_trade ?? ''));
                if ($ref === '') {
                    $idRef = $positionId ?: $orderId ?: (string) $matchedTrade->id;
                    $ref = 'TRADE #'.$idRef;
                }
                $log->linked_trade = $ref;

                if ($matchedTrade->status === 'failed') {
                    $log->trade_outcome = 'FAILED';
                } elseif ($matchedTrade->status === 'success') {
                    $resolvedOutcome = strtoupper(trim((string) ($matchedTrade->trade_outcome ?? '')));
                    $log->trade_outcome = $resolvedOutcome !== '' ? $resolvedOutcome : 'PENDING';
                    if (is_numeric($matchedTrade->trade_pnl ?? null)) {
                        $log->trade_pnl = (float) $matchedTrade->trade_pnl;
                    }
                }
            } elseif ($log->status === 'ai_rejected' || str_ends_with((string) $log->status, '_rejected')) {
                $log->trade_outcome = 'NOT_OPENED';
            }

            $log->alert_reasoning = $buildReasoning($log->ai_summary, $log->message, $log->error_message, $metaPayload);
            $log->bot_score = max(0, min(100, $botScore));

            return $log;
        });
    }

    public function clearAlerts(Request $request)
    {
        $deleted = BotTradeLog::query()
            ->where('event_type', 'signal')
            ->delete();

        return redirect()
            ->route('bot.alerts')
            ->with('status', "Cleared {$deleted} alert record(s).");
    }

    private function buildAnalyticsPayload(
        Mt5Service $mt5Service,
        bool $allowRemoteFetch = true,
        string $context = 'unknown'
    ): array
    {
        $startedAt = microtime(true);
        $settings = AppSetting::singleton();
        $botProfile = $this->resolveAnalyticsBotProfile($settings);

        $openStart = microtime(true);
        $openSnapshot = $this->activeTradesSnapshotCached($mt5Service, $allowRemoteFetch, $botProfile);
        $openDurationMs = (int) round((microtime(true) - $openStart) * 1000);

        $positions = $openSnapshot['positions'] ?? [];

        $todayStatsStart = microtime(true);
        $todayStart = now()->startOfDay();
        $todayLogs = BotTradeLog::query()->where('created_at', '>=', $todayStart);

        $signalLogs = (clone $todayLogs)->where('event_type', 'signal');
        $visibleSignalLogs = (clone $signalLogs)->where('meta_payload->bot_score', '>=', 70);

        $stats = [
            'active_positions' => count($positions),
            'today_signals' => (clone $visibleSignalLogs)->count(),
            'today_opened' => (clone $todayLogs)->where('event_type', 'trade_open')->where('status', 'success')->count(),
            'today_rejected_ai' => (clone $visibleSignalLogs)->where('status', 'ai_rejected')->count(),
            'today_failed' => (clone $todayLogs)->where('event_type', 'trade_open')->where('status', 'failed')->count(),
            'today_trailing_updates' => (clone $todayLogs)->where('event_type', 'trailing_update')->where('status', 'success')->count(),
        ];
        $todayStatsDurationMs = (int) round((microtime(true) - $todayStatsStart) * 1000);

        $historyStart = microtime(true);
        $historyStats = $this->historyStatsCached($mt5Service, $allowRemoteFetch);
        $historyDurationMs = (int) round((microtime(true) - $historyStart) * 1000);
        $totalDurationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (
            $totalDurationMs >= self::ANALYTICS_SLOW_MS
            || !empty($openSnapshot['error'])
            || !empty($historyStats['history_error'])
        ) {
            logger()->warning('analytics payload timing', [
                'context' => $context,
                'allow_remote_fetch' => $allowRemoteFetch,
                'total_ms' => $totalDurationMs,
                'open_snapshot_ms' => $openDurationMs,
                'today_stats_ms' => $todayStatsDurationMs,
                'history_ms' => $historyDurationMs,
                'positions_count' => count($positions),
                'open_error' => $openSnapshot['error'] ?? null,
                'history_error' => $historyStats['history_error'] ?? null,
            ]);
        }

        return [
            'openSnapshot' => $openSnapshot,
            'positions' => $positions,
            'stats' => array_merge($stats, $historyStats),
        ];
    }

    private function analyticsFallbackPayload(?string $errorMessage = null): array
    {
        $error = trim((string) $errorMessage);

        return [
            'openSnapshot' => [
                'positions' => [],
                'orders' => [],
                'error' => $error !== '' ? $error : 'Could not load active positions right now.',
            ],
            'positions' => [],
            'stats' => [
                'active_positions' => 0,
                'today_signals' => 0,
                'today_opened' => 0,
                'today_rejected_ai' => 0,
                'today_failed' => 0,
                'today_trailing_updates' => 0,
                'total_pnl' => null,
                'total_trades' => null,
                'winning_trades' => null,
                'losing_trades' => null,
                'win_rate' => null,
                'avg_win' => null,
                'avg_loss' => null,
                'history_error' => $error !== '' ? $error : 'Analytics data is temporarily unavailable.',
            ],
        ];
    }

    private function openSnapshotCached(Mt5Service $mt5Service, bool $allowRemoteFetch = true, ?array $botProfile = null): array
    {
        $default = [
            'positions' => [],
            'orders' => [],
            'error' => null,
        ];

        $context = $this->resolveUiBrokerContext($botProfile ?? []);
        $cacheKey = 'bot_analytics_open_snapshot_'.md5(json_encode([
            'type' => $context['type'] ?? 'metaapi',
            'instances' => $context['instance_keys'] ?? [],
        ]));

        if ($allowRemoteFetch) {
            return Cache::remember($cacheKey, now()->addSeconds(30), function () use ($mt5Service, $default, $context) {
                return $this->fetchOpenSnapshotForContext($mt5Service, $context);
            });
        }

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return array_merge($default, $cached);
        }

        return $default;
    }

    /**
     * Enrich live open positions with current bid/ask from MetaAPI so active trades auto-update.
     *
     * Quotes are cached per symbol for a short period to avoid repeated lookups during auto-refresh.
     *
     * @param array<int, array<string, mixed>> $positions
     * @return array<int, array<string, mixed>>
     */
    private function enrichPositionsWithLiveQuotes(Mt5Service $mt5Service, array $positions, ?array $botProfile = null): array
    {
        $context = $this->resolveUiBrokerContext($botProfile ?? []);
        $quoteTargets = collect($positions)
            ->filter(static fn ($position) => is_array($position) && !empty($position['symbol']))
            ->map(static function ($position): array {
                return [
                    'symbol' => strtoupper((string) ($position['symbol'] ?? '')),
                    'terminal' => (string) ($position['terminal'] ?? ''),
                ];
            })
            ->unique(fn (array $target) => $target['symbol'].'|'.$target['terminal'])
            ->values();

        $quotesByTarget = [];
        foreach ($quoteTargets as $target) {
            $symbol = $target['symbol'];
            $terminal = $target['terminal'];
            $cacheKey = 'bot_analytics_live_quote_'
                .preg_replace('/[^A-Z0-9_]/', '_', $symbol)
                .'_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $terminal);
            $quotesByTarget[$symbol.'|'.$terminal] = Cache::remember($cacheKey, now()->addSeconds(10), function () use ($mt5Service, $symbol, $terminal, $context) {
                try {
                    if (($context['type'] ?? '') === 'ea_bridge' && $terminal !== '') {
                        return app(EaBridgeBroker::class)->forInstance($terminal)->getTickerPrice($symbol);
                    }
                    return $mt5Service->getTickerPrice($symbol);
                } catch (Throwable $e) {
                    logger()->warning('analytics live quote lookup failed', [
                        'symbol' => $symbol,
                        'terminal' => $terminal,
                        'error' => $e->getMessage(),
                    ]);

                    return null;
                }
            });
        }

        return array_map(static function (array $position) use ($quotesByTarget): array {
            $symbol = strtoupper((string) ($position['symbol'] ?? ''));
            $terminal = (string) ($position['terminal'] ?? '');
            $quote = $quotesByTarget[$symbol.'|'.$terminal] ?? null;

            if (is_array($quote)) {
                $type = strtoupper((string) ($position['type'] ?? 'BUY'));
                $livePrice = $type === 'SELL'
                    ? ($quote['ask'] ?? $quote['last'] ?? null)
                    : ($quote['bid'] ?? $quote['last'] ?? null);

                if (is_numeric($livePrice)) {
                    $position['currentPrice'] = (float) $livePrice;
                }

                if (is_numeric($position['openPrice'] ?? null) && is_numeric($position['currentPrice'] ?? null) && empty($position['profit'])) {
                    $openPrice = (float) $position['openPrice'];
                    $currentPrice = (float) $position['currentPrice'];
                    $direction = $type === 'SELL' ? ($openPrice - $currentPrice) : ($currentPrice - $openPrice);
                    $position['profit'] = round($direction, 5);
                }
            }

            return $position;
        }, $positions);
    }

    private function historyStatsCached(Mt5Service $mt5Service, bool $allowRemoteFetch = true): array
    {
        $cacheKey = 'bot_analytics_history_30d_v2';
        $default = [
            'total_pnl' => null,
            'total_trades' => null,
            'winning_trades' => null,
            'losing_trades' => null,
            'win_rate' => null,
            'avg_win' => null,
            'avg_loss' => null,
            'history_error' => null,
        ];

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return array_merge($default, $cached);
        }

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($default) {
            $from = now()->subDays(30);

            $resolvedTrades = BotTradeLog::query()
                ->where('event_type', 'trade_open')
                ->where('status', 'success')
                ->where('created_at', '>=', $from)
                ->whereIn('trade_outcome', ['WIN', 'LOSS', 'BREAKEVEN'])
                ->get(['trade_outcome', 'trade_pnl']);

            if ($resolvedTrades->isEmpty()) {
                return $default;
            }

            $profits = $resolvedTrades
                ->pluck('trade_pnl')
                ->filter(static fn ($value) => is_numeric($value))
                ->map(static fn ($value) => (float) $value)
                ->values();

            $totalTrades = $resolvedTrades->count();
            $winCount = $resolvedTrades->where('trade_outcome', 'WIN')->count();
            $lossCount = $resolvedTrades->where('trade_outcome', 'LOSS')->count();

            $wins = $profits->filter(static fn (float $value) => $value > 0)->values();
            $losses = $profits->filter(static fn (float $value) => $value < 0)->values();

            return array_merge($default, [
                'total_pnl' => $profits->sum(),
                'total_trades' => $totalTrades,
                'winning_trades' => $winCount,
                'losing_trades' => $lossCount,
                'win_rate' => $totalTrades > 0 ? round(($winCount / $totalTrades) * 100, 1) : null,
                'avg_win' => $wins->isNotEmpty() ? $wins->avg() : null,
                'avg_loss' => $losses->isNotEmpty() ? $losses->avg() : null,
                'history_error' => null,
            ]);
        });
    }

    private function resolveHealthBotProfile(AppSetting $settings): array
    {
        $profiles = is_array($settings->bot_profiles ?? null)
            ? array_values(array_filter($settings->bot_profiles, static fn ($profile) => is_array($profile) && (bool) ($profile['enabled'] ?? true)))
            : [];

        $latestBotKey = (string) (BotTradeLog::query()->latest()->value('bot_key') ?? '');
        if ($latestBotKey !== '') {
            foreach ($profiles as $profile) {
                $profileKey = (string) ($profile['key'] ?? '');
                $profileName = (string) ($profile['name'] ?? '');
                if ($profileKey === $latestBotKey || $profileName === $latestBotKey) {
                    return $profile;
                }
            }
        }

        return $profiles[0] ?? [];
    }

    private function resolveAnalyticsBotProfile(AppSetting $settings): array
    {
        $profiles = is_array($settings->bot_profiles ?? null)
            ? array_values(array_filter($settings->bot_profiles, static fn ($profile) => is_array($profile) && (bool) ($profile['enabled'] ?? true)))
            : [];

        foreach ($profiles as $profile) {
            if ((string) ($profile['key'] ?? '') === 'default') {
                return $profile;
            }
        }

        return $this->resolveHealthBotProfile($settings);
    }

    /**
     * @param  array<string, mixed>  $botProfile
     * @return array{type: string, instance_keys: array<int, string>, symbol_instance_map: array<string, string>}
     */
    private function resolveAnalyticsBrokerContext(array $botProfile): array
    {
        if (BrokerResolver::profileForexBroker($botProfile) !== 'ea_bridge') {
            return $this->resolveUiBrokerContext($botProfile);
        }

        $keys = Mt5EaTerminal::query()
            ->where('enabled', true)
            ->orderBy('display_name')
            ->pluck('instance_key')
            ->map(static fn ($key) => trim((string) $key))
            ->filter(static fn (string $key) => $key !== '')
            ->values()
            ->all();

        if ($keys === []) {
            $keys = EaBridgeService::profileInstanceKeys($botProfile);
        }

        return [
            'type' => 'ea_bridge',
            'instance_keys' => $keys,
            'symbol_instance_map' => [],
        ];
    }

    /**
     * @param  array{type: string, instance_keys: array<int, string>, symbol_instance_map: array<string, string>}  $context
     * @return array<string, mixed>
     */
    private function fetchOpenSnapshotForContext(Mt5Service $mt5Service, array $context): array
    {
        $default = [
            'positions' => [],
            'orders' => [],
            'error' => null,
        ];

        try {
            if (($context['type'] ?? '') === 'ea_bridge') {
                $snapshot = $this->aggregateEaOpenSnapshots($context['instance_keys'] ?? []);
            } else {
                $snapshot = $mt5Service->getOpenTradeSnapshot();
            }

            return is_array($snapshot) ? array_merge($default, $snapshot) : $default;
        } catch (Throwable $e) {
            $default['error'] = $e->getMessage();

            return $default;
        }
    }

    /**
     * @param  array<string, mixed>  $botProfile
     * @return array{type: string, instance_keys: array<int, string>, symbol_instance_map: array<string, string>}
     */
    private function resolveUiBrokerContext(array $botProfile): array
    {
        if (BrokerResolver::profileForexBroker($botProfile) !== 'ea_bridge') {
            return [
                'type' => 'metaapi',
                'instance_keys' => [],
                'symbol_instance_map' => [],
            ];
        }

        return [
            'type' => 'ea_bridge',
            'instance_keys' => EaBridgeService::profileInstanceKeys($botProfile),
            'symbol_instance_map' => [],
        ];
    }

    /**
     * @param  array<int, string>  $instanceKeys
     * @return array<string, mixed>
     */
    private function aggregateEaOpenSnapshots(array $instanceKeys): array
    {
        $positions = [];
        $orders = [];
        $terminals = [];
        $errors = [];
        $keys = $instanceKeys !== [] ? $instanceKeys : [null];

        foreach ($keys as $instanceKey) {
            try {
                $broker = app(EaBridgeBroker::class)->forInstance($instanceKey);
                $snapshot = $broker->getOpenTradeSnapshot();
                $label = $broker->instanceLabel();
                $resolvedKey = $broker->instanceKey() ?? $instanceKey ?? 'default';

                $terminals[] = [
                    'instance_key' => $resolvedKey,
                    'label' => $label,
                    'ok' => true,
                    'positions' => count($snapshot['positions'] ?? []),
                ];

                foreach (($snapshot['positions'] ?? []) as $position) {
                    if (!is_array($position)) {
                        continue;
                    }
                    $position['terminal'] = $resolvedKey;
                    $position['terminal_label'] = $label;
                    $positions[] = $position;
                }
            } catch (Throwable $e) {
                $resolvedKey = $instanceKey ?? 'default';
                $terminals[] = [
                    'instance_key' => $resolvedKey,
                    'label' => $resolvedKey,
                    'ok' => false,
                    'positions' => 0,
                    'error' => $e->getMessage(),
                ];
                $errors[] = $resolvedKey.': '.$e->getMessage();
            }
        }

        return [
            'positions' => $positions,
            'orders' => $orders,
            'terminals' => $terminals,
            'error' => $positions === [] && $errors !== [] ? implode(' | ', $errors) : null,
        ];
    }

    private function normalizeSignalTimeframes(?array $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $order = array_flip(self::ALLOWED_SIGNAL_TIMEFRAMES);
        $timeframes = array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            $raw
        ), static fn ($value) => isset($order[$value]))));

        usort($timeframes, static fn ($a, $b) => $order[$a] <=> $order[$b]);

        return !empty($timeframes) ? $timeframes : null;
    }

    private function normalizeStrategies(?array $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $order = array_flip(self::ALLOWED_STRATEGIES);
        $strategies = array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            $raw
        ), static fn ($value) => isset($order[$value]))));

        usort($strategies, static fn ($a, $b) => $order[$a] <=> $order[$b]);

        return !empty($strategies) ? $strategies : null;
    }

    /**
     * Resolve pending trade outcomes by checking MetaTrader open positions first, then broker history.
     */
    private function reconcilePendingTradeOutcomes(Mt5Service $mt5Service): void
    {
        $this->resolvePendingOutcomesFromActiveSnapshot($mt5Service);
        $this->syncPendingOutcomesFromHistory($mt5Service);
    }

    /**
     * Incrementally resolve pending/error trade outcomes from broker deal history.
     *
     * Runs on alerts load with a short lock to avoid duplicate network calls.
     *
     * @return int Number of trades resolved.
     */
    private function syncPendingOutcomesFromHistory(Mt5Service $mt5Service, ?Collection $tradeSubset = null): int
    {
        $syncLockKey = 'bot_pending_history_sync_lock_v2';
        if (!Cache::add($syncLockKey, true, now()->addSeconds(20))) {
            return 0;
        }

        try {
            $pendingTrades = $tradeSubset ?? BotTradeLog::query()
                ->where('event_type', 'trade_open')
                ->where('status', 'success')
                ->where(function ($query) {
                    $query->whereNull('trade_outcome')
                        ->orWhere('trade_outcome', 'PENDING')
                        ->orWhere('trade_outcome', 'ERROR');
                })
                ->whereNotNull('position_id')
                ->where('position_id', '!=', '')
                ->orderBy('created_at')
                ->get();

            if ($pendingTrades->isEmpty()) {
                return 0;
            }

            $dayKey = 'bot_pending_history_sync_calls_v2:'.now('UTC')->format('Ymd');
            $callsToday = (int) Cache::get($dayKey, 0);
            if ($callsToday >= self::MAX_HISTORY_SYNC_CALLS_PER_DAY) {
                return 0;
            }

            $oldestPending = $pendingTrades->first()?->created_at;
            $from = $oldestPending ? $oldestPending->copy()->subDays(2) : now()->subDays(30);
            $minFrom = now()->subDays(45);
            if ($from->lt($minFrom)) {
                $from = $minFrom;
            }
            $to = now()->addMinutes(1);
            Cache::put($dayKey, $callsToday + 1, now('UTC')->endOfDay());

            try {
                $deals = $mt5Service->getHistoryDeals($from, $to);
            } catch (Throwable $e) {
                logger()->warning('pending history sync skipped', [
                    'error' => $e->getMessage(),
                ]);

                return 0;
            }

            return $this->applyClosingDealsToTrades($pendingTrades, $deals, $tradeSubset !== null);
        } finally {
            Cache::forget($syncLockKey);
        }
    }

    /**
     * Match closing broker deals to trade logs and persist WIN/LOSS/BREAKEVEN outcomes.
     *
     * @param Collection<int, BotTradeLog> $trades
     * @param array<int, array<string, mixed>> $deals
     */
    private function applyClosingDealsToTrades(Collection $trades, array $deals, bool $markUnmatchedAsFailed = false): int
    {
        $byPosition = $this->buildClosingDealsByPosition($deals);
        $resolved = 0;

        foreach ($trades as $tradeLog) {
            $positionId = trim((string) ($tradeLog->position_id ?? ''));
            if ($positionId === '') {
                continue;
            }

            if (!isset($byPosition[$positionId])) {
                if ($markUnmatchedAsFailed) {
                    $this->markTradeOutcomeFailed($tradeLog);
                    $resolved++;
                }

                continue;
            }

            $matched = false;
            foreach ($byPosition[$positionId] as $idx => $deal) {
                if (($deal['used'] ?? false) === true) {
                    continue;
                }
                if ($deal['time']->lt($tradeLog->created_at)) {
                    continue;
                }

                $profit = (float) ($deal['profit'] ?? 0);
                $outcome = $profit > 0 ? 'WIN' : ($profit < 0 ? 'LOSS' : 'BREAKEVEN');

                $tradeLog->trade_outcome = $outcome;
                $tradeLog->trade_pnl = $profit;
                $tradeLog->trade_resolved_at = $deal['time'];
                $tradeLog->save();

                $byPosition[$positionId][$idx]['used'] = true;
                $matched = true;
                $resolved++;
                break;
            }

            if (!$matched && $markUnmatchedAsFailed) {
                $this->markTradeOutcomeFailed($tradeLog);
                $resolved++;
            }
        }

        return $resolved;
    }

    private function markTradeOutcomeFailed(BotTradeLog $tradeLog): void
    {
        $tradeLog->trade_outcome = 'FAILED';
        $tradeLog->trade_pnl = null;
        $tradeLog->trade_resolved_at = now();
        $tradeLog->save();
    }

    /**
     * @param array<int, array<string, mixed>> $deals
     * @return array<string, array<int, array{time: \Carbon\Carbon, profit: float, used: bool}>>
     */
    private function buildClosingDealsByPosition(array $deals): array
    {
        $byPosition = [];

        foreach ($deals as $deal) {
            if (!is_array($deal)) {
                continue;
            }

            $entry = strtoupper((string) ($deal['entryType'] ?? $deal['entry'] ?? ''));
            if (!str_contains($entry, 'OUT')) {
                continue;
            }

            $positionId = trim((string) ($deal['positionId'] ?? $deal['position_id'] ?? ''));
            if ($positionId === '') {
                continue;
            }

            $timeRaw = $deal['brokerTime'] ?? $deal['time'] ?? null;
            if (!$timeRaw) {
                continue;
            }

            try {
                $dealTime = \Carbon\Carbon::parse((string) $timeRaw);
            } catch (Throwable) {
                continue;
            }

            $byPosition[$positionId][] = [
                'time' => $dealTime,
                'profit' => (float) ($deal['profit'] ?? 0),
                'used' => false,
            ];
        }

        foreach ($byPosition as $positionId => $items) {
            usort($items, static fn ($a, $b) => $a['time']->lt($b['time']) ? -1 : 1);
            $byPosition[$positionId] = $items;
        }

        return $byPosition;
    }

    /**
     * Build the active-trades snapshot from pending trade_open logs in the database.
     *
     * @return array{positions: array<int, array<string, mixed>>, orders: array<int, mixed>, error: null}
     */
    private function buildActiveTradesSnapshotFromDb(): array
    {
        $activeTrades = BotTradeLog::query()
            ->where('event_type', 'trade_open')
            ->where('status', 'success')
            ->where('trade_outcome', 'PENDING')
            ->orderByDesc('created_at')
            ->get();

        $positions = $activeTrades->map(static function (BotTradeLog $log): array {
            $tradeType = strtolower((string) ($log->side ?? 'buy')) === 'sell' ? 'sell' : 'buy';

            return [
                'symbol' => (string) ($log->symbol ?? '-'),
                'type' => strtoupper($tradeType),
                'volume' => (float) ($log->lot_size ?? 0),
                'openPrice' => is_numeric($log->entry_price) ? (float) $log->entry_price : null,
                'currentPrice' => is_numeric($log->entry_price) ? (float) $log->entry_price : null,
                'stopLoss' => is_numeric($log->stop_loss) ? (float) $log->stop_loss : null,
                'takeProfit' => is_numeric($log->take_profit) ? (float) $log->take_profit : null,
                'profit' => is_numeric($log->trade_pnl) ? (float) $log->trade_pnl : 0,
                'positionId' => (string) ($log->position_id ?? ''),
                'orderId' => (string) ($log->order_id ?? ''),
                'tradeOutcome' => (string) ($log->trade_outcome ?? 'PENDING'),
            ];
        })->values()->all();

        return [
            'positions' => $positions,
            'orders' => [],
            'error' => null,
        ];
    }

    /**
     * Index live MetaTrader positions by broker position id.
     *
     * @param array<int, mixed> $livePositions
     * @return array<string, array<string, mixed>>
     */
    private function indexLivePositionsById(array $livePositions): array
    {
        $indexed = [];

        foreach ($livePositions as $position) {
            if (!is_array($position)) {
                continue;
            }

            foreach (['id', 'positionId'] as $key) {
                $positionId = trim((string) ($position[$key] ?? ''));
                if ($positionId !== '') {
                    $indexed[$positionId] = $position;
                }
            }
        }

        return $indexed;
    }

    /**
     * Merge a DB-backed snapshot row with the matching live MetaTrader position.
     *
     * @param array<string, mixed> $snapshotPosition
     * @param array<string, mixed> $livePosition
     * @return array<string, mixed>
     */
    private function mergeSnapshotWithLivePosition(array $snapshotPosition, array $livePosition): array
    {
        $type = strtoupper((string) ($livePosition['type'] ?? $snapshotPosition['type'] ?? 'BUY'));

        return [
            'symbol' => (string) ($livePosition['symbol'] ?? $snapshotPosition['symbol'] ?? '-'),
            'type' => str_contains($type, 'SELL') ? 'SELL' : 'BUY',
            'volume' => (float) ($livePosition['volume'] ?? $snapshotPosition['volume'] ?? 0),
            'openPrice' => is_numeric($livePosition['openPrice'] ?? $livePosition['priceOpen'] ?? null)
                ? (float) ($livePosition['openPrice'] ?? $livePosition['priceOpen'])
                : $snapshotPosition['openPrice'],
            'currentPrice' => is_numeric($livePosition['currentPrice'] ?? $livePosition['priceCurrent'] ?? null)
                ? (float) ($livePosition['currentPrice'] ?? $livePosition['priceCurrent'])
                : $snapshotPosition['currentPrice'],
            'stopLoss' => is_numeric($livePosition['stopLoss'] ?? null)
                ? (float) $livePosition['stopLoss']
                : $snapshotPosition['stopLoss'],
            'takeProfit' => is_numeric($livePosition['takeProfit'] ?? null)
                ? (float) $livePosition['takeProfit']
                : $snapshotPosition['takeProfit'],
            'profit' => is_numeric($livePosition['profit'] ?? $livePosition['unrealizedProfit'] ?? null)
                ? (float) ($livePosition['profit'] ?? $livePosition['unrealizedProfit'])
                : ($snapshotPosition['profit'] ?? 0),
            'positionId' => (string) ($livePosition['id'] ?? $livePosition['positionId'] ?? $snapshotPosition['positionId'] ?? ''),
            'orderId' => (string) ($snapshotPosition['orderId'] ?? ''),
            'tradeOutcome' => 'PENDING',
        ];
    }

    /**
     * Build analytics open positions from broker data first, then reconcile DB logs.
     *
     * @return array{positions: array<int, array<string, mixed>>, orders: array<int, mixed>, error: null|string, terminals?: array<int, mixed>, source?: string}
     */
    private function activeTradesSnapshotCached(Mt5Service $mt5Service, bool $allowRemoteFetch = true, ?array $botProfile = null): array
    {
        if (! $allowRemoteFetch) {
            $cached = Cache::get(self::ANALYTICS_LIVE_POSITIONS_CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }

            return $this->buildActiveTradesSnapshotFromDb();
        }

        $botProfile = $botProfile ?? [];
        $brokerContext = $this->resolveAnalyticsBrokerContext($botProfile);
        $liveSnapshot = $this->fetchOpenSnapshotForContext($mt5Service, $brokerContext);
        $livePositionsPayload = $liveSnapshot['positions'] ?? null;
        $livePositions = (is_array($livePositionsPayload) && array_is_list($livePositionsPayload))
            ? $livePositionsPayload
            : [];

        $this->reconcileOpenTradesWithBroker($mt5Service, $livePositions, $brokerContext);

        $positions = $this->mergeDbMetadataIntoLivePositions(
            $this->enrichPositionsWithLiveQuotes($mt5Service, $livePositions, $botProfile)
        );

        $snapshot = [
            'positions' => $positions,
            'orders' => is_array($liveSnapshot['orders'] ?? null) ? $liveSnapshot['orders'] : [],
            'error' => $liveSnapshot['error'] ?? null,
            'terminals' => $liveSnapshot['terminals'] ?? null,
            'source' => 'broker',
            'fetched_at' => now()->toDateTimeString(),
        ];

        Cache::put(self::ANALYTICS_LIVE_POSITIONS_CACHE_KEY, $snapshot, now()->addSeconds(15));

        return $snapshot;
    }

    /**
     * @param  array<int, array<string, mixed>>  $livePositions
     * @param  array{type: string, instance_keys: array<int, string>, symbol_instance_map: array<string, string>}  $brokerContext
     */
    private function reconcileOpenTradesWithBroker(Mt5Service $mt5Service, array $livePositions, array $brokerContext): void
    {
        $liveByPositionId = $this->indexLivePositionsById($livePositions);
        $this->syncTradeLogsWithOpenBrokerPositions($livePositions, $mt5Service);

        $pendingTrades = BotTradeLog::query()
            ->where('event_type', 'trade_open')
            ->where('status', 'success')
            ->where(function ($query) {
                $query->whereNull('trade_outcome')
                    ->orWhere('trade_outcome', 'PENDING')
                    ->orWhere('trade_outcome', 'ERROR');
            })
            ->where('created_at', '<=', now()->subMinutes(2))
            ->orderBy('created_at')
            ->get();

        if ($pendingTrades->isEmpty()) {
            return;
        }

        $closedCandidates = $pendingTrades->filter(function (BotTradeLog $tradeLog) use ($liveByPositionId, $livePositions, $mt5Service): bool {
            $positionId = trim((string) ($tradeLog->position_id ?? ''));
            if ($positionId !== '' && isset($liveByPositionId[$positionId])) {
                return false;
            }

            return ! $this->tradeLogMatchesAnyLivePosition($tradeLog, $livePositions, $mt5Service);
        })->values();

        if ($closedCandidates->isEmpty()) {
            return;
        }

        $resolved = 0;
        if (($brokerContext['type'] ?? '') !== 'ea_bridge') {
            $resolved = $this->syncPendingOutcomesFromHistory($mt5Service, $closedCandidates);
        }

        if ($resolved === 0) {
            foreach ($closedCandidates as $tradeLog) {
                if (! in_array((string) ($tradeLog->trade_outcome ?? ''), ['PENDING', 'ERROR', ''], true)) {
                    continue;
                }

                $tradeLog->trade_outcome = 'FAILED';
                $tradeLog->trade_pnl = null;
                $tradeLog->trade_resolved_at = now();
                $tradeLog->message = trim((string) ($tradeLog->message ?? '')) !== ''
                    ? (string) $tradeLog->message
                    : 'Position closed on broker; outcome unavailable from EA bridge history.';
                $tradeLog->save();
                $resolved++;
            }
        }

        if ($resolved > 0) {
            Cache::forget('bot_analytics_history_30d_v2');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $livePositions
     */
    private function syncTradeLogsWithOpenBrokerPositions(array $livePositions, Mt5Service $mt5Service): void
    {
        if ($livePositions === []) {
            return;
        }

        $pendingTrades = BotTradeLog::query()
            ->where('event_type', 'trade_open')
            ->where('status', 'success')
            ->where(function ($query) {
                $query->whereNull('trade_outcome')
                    ->orWhere('trade_outcome', 'PENDING')
                    ->orWhere('trade_outcome', 'ERROR');
            })
            ->orderByDesc('created_at')
            ->get();

        if ($pendingTrades->isEmpty()) {
            return;
        }

        $claimedLogIds = [];

        foreach ($livePositions as $livePosition) {
            if (! is_array($livePosition)) {
                continue;
            }

            $ticket = trim((string) ($livePosition['id'] ?? $livePosition['positionId'] ?? ''));
            if ($ticket === '') {
                continue;
            }

            $matchedLog = $pendingTrades->first(function (BotTradeLog $log) use ($livePosition, $ticket, $claimedLogIds, $mt5Service): bool {
                if (in_array($log->id, $claimedLogIds, true)) {
                    return false;
                }

                $existingPositionId = trim((string) ($log->position_id ?? ''));
                if ($existingPositionId !== '' && $existingPositionId === $ticket) {
                    return true;
                }

                return $this->tradeLogMatchesLivePosition($log, $livePosition, $mt5Service);
            });

            if ($matchedLog === null) {
                continue;
            }

            $claimedLogIds[] = $matchedLog->id;
            $updates = [];
            if (trim((string) ($matchedLog->position_id ?? '')) !== $ticket) {
                $updates['position_id'] = $ticket;
            }
            if (($matchedLog->trade_outcome ?? null) === 'ERROR') {
                $updates['trade_outcome'] = 'PENDING';
            }

            if ($updates !== []) {
                $matchedLog->fill($updates);
                $matchedLog->save();
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $livePositions
     */
    private function tradeLogMatchesAnyLivePosition(BotTradeLog $tradeLog, array $livePositions, Mt5Service $mt5Service): bool
    {
        foreach ($livePositions as $livePosition) {
            if (is_array($livePosition) && $this->tradeLogMatchesLivePosition($tradeLog, $livePosition, $mt5Service)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $livePosition
     */
    private function tradeLogMatchesLivePosition(BotTradeLog $tradeLog, array $livePosition, Mt5Service $mt5Service): bool
    {
        $logSymbol = $mt5Service->baseSymbol((string) ($tradeLog->symbol ?? ''));
        $liveSymbol = $mt5Service->baseSymbol((string) ($livePosition['symbol'] ?? ''));
        if ($logSymbol === '' || $liveSymbol === '' || $logSymbol !== $liveSymbol) {
            return false;
        }

        $logSide = strtoupper((string) ($tradeLog->side ?? 'buy')) === 'SELL' ? 'SELL' : 'BUY';
        $liveType = strtoupper((string) ($livePosition['type'] ?? 'BUY'));
        $liveSide = str_contains($liveType, 'SELL') ? 'SELL' : 'BUY';
        if ($logSide !== $liveSide) {
            return false;
        }

        $meta = is_array($tradeLog->meta_payload) ? $tradeLog->meta_payload : [];
        $logTerminal = trim((string) ($meta['ea_instance_key'] ?? ''));
        $liveTerminal = trim((string) ($livePosition['terminal'] ?? ''));

        return $logTerminal === '' || $liveTerminal === '' || $logTerminal === $liveTerminal;
    }

    /**
     * @param  array<int, array<string, mixed>>  $positions
     * @return array<int, array<string, mixed>>
     */
    private function mergeDbMetadataIntoLivePositions(array $positions): array
    {
        if ($positions === []) {
            return [];
        }

        $pendingLogs = BotTradeLog::query()
            ->where('event_type', 'trade_open')
            ->where('status', 'success')
            ->where(function ($query) {
                $query->whereNull('trade_outcome')
                    ->orWhere('trade_outcome', 'PENDING')
                    ->orWhere('trade_outcome', 'ERROR');
            })
            ->orderByDesc('created_at')
            ->get();

        return array_map(function (array $position) use ($pendingLogs): array {
            $ticket = trim((string) ($position['positionId'] ?? $position['id'] ?? ''));
            $matched = $pendingLogs->first(function (BotTradeLog $log) use ($ticket, $position): bool {
                if ($ticket !== '' && trim((string) ($log->position_id ?? '')) === $ticket) {
                    return true;
                }

                $meta = is_array($log->meta_payload) ? $log->meta_payload : [];
                $logTerminal = trim((string) ($meta['ea_instance_key'] ?? ''));
                $liveTerminal = trim((string) ($position['terminal'] ?? ''));

                return strtoupper((string) ($log->symbol ?? '')) === strtoupper((string) ($position['symbol'] ?? ''))
                    && strtoupper((string) ($log->side ?? '')) === strtoupper((string) ($position['type'] ?? ''))
                    && ($logTerminal === '' || $liveTerminal === '' || $logTerminal === $liveTerminal);
            });

            if ($matched !== null) {
                $position['bot_key'] = $matched->bot_key;
                $position['bot_name'] = $matched->bot_name;
                $position['linked_trade'] = $matched->linked_trade;
                if (! is_numeric($position['stopLoss'] ?? null) && is_numeric($matched->stop_loss)) {
                    $position['stopLoss'] = (float) $matched->stop_loss;
                }
                if (! is_numeric($position['takeProfit'] ?? null) && is_numeric($matched->take_profit)) {
                    $position['takeProfit'] = (float) $matched->take_profit;
                }
            }

            return $position;
        }, $positions);
    }

    private function enrichAlertLogStatus(BotTradeLog $log): void
    {
        if (($log->trade_outcome ?? null) === 'ERROR' && empty($log->trade_resolved_at)) {
            $log->trade_outcome = 'PENDING';
        }
    }

    /**
     * Resolve pending trades that are no longer open in MetaTrader by storing broker history outcomes.
     */
    private function resolvePendingOutcomesFromActiveSnapshot(Mt5Service $mt5Service): void
    {
        $settings = AppSetting::singleton();
        $botProfile = $this->resolveAnalyticsBotProfile($settings);
        $brokerContext = $this->resolveAnalyticsBrokerContext($botProfile);
        $liveSnapshot = $this->fetchOpenSnapshotForContext($mt5Service, $brokerContext);
        $livePositionsPayload = $liveSnapshot['positions'] ?? null;
        $livePositions = (is_array($livePositionsPayload) && array_is_list($livePositionsPayload))
            ? $livePositionsPayload
            : [];

        $this->reconcileOpenTradesWithBroker($mt5Service, $livePositions, $brokerContext);
    }
}
