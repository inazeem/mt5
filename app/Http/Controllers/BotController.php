<?php

namespace App\Http\Controllers;

use App\Models\BotTradeLog;
use App\Models\AppSetting;
use App\Services\Mt5Service;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BotController extends Controller
{
    private const ALLOWED_SIGNAL_TIMEFRAMES = ['5m', '15m', '30m', '1h', '4h'];
    private const ALLOWED_STRATEGIES = ['momentum', 'sma_cross', 'ema_cross', 'bollinger_reversion', 'vwap_reversion'];

    public function index(Mt5Service $mt5Service)
    {
        $settings = AppSetting::singleton();
        $openSnapshot = null;
        $tickerPrice = null;
        $topForexSymbols = ['EURUSD', 'GBPUSD', 'USDJPY', 'USDCHF', 'USDCAD', 'AUDUSD', 'NZDUSD', 'EURJPY'];
        $defaultSymbol = strtoupper((string) old('symbol', 'GBPUSD'));

        try {
            $openSnapshot = $mt5Service->getOpenTradeSnapshot();
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
        ]);

        $validated['bot_ai_confirm'] = $request->boolean('bot_ai_confirm');
        $validated['bot_strategies'] = $this->normalizeStrategies($validated['bot_strategies'] ?? null) ?? ['momentum'];
        $validated['bot_signal_timeframes'] = $this->normalizeSignalTimeframes($validated['bot_signal_timeframes'] ?? null) ?? ['15m'];
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

        $payload = $this->buildAnalyticsPayload($mt5Service);
        $openSnapshot = $payload['openSnapshot'];
        $positions = $payload['positions'];
        $stats = $payload['stats'];

        return view('bot.analytics', compact('settings', 'openSnapshot', 'positions', 'stats'));
    }

    public function analyticsLive(Mt5Service $mt5Service)
    {
        $payload = $this->buildAnalyticsPayload($mt5Service);

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
            ? array_values(array_filter(array_map(static fn ($symbol) => strtoupper(trim((string) $symbol)), $botProfile['symbols'])))
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
            ?? ['15m'];
        $signalTimeframes = array_values(array_unique(array_filter(array_map(
            static fn ($value) => strtolower(trim((string) $value)),
            is_array($timeframeSource) ? $timeframeSource : []
        ), static fn ($value) => in_array($value, $allowedSignalTimeframes, true))));
        if (empty($signalTimeframes)) {
            $signalTimeframes = ['15m'];
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
            'health_symbol' => $healthSymbol,
        ];

        $checks = [];

        try {
            $account = $mt5Service->getAccountInformation();
            $checks[] = [
                'name' => 'Account',
                'ok' => true,
                'detail' => 'Balance='.(float) ($account['balance'] ?? 0).' Equity='.(float) ($account['equity'] ?? 0),
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'name' => 'Account',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        try {
            $snapshot = $mt5Service->getOpenTradeSnapshot();
            $checks[] = [
                'name' => 'Open snapshot',
                'ok' => true,
                'detail' => 'Positions='.count($snapshot['positions'] ?? []).' Orders='.count($snapshot['orders'] ?? []),
            ];
        } catch (Throwable $e) {
            $checks[] = [
                'name' => 'Open snapshot',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

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

        $closingDealsBySymbol = [];
        $closingDealsByPosition = [];
        try {
            $deals = $mt5Service->getHistoryDeals(now()->subDays(30), now());
            foreach ($deals as $deal) {
                if (!is_array($deal)) {
                    continue;
                }

                $entry = strtoupper((string) ($deal['entryType'] ?? $deal['entry'] ?? ''));
                if (!str_contains($entry, 'OUT')) {
                    continue;
                }

                $symbolKey = $normalizeSymbolForMatch((string) ($deal['symbol'] ?? ''));
                if ($symbolKey === '') {
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

                $dealRecord = [
                    'time' => $dealTime,
                    'profit' => (float) ($deal['profit'] ?? 0),
                    'used' => false,
                ];

                $closingDealsBySymbol[$symbolKey][] = $dealRecord;

                $positionId = trim((string) ($deal['positionId'] ?? $deal['position_id'] ?? ''));
                if ($positionId !== '') {
                    $closingDealsByPosition[$positionId][] = $dealRecord;
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
        } catch (\Throwable) {
            $closingDealsBySymbol = [];
            $closingDealsByPosition = [];
        }

        $buildReasoning = static function (?string $aiSummary, ?string $message, ?string $errorMessage): string {
            $parts = [];

            foreach ([$aiSummary, $message, $errorMessage] as $value) {
                $text = trim((string) $value);
                if ($text === '' || in_array($text, $parts, true)) {
                    continue;
                }

                $parts[] = $text;
            }

            return implode("\n", $parts);
        };

        return $logs->transform(static function (BotTradeLog $log) use (&$tradeBuckets, &$closingDealsBySymbol, &$closingDealsByPosition, $normalizeSymbolForMatch, $buildReasoning) {
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

            $log->linked_trade = '-';
            $log->trade_outcome = '-';
            $log->trade_pnl = null;

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

                $ref = $positionId ?: $orderId ?: (string) $matchedTrade->id;
                $log->linked_trade = 'TRADE #'.$ref;

                if ($matchedTrade->status === 'failed') {
                    $log->trade_outcome = 'FAILED';
                } elseif ($matchedTrade->status === 'success') {
                    $log->trade_outcome = 'PENDING';

                    $resolveOutcome = static function (float $profit): string {
                        if ($profit > 0) {
                            return 'WIN';
                        }
                        if ($profit < 0) {
                            return 'LOSS';
                        }

                        return 'BREAKEVEN';
                    };

                    if (!empty($positionId) && isset($closingDealsByPosition[$positionId])) {
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
                            $log->trade_outcome = $resolveOutcome($profit);
                            $log->trade_pnl = $profit;
                            break;
                        }
                    }

                    $symbolKey = $normalizeSymbolForMatch((string) ($log->symbol ?? ''));
                    if ($log->trade_outcome === 'PENDING' && isset($closingDealsBySymbol[$symbolKey])) {
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
                            $log->trade_outcome = $resolveOutcome($profit);
                            $log->trade_pnl = $profit;
                            break;
                        }
                    }
                }
            } elseif ($log->status === 'ai_rejected' || str_ends_with((string) $log->status, '_rejected')) {
                $log->trade_outcome = 'NOT_OPENED';
            }

            $log->alert_reasoning = $buildReasoning($log->ai_summary, $log->message, $log->error_message);
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

    private function buildAnalyticsPayload(Mt5Service $mt5Service): array
    {
        $openSnapshot = [
            'positions' => [],
            'orders' => [],
            'error' => null,
        ];

        try {
            $openSnapshot = $mt5Service->getOpenTradeSnapshot();
        } catch (Throwable $e) {
            $openSnapshot['error'] = $e->getMessage();
        }

        $positions = is_array($openSnapshot['positions'] ?? null) ? $openSnapshot['positions'] : [];
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

        $historyStats = $this->historyStatsCached($mt5Service);

        return [
            'openSnapshot' => $openSnapshot,
            'positions' => $positions,
            'stats' => array_merge($stats, $historyStats),
        ];
    }

    private function historyStatsCached(Mt5Service $mt5Service): array
    {
        return Cache::remember('bot_analytics_history_30d', now()->addMinutes(10), function () use ($mt5Service) {
            $history = [
                'total_pnl' => null,
                'total_trades' => null,
                'winning_trades' => null,
                'losing_trades' => null,
                'win_rate' => null,
                'avg_win' => null,
                'avg_loss' => null,
                'history_error' => null,
            ];

            try {
                $deals = $mt5Service->getHistoryDeals(now()->subDays(30), now());

                $closingDeals = array_filter($deals, static function (mixed $deal): bool {
                    if (!is_array($deal)) {
                        return false;
                    }
                    $entry = strtoupper((string) ($deal['entryType'] ?? $deal['entry'] ?? ''));
                    return str_contains($entry, 'OUT');
                });

                $wins = array_filter($closingDeals, fn ($d) => (float) ($d['profit'] ?? 0) > 0);
                $losses = array_filter($closingDeals, fn ($d) => (float) ($d['profit'] ?? 0) < 0);

                $totalPnl = array_sum(array_column(array_values($closingDeals), 'profit'));
                $totalTrades = count($closingDeals);
                $winCount = count($wins);
                $lossCount = count($losses);

                $history['total_pnl'] = $totalPnl;
                $history['total_trades'] = $totalTrades;
                $history['winning_trades'] = $winCount;
                $history['losing_trades'] = $lossCount;
                $history['win_rate'] = $totalTrades > 0 ? round(($winCount / $totalTrades) * 100, 1) : null;
                $history['avg_win'] = $winCount > 0
                    ? array_sum(array_column(array_values($wins), 'profit')) / $winCount
                    : null;
                $history['avg_loss'] = $lossCount > 0
                    ? array_sum(array_column(array_values($losses), 'profit')) / $lossCount
                    : null;
            } catch (Throwable $e) {
                $history['history_error'] = $e->getMessage();
            }

            return $history;
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
}
