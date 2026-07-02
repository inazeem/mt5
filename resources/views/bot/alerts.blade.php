<x-app-layout>
    <x-page-header title="Bot Alerts" subtitle="Signal, trade, and error logs with filters and export." />

    <div class="mx-auto max-w-7xl space-y-6">
        <x-flash-messages />

            <x-card>
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Alert Logs</h3>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('bot.alerts.clear') }}" onsubmit="return confirm('Clear all alert records? This cannot be undone.');">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center px-3 py-1.5 bg-rose-600 text-white text-xs font-semibold rounded hover:bg-rose-700">
                                Clear Alerts
                            </button>
                        </form>
                        <a href="{{ route('bot.health') }}"
                           class="inline-flex items-center px-3 py-1.5 bg-amber-500 text-white text-xs font-semibold rounded hover:bg-amber-600">
                            Bot Health
                        </a>
                        <a href="{{ route('bot.analytics') }}"
                           class="inline-flex items-center px-3 py-1.5 bg-gray-200 text-gray-700 text-xs font-semibold rounded hover:bg-gray-300">
                            Back to Analytics
                        </a>
                        <a href="{{ route('bot.alerts.export', request()->query()) }}"
                           class="inline-flex items-center px-3 py-1.5 bg-emerald-600 text-white text-xs font-semibold rounded hover:bg-emerald-700">
                            Export CSV
                        </a>
                    </div>
                </div>

                <form method="GET" action="{{ route('bot.alerts') }}" class="flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Date from</label>
                        <input type="date" name="date_from" value="{{ $dateFrom ?? '' }}"
                               class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring focus:ring-indigo-200">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Date to</label>
                        <input type="date" name="date_to" value="{{ $dateTo ?? '' }}"
                               class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring focus:ring-indigo-200">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Event type</label>
                        <select name="event_type" class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring focus:ring-indigo-200">
                            <option value="">All</option>
                            @foreach (['signal', 'trade_open', 'trailing_update', 'guardrail'] as $et)
                                <option value="{{ $et }}" {{ ($eventType ?? '') === $et ? 'selected' : '' }}>{{ $et }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Symbol</label>
                        <input type="text" name="symbol" value="{{ $symbol ?? '' }}" placeholder="e.g. GBPUSD"
                               class="border border-gray-300 rounded px-2 py-1 text-sm w-32 focus:outline-none focus:ring focus:ring-indigo-200">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Bot</label>
                        <select name="bot" class="border border-gray-300 rounded px-2 py-1 text-sm w-48 focus:outline-none focus:ring focus:ring-indigo-200">
                            <option value="">All</option>
                            @foreach (($botOptions ?? collect()) as $botOption)
                                @php
                                    $botValue = (string) ($botOption->bot_key ?? '');
                                    $botLabel = trim((string) ($botOption->bot_name ?? ''));
                                    if ($botLabel === '') {
                                        $botLabel = $botValue !== '' ? $botValue : 'Default';
                                    }
                                @endphp
                                <option value="{{ $botValue }}" {{ ($botFilter ?? '') === $botValue ? 'selected' : '' }}>{{ $botLabel }} ({{ $botValue !== '' ? $botValue : 'default' }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Per page</label>
                        <select name="per_page" class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring focus:ring-indigo-200">
                            @foreach ([25, 50, 100, 200] as $pp)
                                <option value="{{ $pp }}" {{ $perPage === $pp ? 'selected' : '' }}>{{ $pp }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">Filter</button>
                        <a href="{{ route('bot.alerts') }}" class="px-3 py-1.5 bg-gray-200 text-gray-700 text-xs font-semibold rounded hover:bg-gray-300">Clear</a>
                    </div>
                </form>

                @php
                    $summary = is_array($alertsSummary ?? null) ? $alertsSummary : ['won' => 0, 'lost' => 0, 'net' => 0, 'resolved_count' => 0];
                    $resolvedCount = (int) ($summary['resolved_count'] ?? 0);
                    $netValue = (float) ($summary['net'] ?? 0);
                    $netClass = $netValue > 0 ? 'text-emerald-800' : ($netValue < 0 ? 'text-rose-800' : 'text-sky-800');
                    $netPrefix = $netValue > 0 ? '+' : '';
                @endphp

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Total Won</div>
                        <div class="mt-1 text-lg font-bold text-emerald-800">+{{ number_format((float) ($summary['won'] ?? 0), 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-rose-200 bg-rose-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-rose-700">Total Lost</div>
                        <div class="mt-1 text-lg font-bold text-rose-800">-{{ number_format((float) ($summary['lost'] ?? 0), 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">Net P/L</div>
                        <div class="mt-1 text-lg font-bold {{ $netClass }}">{{ $netPrefix }}{{ number_format($netValue, 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Resolved Trades</div>
                        <div class="mt-1 text-lg font-bold text-indigo-800">{{ $resolvedCount }}</div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-2 pr-3">Date</th>
                                <th class="py-2 pr-3">Bot</th>
                                <th class="py-2 pr-3">Symbol</th>
                                <th class="py-2 pr-3">Direction</th>
                                <th class="py-2 pr-3">Limit Buy</th>
                                <th class="py-2 pr-3">SL</th>
                                <th class="py-2 pr-3">TP</th>
                                <th class="py-2 pr-3">Trade</th>
                                <th class="py-2 pr-3">Outcome</th>
                                <th class="py-2 pr-3">P/L</th>
                                <th class="py-2 pr-3">AI Score</th>
                                <th class="py-2 pr-3">Bot Score</th>
                                <th class="py-2 pr-3">Bot Thinking</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentLogs as $log)
                                <tr class="border-b border-gray-100 align-top">
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ $log->bot_name ?: ($log->bot_key ?: 'Default') }}</td>
                                    <td class="py-2 pr-3">{{ $log->symbol ?? '-' }}</td>
                                    <td class="py-2 pr-3">{{ $log->side ? strtoupper((string) $log->side) : '-' }}</td>
                                    <td class="py-2 pr-3">{{ is_numeric($log->entry_price) ? number_format((float) $log->entry_price, 5) : '-' }}</td>
                                    <td class="py-2 pr-3">{{ is_numeric($log->stop_loss) ? number_format((float) $log->stop_loss, 5) : '-' }}</td>
                                    <td class="py-2 pr-3">{{ is_numeric($log->take_profit) ? number_format((float) $log->take_profit, 5) : '-' }}</td>
                                    <td class="py-2 pr-3">{{ $log->linked_trade ?? '-' }}</td>
                                    <td class="py-2 pr-3">
                                        @php
                                            $outcome = strtoupper((string) ($log->trade_outcome ?? '-'));
                                            $outcomeClass = match ($outcome) {
                                                'WIN' => 'bg-emerald-100 text-emerald-700',
                                                'LOSS' => 'bg-rose-100 text-rose-700',
                                                'PENDING' => 'bg-amber-100 text-amber-700',
                                                'ERROR' => 'bg-orange-100 text-orange-700',
                                                'FAILED' => 'bg-gray-200 text-gray-700',
                                                'NOT_OPENED' => 'bg-slate-100 text-slate-700',
                                                'BREAKEVEN' => 'bg-sky-100 text-sky-700',
                                                default => 'bg-gray-100 text-gray-600',
                                            };
                                        @endphp
                                        <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-semibold {{ $outcomeClass }}">{{ $outcome }}</span>
                                    </td>
                                    <td class="py-2 pr-3 whitespace-nowrap">
                                        @php
                                            $tradePnl = is_numeric($log->trade_pnl ?? null) ? (float) $log->trade_pnl : null;
                                            $pnlClass = $tradePnl === null
                                                ? 'text-gray-500'
                                                : ($tradePnl > 0 ? 'text-emerald-700' : ($tradePnl < 0 ? 'text-rose-700' : 'text-sky-700'));
                                            $pnlText = $tradePnl === null ? '-' : (($tradePnl > 0 ? '+' : '').number_format($tradePnl, 2));
                                        @endphp
                                        <span class="font-semibold {{ $pnlClass }}">{{ $pnlText }}</span>
                                    </td>
                                    <td class="py-2 pr-3">{{ is_numeric($log->ai_confidence) ? (int) $log->ai_confidence.'%' : '-' }}</td>
                                    <td class="py-2 pr-3">{{ is_numeric($log->bot_score) ? (int) $log->bot_score.'%' : '-' }}</td>
                                    <td class="py-2 pr-3 max-w-md">
                                        @php
                                            $thinking = trim((string) ($log->alert_reasoning ?? ''));
                                            $summary = $thinking !== '' ? explode("\n", $thinking)[0] : '-';
                                        @endphp
                                        <div class="text-xs text-gray-700 truncate" title="{{ $summary }}">{{ $summary }}</div>
                                        @if ($thinking !== '')
                                            <button
                                                type="button"
                                                class="mt-1 inline-flex items-center rounded border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 hover:bg-indigo-100"
                                                data-thinking-view="1"
                                                data-thinking-title="{{ ($log->symbol ?? '-') }} {{ strtoupper((string) ($log->side ?? '-')) }} | {{ $log->created_at?->format('Y-m-d H:i:s') }}"
                                                data-thinking-body="{{ base64_encode($thinking) }}"
                                            >
                                                View
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="13" class="py-4 text-gray-500">No alerts yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $recentLogs->links() }}
                </div>
            </x-card>
    </div>

    <div id="bot-thinking-modal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40" data-thinking-close="1"></div>
        <div class="relative bg-white rounded-lg shadow-xl w-full max-w-3xl p-5">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-base font-semibold text-gray-900" id="bot-thinking-title">Bot Thinking</h3>
                <button type="button" class="text-gray-500 hover:text-gray-700" data-thinking-close="1">Close</button>
            </div>
            <pre id="bot-thinking-body" class="max-h-[60vh] overflow-auto whitespace-pre-wrap break-words text-xs bg-slate-50 border border-slate-200 rounded p-3 text-slate-800"></pre>
        </div>
    </div>

    <script>
        (function () {
            const modal = document.getElementById('bot-thinking-modal');
            const titleEl = document.getElementById('bot-thinking-title');
            const bodyEl = document.getElementById('bot-thinking-body');
            if (!modal || !titleEl || !bodyEl) return;

            document.querySelectorAll('[data-thinking-view="1"]').forEach((button) => {
                button.addEventListener('click', () => {
                    const title = button.getAttribute('data-thinking-title') || 'Bot Thinking';
                    const encoded = button.getAttribute('data-thinking-body') || '';
                    let text = '';
                    try {
                        text = atob(encoded);
                    } catch (_) {
                        text = 'Unable to decode bot thinking payload.';
                    }

                    titleEl.textContent = title;
                    bodyEl.textContent = text;
                    modal.classList.remove('hidden');
                    modal.classList.add('flex');
                });
            });

            document.querySelectorAll('[data-thinking-close="1"]').forEach((button) => {
                button.addEventListener('click', () => {
                    modal.classList.add('hidden');
                    modal.classList.remove('flex');
                });
            });
        })();
    </script>
</x-app-layout>
