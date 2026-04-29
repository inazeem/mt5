<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Bot Analytics</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <div class="bg-white p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Active Positions</div>
                    <div class="mt-1 text-2xl font-bold text-indigo-700">{{ $stats['active_positions'] }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Today Signals</div>
                    <div class="mt-1 text-2xl font-bold text-gray-800">{{ $stats['today_signals'] }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Today Opened</div>
                    <div class="mt-1 text-2xl font-bold text-emerald-700">{{ $stats['today_opened'] }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">AI Rejected</div>
                    <div class="mt-1 text-2xl font-bold text-amber-700">{{ $stats['today_rejected_ai'] }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Today Failed</div>
                    <div class="mt-1 text-2xl font-bold text-rose-700">{{ $stats['today_failed'] }}</div>
                </div>
                <div class="bg-white p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Trailing Updates</div>
                    <div class="mt-1 text-2xl font-bold text-cyan-700">{{ $stats['today_trailing_updates'] }}</div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <h3 class="text-lg font-semibold">Active Trades</h3>

                @if (!empty($openSnapshot['error']))
                    <div class="rounded border border-rose-200 bg-rose-50 text-rose-700 p-3 text-sm">
                        {{ $openSnapshot['error'] }}
                    </div>
                @endif

                @if (count($positions) === 0)
                    <div class="text-sm text-gray-500">No open positions.</div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-600 border-b">
                                    <th class="py-2 pr-4">Symbol</th>
                                    <th class="py-2 pr-4">Type</th>
                                    <th class="py-2 pr-4 text-right">Volume</th>
                                    <th class="py-2 pr-4 text-right">Open</th>
                                    <th class="py-2 pr-4 text-right">Current</th>
                                    <th class="py-2 pr-4 text-right">SL</th>
                                    <th class="py-2 pr-4 text-right">TP</th>
                                    <th class="py-2 pr-4 text-right">P/L</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($positions as $position)
                                    @php
                                        $symbol = is_array($position) ? (string) ($position['symbol'] ?? '-') : '-';
                                        $type = is_array($position) ? (string) ($position['type'] ?? '-') : '-';
                                        $volume = is_array($position) ? (float) ($position['volume'] ?? 0) : 0;
                                        $openPrice = is_array($position) ? ($position['openPrice'] ?? $position['priceOpen'] ?? null) : null;
                                        $currentPrice = is_array($position) ? ($position['currentPrice'] ?? $position['priceCurrent'] ?? null) : null;
                                        $sl = is_array($position) ? ($position['stopLoss'] ?? null) : null;
                                        $tp = is_array($position) ? ($position['takeProfit'] ?? null) : null;
                                        $pnl = is_array($position) ? (float) ($position['profit'] ?? $position['unrealizedProfit'] ?? 0) : 0;
                                    @endphp
                                    <tr class="border-b border-gray-100">
                                        <td class="py-2 pr-4 font-medium">{{ $symbol }}</td>
                                        <td class="py-2 pr-4">{{ $type }}</td>
                                        <td class="py-2 pr-4 text-right">{{ number_format($volume, 2) }}</td>
                                        <td class="py-2 pr-4 text-right">{{ is_numeric($openPrice) ? number_format((float) $openPrice, 5) : '-' }}</td>
                                        <td class="py-2 pr-4 text-right">{{ is_numeric($currentPrice) ? number_format((float) $currentPrice, 5) : '-' }}</td>
                                        <td class="py-2 pr-4 text-right">{{ is_numeric($sl) ? number_format((float) $sl, 5) : '-' }}</td>
                                        <td class="py-2 pr-4 text-right">{{ is_numeric($tp) ? number_format((float) $tp, 5) : '-' }}</td>
                                        <td class="py-2 pr-4 text-right {{ $pnl >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ number_format($pnl, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold">Recent Bot Logs</h3>
                    <a href="{{ route('bot.analytics.export') }}"
                       class="inline-flex items-center px-3 py-1.5 bg-emerald-600 text-white text-xs font-semibold rounded hover:bg-emerald-700">
                        Export CSV
                    </a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-2 pr-3">Time</th>
                                <th class="py-2 pr-3">Event</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2 pr-3">Symbol</th>
                                <th class="py-2 pr-3">Side</th>
                                <th class="py-2 pr-3">Signal (pips)</th>
                                <th class="py-2 pr-3">Spread (pips)</th>
                                <th class="py-2 pr-3">AI</th>
                                <th class="py-2 pr-3">Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentLogs as $log)
                                <tr class="border-b border-gray-100 align-top">
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td class="py-2 pr-3">{{ $log->event_type }}</td>
                                    <td class="py-2 pr-3">{{ $log->status }}</td>
                                    <td class="py-2 pr-3">{{ $log->symbol ?? '-' }}</td>
                                    <td class="py-2 pr-3">{{ $log->side ?? '-' }}</td>
                                    <td class="py-2 pr-3">{{ is_numeric($log->signal_delta_pips) ? number_format((float) $log->signal_delta_pips, 2) : '-' }}</td>
                                    <td class="py-2 pr-3">{{ is_numeric($log->spread_pips) ? number_format((float) $log->spread_pips, 2) : '-' }}</td>
                                    <td class="py-2 pr-3">{{ $log->ai_decision ? strtoupper($log->ai_decision) : '-' }}</td>
                                    <td class="py-2 pr-3 max-w-md whitespace-pre-wrap break-words">{{ $log->message ?? $log->error_message ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="py-4 text-gray-500">No logs yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
