<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Bot Analytics</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="text-xs text-gray-500" id="analytics-last-updated">Last updated: {{ now()->format('Y-m-d H:i:s') }}</div>
            <div class="grid grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-4">
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Active Positions</div>
                    <div id="stat-active_positions" class="mt-1 text-xl sm:text-2xl font-bold text-indigo-700">{{ $stats['active_positions'] }}</div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Today Signals</div>
                    <div id="stat-today_signals" class="mt-1 text-xl sm:text-2xl font-bold text-gray-800">{{ $stats['today_signals'] }}</div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Today Opened</div>
                    <div id="stat-today_opened" class="mt-1 text-xl sm:text-2xl font-bold text-emerald-700">{{ $stats['today_opened'] }}</div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">AI Rejected</div>
                    <div id="stat-today_rejected_ai" class="mt-1 text-xl sm:text-2xl font-bold text-amber-700">{{ $stats['today_rejected_ai'] }}</div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Today Failed</div>
                    <div id="stat-today_failed" class="mt-1 text-xl sm:text-2xl font-bold text-rose-700">{{ $stats['today_failed'] }}</div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Trailing Updates</div>
                    <div id="stat-today_trailing_updates" class="mt-1 text-xl sm:text-2xl font-bold text-cyan-700">{{ $stats['today_trailing_updates'] }}</div>
                </div>
            </div>

            {{-- P/L & Win Rate (last 30 days from MetaAPI history) --}}
            <div id="history-error" class="rounded border border-amber-200 bg-amber-50 text-amber-700 p-3 text-sm {{ empty($stats['history_error']) ? 'hidden' : '' }}">
                <span id="history-error-text">
                    Could not load history deals: {{ $stats['history_error'] }}
                </span>
            </div>

            <div class="grid grid-cols-3 md:grid-cols-4 lg:grid-cols-7 gap-3 sm:gap-4">
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow col-span-1">
                    <div class="text-xs uppercase text-gray-500">30d Total P/L</div>
                    @php $pnl = $stats['total_pnl']; @endphp
                    <div id="stat-total_pnl" class="mt-1 text-xl sm:text-2xl font-bold {{ $pnl === null ? 'text-gray-400' : ($pnl >= 0 ? 'text-emerald-700' : 'text-rose-700') }}">
                        {{ $pnl !== null ? ($pnl >= 0 ? '+' : '') . number_format($pnl, 2) : '—' }}
                    </div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Closed Trades</div>
                    <div id="stat-total_trades" class="mt-1 text-xl sm:text-2xl font-bold text-gray-800">{{ $stats['total_trades'] ?? '—' }}</div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Wins</div>
                    <div id="stat-winning_trades" class="mt-1 text-xl sm:text-2xl font-bold text-emerald-700">{{ $stats['winning_trades'] ?? '—' }}</div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Losses</div>
                    <div id="stat-losing_trades" class="mt-1 text-xl sm:text-2xl font-bold text-rose-700">{{ $stats['losing_trades'] ?? '—' }}</div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Win Rate</div>
                    <div id="stat-win_rate" class="mt-1 text-xl sm:text-2xl font-bold {{ is_numeric($stats['win_rate']) ? ((float) $stats['win_rate'] >= 50 ? 'text-emerald-700' : 'text-rose-700') : 'text-gray-400' }}">
                        {{ $stats['win_rate'] !== null ? $stats['win_rate'] . '%' : '—' }}
                    </div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Avg Win</div>
                    <div id="stat-avg_win" class="mt-1 text-xl sm:text-2xl font-bold text-emerald-700">
                        {{ $stats['avg_win'] !== null ? '+' . number_format($stats['avg_win'], 2) : '—' }}
                    </div>
                </div>
                <div class="bg-white p-3 sm:p-4 rounded-lg shadow">
                    <div class="text-xs uppercase text-gray-500">Avg Loss</div>
                    <div id="stat-avg_loss" class="mt-1 text-xl sm:text-2xl font-bold text-rose-700">
                        {{ $stats['avg_loss'] !== null ? number_format($stats['avg_loss'], 2) : '—' }}
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <h3 class="text-lg font-semibold">Active Trades</h3>

                @if (!empty($openSnapshot['error']))
                    <div id="active-positions-error" class="rounded border border-rose-200 bg-rose-50 text-rose-700 p-3 text-sm">
                        {{ $openSnapshot['error'] }}
                    </div>
                @else
                    <div id="active-positions-error" class="hidden rounded border border-rose-200 bg-rose-50 text-rose-700 p-3 text-sm"></div>
                @endif

                <div id="active-positions-empty" class="text-sm text-gray-500 {{ count($positions) === 0 ? '' : 'hidden' }}">No open positions.</div>

                <div id="active-positions-mobile" class="space-y-3 sm:hidden {{ count($positions) > 0 ? '' : 'hidden' }}">
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
                        <div class="rounded-lg border border-gray-200 p-3">
                            <div class="flex items-center justify-between">
                                <div class="font-semibold text-gray-900">{{ $symbol }}</div>
                                <div class="text-xs text-gray-500">{{ $type }}</div>
                            </div>
                            <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                                <div class="text-gray-500">Volume</div><div class="text-right text-gray-800">{{ number_format($volume, 2) }}</div>
                                <div class="text-gray-500">Open</div><div class="text-right text-gray-800">{{ is_numeric($openPrice) ? number_format((float) $openPrice, 5) : '-' }}</div>
                                <div class="text-gray-500">Current</div><div class="text-right text-gray-800">{{ is_numeric($currentPrice) ? number_format((float) $currentPrice, 5) : '-' }}</div>
                                <div class="text-gray-500">SL</div><div class="text-right text-gray-800">{{ is_numeric($sl) ? number_format((float) $sl, 5) : '-' }}</div>
                                <div class="text-gray-500">TP</div><div class="text-right text-gray-800">{{ is_numeric($tp) ? number_format((float) $tp, 5) : '-' }}</div>
                                <div class="text-gray-500">P/L</div><div class="text-right {{ $pnl >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ number_format($pnl, 2) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div id="active-positions-table-wrap" class="hidden sm:block overflow-x-auto {{ count($positions) > 0 ? '' : 'hidden' }}">
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
                        <tbody id="active-positions-body">
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
            </div>

            <div class="bg-white p-6 rounded-lg shadow">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">Alerts</h3>
                        <p class="text-sm text-gray-500">Detailed logs and filters moved to a dedicated page to keep analytics lightweight.</p>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('bot.alerts') }}"
                           class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">
                            Open Alerts
                        </a>
                        <a href="{{ route('bot.alerts.export') }}"
                           class="inline-flex items-center px-3 py-1.5 bg-emerald-600 text-white text-xs font-semibold rounded hover:bg-emerald-700">
                            Export Alerts CSV
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const liveUrl = @json(route('bot.analytics.live'));
            const fastIntervalMs = 20000;
            const maxIntervalMs = 120000;
            let currentInterval = fastIntervalMs;
            let timer = null;
            let inFlight = false;

            const setText = (id, text) => {
                const el = document.getElementById(id);
                if (el) el.textContent = text;
            };

            const formatNumber = (value, digits = 2) => {
                const n = Number(value);
                if (!Number.isFinite(n)) return '-';
                return n.toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });
            };

            const updateHistoryStyles = (stats) => {
                const pnlEl = document.getElementById('stat-total_pnl');
                if (pnlEl) {
                    pnlEl.classList.remove('text-gray-400', 'text-emerald-700', 'text-rose-700');
                    const pnl = Number(stats.total_pnl);
                    if (!Number.isFinite(pnl)) {
                        pnlEl.classList.add('text-gray-400');
                    } else if (pnl >= 0) {
                        pnlEl.classList.add('text-emerald-700');
                    } else {
                        pnlEl.classList.add('text-rose-700');
                    }
                }

                const winRateEl = document.getElementById('stat-win_rate');
                if (winRateEl) {
                    winRateEl.classList.remove('text-gray-400', 'text-emerald-700', 'text-rose-700');
                    const wr = Number(stats.win_rate);
                    if (!Number.isFinite(wr)) {
                        winRateEl.classList.add('text-gray-400');
                    } else if (wr >= 50) {
                        winRateEl.classList.add('text-emerald-700');
                    } else {
                        winRateEl.classList.add('text-rose-700');
                    }
                }
            };

            const updateError = (openError, historyError) => {
                const openEl = document.getElementById('active-positions-error');
                if (openEl) {
                    if (openError) {
                        openEl.textContent = openError;
                        openEl.classList.remove('hidden');
                    } else {
                        openEl.textContent = '';
                        openEl.classList.add('hidden');
                    }
                }

                const historyEl = document.getElementById('history-error');
                const historyTextEl = document.getElementById('history-error-text');
                if (historyEl && historyTextEl) {
                    if (historyError) {
                        historyTextEl.textContent = 'Could not load history deals: ' + historyError;
                        historyEl.classList.remove('hidden');
                    } else {
                        historyTextEl.textContent = '';
                        historyEl.classList.add('hidden');
                    }
                }
            };

            const renderPositions = (positions) => {
                const body = document.getElementById('active-positions-body');
                const empty = document.getElementById('active-positions-empty');
                const wrap = document.getElementById('active-positions-table-wrap');
                const mobile = document.getElementById('active-positions-mobile');
                if (!body || !empty || !wrap || !mobile) return;

                if (!Array.isArray(positions) || positions.length === 0) {
                    body.innerHTML = '';
                    mobile.innerHTML = '';
                    empty.classList.remove('hidden');
                    wrap.classList.add('hidden');
                    mobile.classList.add('hidden');
                    return;
                }

                empty.classList.add('hidden');
                wrap.classList.remove('hidden');
                mobile.classList.remove('hidden');

                const rows = positions.map((position) => {
                    const symbol = position?.symbol ?? '-';
                    const type = position?.type ?? '-';
                    const volume = formatNumber(position?.volume ?? 0, 2);
                    const openRaw = position?.openPrice ?? position?.priceOpen;
                    const currentRaw = position?.currentPrice ?? position?.priceCurrent;
                    const slRaw = position?.stopLoss;
                    const tpRaw = position?.takeProfit;
                    const openPrice = Number.isFinite(Number(openRaw)) ? formatNumber(openRaw, 5) : '-';
                    const currentPrice = Number.isFinite(Number(currentRaw)) ? formatNumber(currentRaw, 5) : '-';
                    const sl = Number.isFinite(Number(slRaw)) ? formatNumber(slRaw, 5) : '-';
                    const tp = Number.isFinite(Number(tpRaw)) ? formatNumber(tpRaw, 5) : '-';
                    const pnlNum = Number(position?.profit ?? 0);
                    const pnlClass = pnlNum >= 0 ? 'text-emerald-600' : 'text-rose-600';
                    const pnl = Number.isFinite(pnlNum) ? formatNumber(pnlNum, 2) : '-';

                    return `<tr class="border-b border-gray-100">
                        <td class="py-2 pr-4 font-medium">${symbol}</td>
                        <td class="py-2 pr-4">${type}</td>
                        <td class="py-2 pr-4 text-right">${volume}</td>
                        <td class="py-2 pr-4 text-right">${openPrice}</td>
                        <td class="py-2 pr-4 text-right">${currentPrice}</td>
                        <td class="py-2 pr-4 text-right">${sl}</td>
                        <td class="py-2 pr-4 text-right">${tp}</td>
                        <td class="py-2 pr-4 text-right ${pnlClass}">${pnl}</td>
                    </tr>`;
                }).join('');

                const cards = positions.map((position) => {
                    const symbol = position?.symbol ?? '-';
                    const type = position?.type ?? '-';
                    const volume = formatNumber(position?.volume ?? 0, 2);
                    const openRaw = position?.openPrice ?? position?.priceOpen;
                    const currentRaw = position?.currentPrice ?? position?.priceCurrent;
                    const openPrice = Number.isFinite(Number(openRaw)) ? formatNumber(openRaw, 5) : '-';
                    const currentPrice = Number.isFinite(Number(currentRaw)) ? formatNumber(currentRaw, 5) : '-';
                    const sl = Number.isFinite(Number(position?.stopLoss)) ? formatNumber(position.stopLoss, 5) : '-';
                    const tp = Number.isFinite(Number(position?.takeProfit)) ? formatNumber(position.takeProfit, 5) : '-';
                    const pnlNum = Number(position?.profit ?? 0);
                    const pnlClass = pnlNum >= 0 ? 'text-emerald-600' : 'text-rose-600';
                    const pnl = Number.isFinite(pnlNum) ? formatNumber(pnlNum, 2) : '-';

                    return `<div class="rounded-lg border border-gray-200 p-3">
                        <div class="flex items-center justify-between">
                            <div class="font-semibold text-gray-900">${symbol}</div>
                            <div class="text-xs text-gray-500">${type}</div>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                            <div class="text-gray-500">Volume</div><div class="text-right text-gray-800">${volume}</div>
                            <div class="text-gray-500">Open</div><div class="text-right text-gray-800">${openPrice}</div>
                            <div class="text-gray-500">Current</div><div class="text-right text-gray-800">${currentPrice}</div>
                            <div class="text-gray-500">SL</div><div class="text-right text-gray-800">${sl}</div>
                            <div class="text-gray-500">TP</div><div class="text-right text-gray-800">${tp}</div>
                            <div class="text-gray-500">P/L</div><div class="text-right ${pnlClass}">${pnl}</div>
                        </div>
                    </div>`;
                }).join('');

                body.innerHTML = rows;
                mobile.innerHTML = cards;
            };

            const updateStats = (stats) => {
                setText('stat-active_positions', String(stats.active_positions ?? 0));
                setText('stat-today_signals', String(stats.today_signals ?? 0));
                setText('stat-today_opened', String(stats.today_opened ?? 0));
                setText('stat-today_rejected_ai', String(stats.today_rejected_ai ?? 0));
                setText('stat-today_failed', String(stats.today_failed ?? 0));
                setText('stat-today_trailing_updates', String(stats.today_trailing_updates ?? 0));

                const totalPnl = Number(stats.total_pnl);
                setText('stat-total_pnl', Number.isFinite(totalPnl) ? `${totalPnl >= 0 ? '+' : ''}${formatNumber(totalPnl, 2)}` : '—');
                setText('stat-total_trades', stats.total_trades ?? '—');
                setText('stat-winning_trades', stats.winning_trades ?? '—');
                setText('stat-losing_trades', stats.losing_trades ?? '—');
                setText('stat-win_rate', Number.isFinite(Number(stats.win_rate)) ? `${stats.win_rate}%` : '—');
                setText('stat-avg_win', Number.isFinite(Number(stats.avg_win)) ? `+${formatNumber(stats.avg_win, 2)}` : '—');
                setText('stat-avg_loss', Number.isFinite(Number(stats.avg_loss)) ? `${formatNumber(stats.avg_loss, 2)}` : '—');

                updateHistoryStyles(stats);
            };

            const scheduleNext = () => {
                if (timer) window.clearTimeout(timer);
                if (document.hidden) return;
                timer = window.setTimeout(fetchLive, currentInterval);
            };

            const fetchLive = async () => {
                if (inFlight || document.hidden) {
                    scheduleNext();
                    return;
                }

                inFlight = true;
                try {
                    const res = await fetch(liveUrl, {
                        method: 'GET',
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                    });

                    if (!res.ok) {
                        throw new Error('HTTP ' + res.status);
                    }

                    const data = await res.json();
                    if (!data || data.ok !== true) {
                        throw new Error('Invalid payload');
                    }

                    updateStats(data.stats || {});
                    renderPositions(data.positions || []);
                    updateError(data.open_error || null, data?.stats?.history_error || null);
                    setText('analytics-last-updated', 'Last updated: ' + new Date(data.updated_at || Date.now()).toLocaleString());
                    currentInterval = fastIntervalMs;
                } catch (error) {
                    currentInterval = Math.min(maxIntervalMs, currentInterval * 2);
                } finally {
                    inFlight = false;
                    scheduleNext();
                }
            };

            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    currentInterval = fastIntervalMs;
                    fetchLive();
                } else if (timer) {
                    window.clearTimeout(timer);
                    timer = null;
                }
            });

            scheduleNext();
        })();
    </script>
</x-app-layout>
