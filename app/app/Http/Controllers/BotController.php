<?php

namespace App\Http\Controllers;

use App\Models\BotTradeLog;
use App\Models\AppSetting;
use App\Services\Mt5Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class BotController extends Controller
{
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

    public function alerts(Request $request, Mt5Service $mt5Service)
    {
        $validated = $request->validate([
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date'],
            'event_type' => ['nullable', 'string', 'in:signal,trade_open,trailing_update,guardrail'],
            'symbol'     => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]*$/'],
            'per_page'   => ['nullable', 'integer', 'in:25,50,100,200'],
        ]);

        $dateFrom  = !empty($validated['date_from']) ? $validated['date_from'] : null;
        $dateTo    = !empty($validated['date_to'])   ? $validated['date_to']   : null;
        $eventType = $validated['event_type'] ?? 'signal';
        $symbol    = !empty($validated['symbol']) ? strtoupper($validated['symbol']) : null;
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

        // Show only high-quality signal alerts (bot score >= 70) in this page.
        $logsQuery->where(function ($query) {
            $query->where('event_type', '!=', 'signal')
                ->orWhere('meta_payload->bot_score', '>=', 70);
        });

        $recentLogs = $logsQuery->paginate($perPage)->withQueryString();

        $alertsCollection = $recentLogs->getCollection();
        $symbols = $alertsCollection
            ->pluck('symbol')
            ->filter()
            ->map(static fn ($s) => strtoupper((string) $s))
            ->unique()
            ->values();

        $timeStart = $alertsCollection->min('created_at');
        $timeEnd = $alertsCollection->max('created_at');

        $tradeCandidates = collect();
        if ($timeStart && $timeEnd && $symbols->isNotEmpty()) {
            $tradeCandidates = BotTradeLog::query()
                ->where('event_type', 'trade_open')
                ->whereIn('symbol', $symbols->all())
                ->whereBetween('created_at', [$timeStart, $timeEnd->copy()->addDay()])
                ->orderBy('created_at')
                ->get();
        }

        $tradeBuckets = [];
        foreach ($tradeCandidates as $tradeLog) {
            $key = strtoupper((string) ($tradeLog->symbol ?? '')).'|'.strtolower((string) ($tradeLog->side ?? ''));
            if (!isset($tradeBuckets[$key])) {
                $tradeBuckets[$key] = [];
            }
            $tradeBuckets[$key][] = $tradeLog;
        }

        $closingDealsBySymbol = [];
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

                $symbolKey = strtoupper((string) ($deal['symbol'] ?? ''));
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

                $closingDealsBySymbol[$symbolKey][] = [
                    'time' => $dealTime,
                    'profit' => (float) ($deal['profit'] ?? 0),
                    'used' => false,
                ];
            }

            foreach ($closingDealsBySymbol as $symbolKey => $symbolDeals) {
                usort($symbolDeals, static fn ($a, $b) => $a['time']->lessThan($b['time']) ? -1 : 1);
                $closingDealsBySymbol[$symbolKey] = $symbolDeals;
            }
        } catch (\Throwable) {
            $closingDealsBySymbol = [];
        }

        $recentLogs->getCollection()->transform(static function (BotTradeLog $log) use (&$tradeBuckets, &$closingDealsBySymbol) {
            $delta = is_numeric($log->signal_delta_pips) ? abs((float) $log->signal_delta_pips) : null;
            $spread = is_numeric($log->spread_pips) ? (float) $log->spread_pips : null;

            // Bot score is a heuristic quality score from signal strength and spread quality.
            $signalStrengthScore = $delta !== null ? min(100.0, ($delta / 10.0) * 100.0) : 0.0;
            $spreadScore = $spread !== null ? max(0.0, min(100.0, (1 - ($spread / 3.0)) * 100.0)) : 0.0;
            $botScore = (int) round(($signalStrengthScore * 0.7) + ($spreadScore * 0.3));

            $log->linked_trade = '-';
            $log->trade_outcome = '-';

            $bucketKey = strtoupper((string) ($log->symbol ?? '')).'|'.strtolower((string) ($log->side ?? ''));
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
                    $resp = is_array($firstOrder['response'] ?? null) ? $firstOrder['response'] : [];
                    $orderId = $resp['orderId'] ?? null;
                    $positionId = $resp['positionId'] ?? null;
                }

                $ref = $positionId ?: $orderId ?: (string) $matchedTrade->id;
                $log->linked_trade = 'TRADE #'.$ref;

                if ($matchedTrade->status === 'failed') {
                    $log->trade_outcome = 'FAILED';
                } elseif ($matchedTrade->status === 'success') {
                    $log->trade_outcome = 'PENDING';

                    $symbolKey = strtoupper((string) ($log->symbol ?? ''));
                    if (isset($closingDealsBySymbol[$symbolKey])) {
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
                            if ($profit > 0) {
                                $log->trade_outcome = 'WIN';
                            } elseif ($profit < 0) {
                                $log->trade_outcome = 'LOSS';
                            } else {
                                $log->trade_outcome = 'BREAKEVEN';
                            }
                            break;
                        }
                    }
                }
            } elseif ($log->status === 'ai_rejected' || str_ends_with((string) $log->status, '_rejected')) {
                $log->trade_outcome = 'NOT_OPENED';
            }

            $log->alert_reasoning = trim((string) ($log->ai_summary ?: $log->message ?: $log->error_message ?: ''));
            $log->bot_score = max(0, min(100, $botScore));

            return $log;
        });

        return view('bot.alerts', compact('recentLogs', 'dateFrom', 'dateTo', 'eventType', 'symbol', 'perPage'));
    }

    public function exportCsv()
    {
        $logs = BotTradeLog::query()->latest()->get();

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="bot_trade_logs_'.now()->format('Ymd_His').'.csv"',
        ];

        $callback = function () use ($logs) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'id', 'created_at', 'event_type', 'status', 'symbol', 'side',
                'lot_size', 'entry_price', 'take_profit', 'stop_loss',
                'spread_pips', 'signal_delta_pips', 'ai_provider', 'ai_decision',
                'ai_confidence', 'ai_summary', 'message', 'error_message',
            ]);

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->id,
                    $log->created_at?->toDateTimeString(),
                    $log->event_type,
                    $log->status,
                    $log->symbol,
                    $log->side,
                    $log->lot_size,
                    $log->entry_price,
                    $log->take_profit,
                    $log->stop_loss,
                    $log->spread_pips,
                    $log->signal_delta_pips,
                    $log->ai_provider,
                    $log->ai_decision,
                    $log->ai_confidence,
                    $log->ai_summary,
                    $log->message,
                    $log->error_message,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
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

        $stats = [
            'active_positions' => count($positions),
            'today_signals' => (clone $todayLogs)->where('event_type', 'signal')->count(),
            'today_opened' => (clone $todayLogs)->where('event_type', 'trade_open')->where('status', 'success')->count(),
            'today_rejected_ai' => (clone $todayLogs)->where('event_type', 'signal')->where('status', 'ai_rejected')->count(),
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
}
