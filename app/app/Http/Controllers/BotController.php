<?php

namespace App\Http\Controllers;

use App\Models\BotTradeLog;
use App\Models\AppSetting;
use App\Services\Mt5Service;
use Illuminate\Http\Request;
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

    public function analytics(Request $request, Mt5Service $mt5Service)
    {
        $validated = $request->validate([
            'date_from'  => ['nullable', 'date'],
            'date_to'    => ['nullable', 'date'],
            'event_type' => ['nullable', 'string', 'in:signal,trade_open,trailing_update,guardrail'],
            'symbol'     => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]*$/'],
            'per_page'   => ['nullable', 'integer', 'in:25,50,100,200'],
        ]);

        $settings = AppSetting::singleton();

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

        $todayLogs = BotTradeLog::query()
            ->where('created_at', '>=', $todayStart)
            ->get();

        $dateFrom  = !empty($validated['date_from']) ? $validated['date_from'] : null;
        $dateTo    = !empty($validated['date_to'])   ? $validated['date_to']   : null;
        $eventType = $validated['event_type'] ?? null;
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

        $recentLogs = $logsQuery->paginate($perPage)->withQueryString();

        $stats = [
            'active_positions' => count($positions),
            'today_signals' => $todayLogs->where('event_type', 'signal')->count(),
            'today_opened' => $todayLogs->where('event_type', 'trade_open')->where('status', 'success')->count(),
            'today_rejected_ai' => $todayLogs->where('event_type', 'signal')->where('status', 'ai_rejected')->count(),
            'today_failed' => $todayLogs->where('event_type', 'trade_open')->where('status', 'failed')->count(),
            'today_trailing_updates' => $todayLogs->where('event_type', 'trailing_update')->where('status', 'success')->count(),
            // P/L and win-rate from MetaAPI history deals (last 30 days)
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

            // Keep only position-exit deals (realized P/L). MetaAPI marks these with
            // entryType = DEAL_ENTRY_OUT or type containing DEAL_TYPE_SELL / DEAL_TYPE_BUY
            // when they correspond to closing a position.
            $closingDeals = array_filter($deals, static function (mixed $deal): bool {
                if (!is_array($deal)) {
                    return false;
                }
                $entry = strtoupper((string) ($deal['entryType'] ?? $deal['entry'] ?? ''));
                // MetaAPI: DEAL_ENTRY_OUT = closing deal, DEAL_ENTRY_INOUT = partial close
                return str_contains($entry, 'OUT');
            });

            $wins  = array_filter($closingDeals, fn ($d) => (float) ($d['profit'] ?? 0) > 0);
            $losses = array_filter($closingDeals, fn ($d) => (float) ($d['profit'] ?? 0) < 0);

            $totalPnl    = array_sum(array_column(array_values($closingDeals), 'profit'));
            $totalTrades = count($closingDeals);
            $winCount    = count($wins);
            $lossCount   = count($losses);

            $stats['total_pnl']      = $totalPnl;
            $stats['total_trades']   = $totalTrades;
            $stats['winning_trades'] = $winCount;
            $stats['losing_trades']  = $lossCount;
            $stats['win_rate']       = $totalTrades > 0 ? round(($winCount / $totalTrades) * 100, 1) : null;
            $stats['avg_win']        = $winCount > 0
                ? array_sum(array_column(array_values($wins), 'profit')) / $winCount
                : null;
            $stats['avg_loss']       = $lossCount > 0
                ? array_sum(array_column(array_values($losses), 'profit')) / $lossCount
                : null;
        } catch (Throwable $e) {
            $stats['history_error'] = $e->getMessage();
        }

        return view('bot.analytics', compact('settings', 'openSnapshot', 'positions', 'recentLogs', 'stats', 'dateFrom', 'dateTo', 'eventType', 'symbol', 'perPage'));
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
                'ai_summary', 'message', 'error_message',
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
                    $log->ai_summary,
                    $log->message,
                    $log->error_message,
                ]);
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, $headers);
    }
}
