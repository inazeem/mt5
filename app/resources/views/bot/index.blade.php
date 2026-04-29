<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Bot</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="bg-green-100 border border-green-200 text-green-800 p-4 rounded">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="bg-red-100 border border-red-200 text-red-800 p-4 rounded">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-yellow-50 border border-yellow-200 text-yellow-900 p-4 rounded">
                Demo-only is currently <strong>{{ $settings->demo_only ? 'ON' : 'OFF' }}</strong>.
            </div>

            <form method="POST" action="{{ route('bot.trade') }}" class="bg-white p-6 rounded-lg shadow space-y-4">
                @csrf

                @php
                    $topForexSymbols = is_array($topForexSymbols ?? null) && !empty($topForexSymbols)
                        ? $topForexSymbols
                        : ['EURUSD', 'GBPUSD', 'USDJPY', 'USDCHF', 'USDCAD', 'AUDUSD', 'NZDUSD', 'EURJPY'];
                    $oldLegs = old('exit_legs');
                    if (!is_array($oldLegs) || empty($oldLegs)) {
                        $oldLegs = [
                            ['close_percent' => '100', 'take_profit' => '', 'stop_loss' => ''],
                        ];
                    }
                @endphp

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Symbol</label>
                        <input id="trade-symbol-input" list="top-forex-symbols" name="symbol" value="{{ old('symbol', 'GBPUSD') }}" class="mt-1 block w-full rounded border-gray-300" />
                        <datalist id="top-forex-symbols">
                            @foreach ($topForexSymbols as $forexSymbol)
                                <option value="{{ $forexSymbol }}"></option>
                            @endforeach
                        </datalist>
                        <p class="mt-1 text-xs text-gray-500">Top 8 forex symbols are suggested from your account symbols, but you can type any broker symbol.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Lot Size</label>
                        <input type="number" step="0.01" min="0.01" name="lot_size" value="{{ old('lot_size', '0.01') }}" class="mt-1 block w-full rounded border-gray-300" />
                        <p class="mt-1 text-xs text-gray-500">Micro lots supported (for example: 0.01).</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Side</label>
                        <select name="side" class="mt-1 block w-full rounded border-gray-300">
                            <option value="buy" {{ old('side') === 'buy' ? 'selected' : '' }}>Buy</option>
                            <option value="sell" {{ old('side') === 'sell' ? 'selected' : '' }}>Sell</option>
                        </select>
                    </div>
                </div>

                <div class="rounded-lg border border-sky-200 bg-sky-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-sky-900">Current Ticker Price</h3>
                        <button type="button" id="refresh-price-btn" class="inline-flex items-center rounded-md border border-sky-300 bg-white px-3 py-1.5 text-xs font-semibold text-sky-700 hover:bg-sky-100">
                            Refresh
                        </button>
                    </div>
                    <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-4 text-sm">
                        <div><span class="text-sky-700">Symbol:</span> <span id="ticker-symbol" class="font-semibold text-sky-900">{{ $tickerPrice['symbol'] ?? old('symbol', 'GBPUSD') }}</span></div>
                        <div><span class="text-sky-700">Bid:</span> <span id="ticker-bid" class="font-semibold text-sky-900">{{ isset($tickerPrice['bid']) && $tickerPrice['bid'] !== null ? number_format((float) $tickerPrice['bid'], 5) : '-' }}</span></div>
                        <div><span class="text-sky-700">Ask:</span> <span id="ticker-ask" class="font-semibold text-sky-900">{{ isset($tickerPrice['ask']) && $tickerPrice['ask'] !== null ? number_format((float) $tickerPrice['ask'], 5) : '-' }}</span></div>
                        <div><span class="text-sky-700">Time:</span> <span id="ticker-time" class="font-semibold text-sky-900">{{ $tickerPrice['time'] ?? '-' }}</span></div>
                    </div>
                    <p id="ticker-error" class="mt-2 text-xs text-rose-700 {{ empty($tickerPrice['error']) ? 'hidden' : '' }}">{{ $tickerPrice['error'] ?? '' }}</p>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 space-y-3">
                    <h3 class="text-sm font-semibold text-gray-800">Multiple Exit Legs (TP/SL + Close %)</h3>
                    <p class="text-xs text-gray-600">Add as many legs as needed (up to 20). Close percentages must total 100% when used.</p>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-600">
                                    <th class="py-2 pr-3">Leg</th>
                                    <th class="py-2 pr-3">Close %</th>
                                    <th class="py-2 pr-3">Take Profit Price</th>
                                    <th class="py-2 pr-3">Stop Loss Price</th>
                                    <th class="py-2 pr-3">Action</th>
                                </tr>
                            </thead>
                            <tbody id="exit-legs-body" data-next-index="{{ count($oldLegs) }}">
                                @foreach ($oldLegs as $index => $leg)
                                    <tr class="exit-leg-row">
                                        <td class="py-2 pr-3 font-medium text-gray-700">{{ $index + 1 }}</td>
                                        <td class="py-2 pr-3">
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="100"
                                                name="exit_legs[{{ $index }}][close_percent]"
                                                value="{{ $leg['close_percent'] ?? '' }}"
                                                class="block w-28 rounded border-gray-300"
                                            />
                                        </td>
                                        <td class="py-2 pr-3">
                                            <input
                                                type="number"
                                                step="0.00001"
                                                min="0"
                                                name="exit_legs[{{ $index }}][take_profit]"
                                                value="{{ $leg['take_profit'] ?? '' }}"
                                                class="block w-full rounded border-gray-300"
                                            />
                                        </td>
                                        <td class="py-2 pr-3">
                                            <input
                                                type="number"
                                                step="0.00001"
                                                min="0"
                                                name="exit_legs[{{ $index }}][stop_loss]"
                                                value="{{ $leg['stop_loss'] ?? '' }}"
                                                class="block w-full rounded border-gray-300"
                                            />
                                        </td>
                                        <td class="py-2 pr-3">
                                            <button type="button" class="remove-exit-leg inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                                Remove
                                            </button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <button type="button" id="add-exit-leg" class="inline-flex items-center rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-semibold uppercase tracking-wider text-indigo-700 hover:bg-indigo-100">
                        Add Exit Leg
                    </button>
                </div>

                <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700">
                    Send To MT5
                </button>
            </form>

            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const legsBody = document.getElementById('exit-legs-body');
                    const addLegButton = document.getElementById('add-exit-leg');

                    if (!legsBody || !addLegButton) {
                        return;
                    }

                    const maxLegs = 20;

                    const updateLegNumbers = function () {
                        legsBody.querySelectorAll('tr.exit-leg-row').forEach(function (row, idx) {
                            const numberCell = row.querySelector('td');
                            if (numberCell) {
                                numberCell.textContent = String(idx + 1);
                            }
                        });
                    };

                    const bindRemoveButtons = function () {
                        legsBody.querySelectorAll('.remove-exit-leg').forEach(function (button) {
                            if (button.dataset.bound === '1') {
                                return;
                            }

                            button.dataset.bound = '1';
                            button.addEventListener('click', function () {
                                const rows = legsBody.querySelectorAll('tr.exit-leg-row');
                                if (rows.length <= 1) {
                                    return;
                                }

                                const row = button.closest('tr.exit-leg-row');
                                if (row) {
                                    row.remove();
                                    updateLegNumbers();
                                }
                            });
                        });
                    };

                    addLegButton.addEventListener('click', function () {
                        const rows = legsBody.querySelectorAll('tr.exit-leg-row');
                        if (rows.length >= maxLegs) {
                            alert('Maximum 20 exit legs allowed.');
                            return;
                        }

                        const index = Number(legsBody.dataset.nextIndex || '0');
                        legsBody.dataset.nextIndex = String(index + 1);

                        const row = document.createElement('tr');
                        row.className = 'exit-leg-row';
                        row.innerHTML = `
                            <td class="py-2 pr-3 font-medium text-gray-700">${rows.length + 1}</td>
                            <td class="py-2 pr-3">
                                <input type="number" step="0.01" min="0" max="100" name="exit_legs[${index}][close_percent]" class="block w-28 rounded border-gray-300" />
                            </td>
                            <td class="py-2 pr-3">
                                <input type="number" step="0.00001" min="0" name="exit_legs[${index}][take_profit]" class="block w-full rounded border-gray-300" />
                            </td>
                            <td class="py-2 pr-3">
                                <input type="number" step="0.00001" min="0" name="exit_legs[${index}][stop_loss]" class="block w-full rounded border-gray-300" />
                            </td>
                            <td class="py-2 pr-3">
                                <button type="button" class="remove-exit-leg inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                    Remove
                                </button>
                            </td>
                        `;

                        legsBody.appendChild(row);
                        bindRemoveButtons();
                        updateLegNumbers();
                    });

                    bindRemoveButtons();
                    updateLegNumbers();

                    const symbolInput = document.getElementById('trade-symbol-input');
                    const refreshPriceBtn = document.getElementById('refresh-price-btn');
                    const tickerSymbolEl = document.getElementById('ticker-symbol');
                    const tickerBidEl = document.getElementById('ticker-bid');
                    const tickerAskEl = document.getElementById('ticker-ask');
                    const tickerTimeEl = document.getElementById('ticker-time');
                    const tickerErrorEl = document.getElementById('ticker-error');

                    const setTickerLoading = function (symbol) {
                        tickerSymbolEl.textContent = symbol || '-';
                        tickerBidEl.textContent = '...';
                        tickerAskEl.textContent = '...';
                        tickerTimeEl.textContent = 'Updating...';
                        tickerErrorEl.classList.add('hidden');
                        tickerErrorEl.textContent = '';
                    };

                    const setTickerError = function (message) {
                        tickerBidEl.textContent = '-';
                        tickerAskEl.textContent = '-';
                        tickerTimeEl.textContent = '-';
                        tickerErrorEl.textContent = message;
                        tickerErrorEl.classList.remove('hidden');
                    };

                    const setTickerData = function (data) {
                        tickerSymbolEl.textContent = data.symbol || '-';
                        tickerBidEl.textContent = (typeof data.bid === 'number') ? data.bid.toFixed(5) : '-';
                        tickerAskEl.textContent = (typeof data.ask === 'number') ? data.ask.toFixed(5) : '-';
                        tickerTimeEl.textContent = data.time || '-';
                        tickerErrorEl.classList.add('hidden');
                        tickerErrorEl.textContent = '';
                    };

                    const fetchTicker = function () {
                        const symbol = (symbolInput?.value || '').trim().toUpperCase();
                        if (!symbol) {
                            setTickerError('Enter a symbol first.');
                            return;
                        }

                        setTickerLoading(symbol);

                        fetch(`{{ route('bot.price') }}?symbol=${encodeURIComponent(symbol)}`, {
                            method: 'GET',
                            headers: {
                                'Accept': 'application/json'
                            }
                        })
                            .then(function (response) {
                                return response.json().then(function (body) {
                                    return { ok: response.ok, body: body };
                                });
                            })
                            .then(function (result) {
                                if (!result.ok || !result.body.ok) {
                                    setTickerError(result.body.error || 'Failed to fetch ticker price.');
                                    return;
                                }

                                setTickerData(result.body.data || {});
                            })
                            .catch(function () {
                                setTickerError('Network error while fetching ticker price.');
                            });
                    };

                    if (symbolInput) {
                        symbolInput.addEventListener('change', fetchTicker);
                        symbolInput.addEventListener('blur', fetchTicker);
                    }

                    if (refreshPriceBtn) {
                        refreshPriceBtn.addEventListener('click', fetchTicker);
                    }
                });
            </script>

            @if (session('trade_result'))
                <div class="bg-white p-6 rounded-lg shadow">
                    <h3 class="text-lg font-semibold mb-2">Last Trade Result</h3>
                    <pre class="text-xs bg-gray-100 p-3 rounded overflow-x-auto">{{ json_encode(session('trade_result'), JSON_PRETTY_PRINT) }}</pre>
                </div>
            @endif

            @php
                $snapshot = session('open_snapshot', $openSnapshot ?? null);
                $positions = is_array($snapshot['positions'] ?? null) ? $snapshot['positions'] : [];
                $orders = is_array($snapshot['orders'] ?? null) ? $snapshot['orders'] : [];
                $snapshotError = $snapshot['error'] ?? null;
                $openPositionsCount = is_countable($positions) ? count($positions) : 0;
                $pendingOrdersCount = is_countable($orders) ? count($orders) : 0;
                $totalFloatingPnl = 0.0;

                foreach ($positions as $positionItem) {
                    if (is_array($positionItem)) {
                        $totalFloatingPnl += (float) ($positionItem['profit'] ?? $positionItem['unrealizedProfit'] ?? 0);
                    }
                }
            @endphp

            <section class="relative overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-950 via-slate-900 to-slate-800 text-slate-100 shadow-2xl">
                <div class="absolute -right-20 -top-24 h-52 w-52 rounded-full bg-cyan-400/20 blur-3xl"></div>
                <div class="absolute -left-16 -bottom-20 h-56 w-56 rounded-full bg-emerald-400/20 blur-3xl"></div>

                <div class="relative p-6 md:p-8 space-y-6">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-semibold tracking-wide">Live Position Monitor</h3>
                            <p class="text-sm text-slate-300 mt-1">Real-time view of positions and pending orders from your MetaApi account.</p>
                        </div>
                        @if (!empty($snapshot['fetched_at']))
                            <span class="inline-flex items-center rounded-full border border-slate-600 bg-slate-800/80 px-3 py-1 text-xs text-slate-200">Updated: {{ $snapshot['fetched_at'] }}</span>
                        @endif
                    </div>

                    @if ($snapshotError)
                        <div class="rounded-xl border border-red-300/40 bg-red-500/10 p-4 text-sm text-red-100">
                            Unable to fetch positions/orders: {{ $snapshotError }}
                        </div>
                    @else
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                            <div class="rounded-xl border border-slate-700/70 bg-slate-900/70 p-4 backdrop-blur">
                                <div class="text-xs uppercase tracking-widest text-slate-400">Open Positions</div>
                                <div class="mt-2 text-3xl font-bold text-cyan-300">{{ $openPositionsCount }}</div>
                            </div>
                            <div class="rounded-xl border border-slate-700/70 bg-slate-900/70 p-4 backdrop-blur">
                                <div class="text-xs uppercase tracking-widest text-slate-400">Pending Orders</div>
                                <div class="mt-2 text-3xl font-bold text-indigo-300">{{ $pendingOrdersCount }}</div>
                            </div>
                            <div class="rounded-xl border border-slate-700/70 bg-slate-900/70 p-4 backdrop-blur">
                                <div class="text-xs uppercase tracking-widest text-slate-400">Floating P/L</div>
                                <div class="mt-2 text-3xl font-bold {{ $totalFloatingPnl >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">
                                    {{ number_format($totalFloatingPnl, 2) }}
                                </div>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-sm font-semibold uppercase tracking-wider text-slate-300">Open Positions</h4>
                            @if ($openPositionsCount === 0)
                                <div class="rounded-xl border border-dashed border-slate-600 bg-slate-900/50 p-6 text-sm text-slate-300">
                                    No active positions right now.
                                </div>
                            @else
                                <div class="overflow-x-auto rounded-xl border border-slate-700/70 bg-slate-900/60">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-slate-800/80 text-slate-300">
                                            <tr>
                                                <th class="px-4 py-3 text-left font-semibold">Symbol</th>
                                                <th class="px-4 py-3 text-left font-semibold">Side</th>
                                                <th class="px-4 py-3 text-right font-semibold">Volume</th>
                                                <th class="px-4 py-3 text-right font-semibold">Open Price</th>
                                                <th class="px-4 py-3 text-right font-semibold">Current Price</th>
                                                <th class="px-4 py-3 text-right font-semibold">P/L</th>
                                                <th class="px-4 py-3 text-right font-semibold">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-800 text-slate-100">
                                            @foreach ($positions as $position)
                                                @php
                                                    $symbol = is_array($position) ? ($position['symbol'] ?? 'N/A') : 'N/A';
                                                    $type = strtoupper((string) (is_array($position) ? ($position['type'] ?? '') : ''));
                                                    $side = str_contains($type, 'SELL') ? 'SELL' : 'BUY';
                                                    $positionId = is_array($position) ? (string) ($position['id'] ?? $position['positionId'] ?? '') : '';
                                                    $volume = is_array($position) ? (float) ($position['volume'] ?? 0) : 0;
                                                    $openPrice = is_array($position) ? ($position['openPrice'] ?? $position['priceOpen'] ?? '-') : '-';
                                                    $currentPrice = is_array($position) ? ($position['currentPrice'] ?? $position['priceCurrent'] ?? '-') : '-';
                                                    $pnl = is_array($position) ? (float) ($position['profit'] ?? $position['unrealizedProfit'] ?? 0) : 0;
                                                @endphp
                                                <tr class="hover:bg-slate-800/60 transition-colors">
                                                    <td class="px-4 py-3 font-semibold tracking-wide">{{ $symbol }}</td>
                                                    <td class="px-4 py-3">
                                                        <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $side === 'SELL' ? 'bg-rose-500/20 text-rose-200 border border-rose-400/30' : 'bg-emerald-500/20 text-emerald-200 border border-emerald-400/30' }}">
                                                            {{ $side }}
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3 text-right">{{ number_format($volume, 2) }}</td>
                                                    <td class="px-4 py-3 text-right">{{ is_numeric($openPrice) ? number_format((float) $openPrice, 5) : $openPrice }}</td>
                                                    <td class="px-4 py-3 text-right">{{ is_numeric($currentPrice) ? number_format((float) $currentPrice, 5) : $currentPrice }}</td>
                                                    <td class="px-4 py-3 text-right font-semibold {{ $pnl >= 0 ? 'text-emerald-300' : 'text-rose-300' }}">{{ number_format($pnl, 2) }}</td>
                                                    <td class="px-4 py-3 text-right">
                                                        @if ($positionId !== '')
                                                            <form method="POST" action="{{ route('bot.close-position') }}" onsubmit="return confirm('Close position {{ $positionId }}?');">
                                                                @csrf
                                                                <input type="hidden" name="position_id" value="{{ $positionId }}" />
                                                                <button type="submit" class="inline-flex items-center rounded-md border border-rose-400/40 bg-rose-500/20 px-3 py-1.5 text-xs font-semibold text-rose-100 hover:bg-rose-500/30">
                                                                    Close
                                                                </button>
                                                            </form>
                                                        @else
                                                            <span class="text-xs text-slate-400">N/A</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>

                        <div class="space-y-4">
                            <h4 class="text-sm font-semibold uppercase tracking-wider text-slate-300">Pending Orders</h4>
                            @if ($pendingOrdersCount === 0)
                                <div class="rounded-xl border border-dashed border-slate-600 bg-slate-900/50 p-6 text-sm text-slate-300">
                                    No pending orders.
                                </div>
                            @else
                                <div class="overflow-x-auto rounded-xl border border-slate-700/70 bg-slate-900/60">
                                    <table class="min-w-full text-sm">
                                        <thead class="bg-slate-800/80 text-slate-300">
                                            <tr>
                                                <th class="px-4 py-3 text-left font-semibold">Symbol</th>
                                                <th class="px-4 py-3 text-left font-semibold">Type</th>
                                                <th class="px-4 py-3 text-right font-semibold">Volume</th>
                                                <th class="px-4 py-3 text-right font-semibold">Price</th>
                                                <th class="px-4 py-3 text-left font-semibold">State</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-800 text-slate-100">
                                            @foreach ($orders as $order)
                                                @php
                                                    $symbol = is_array($order) ? ($order['symbol'] ?? 'N/A') : 'N/A';
                                                    $type = is_array($order) ? (string) ($order['type'] ?? '-') : '-';
                                                    $volume = is_array($order) ? (float) ($order['volume'] ?? $order['currentVolume'] ?? 0) : 0;
                                                    $price = is_array($order) ? ($order['openPrice'] ?? $order['priceOpen'] ?? '-') : '-';
                                                    $state = is_array($order) ? (string) ($order['state'] ?? $order['status'] ?? '-') : '-';
                                                @endphp
                                                <tr class="hover:bg-slate-800/60 transition-colors">
                                                    <td class="px-4 py-3 font-semibold tracking-wide">{{ $symbol }}</td>
                                                    <td class="px-4 py-3">{{ $type }}</td>
                                                    <td class="px-4 py-3 text-right">{{ number_format($volume, 2) }}</td>
                                                    <td class="px-4 py-3 text-right">{{ is_numeric($price) ? number_format((float) $price, 5) : $price }}</td>
                                                    <td class="px-4 py-3">{{ $state }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>

                        <details class="rounded-xl border border-slate-700/70 bg-slate-900/60 p-3">
                            <summary class="cursor-pointer text-sm font-medium text-slate-300">Show positions JSON</summary>
                            <pre class="mt-3 overflow-x-auto rounded bg-slate-950 p-3 text-xs text-slate-200">{{ json_encode($positions, JSON_PRETTY_PRINT) }}</pre>
                        </details>

                        <details class="rounded-xl border border-slate-700/70 bg-slate-900/60 p-3">
                            <summary class="cursor-pointer text-sm font-medium text-slate-300">Show pending orders JSON</summary>
                            <pre class="mt-3 overflow-x-auto rounded bg-slate-950 p-3 text-xs text-slate-200">{{ json_encode($orders, JSON_PRETTY_PRINT) }}</pre>
                        </details>
                    @endif
                </div>
            </section>
        </div>
    </div>
</x-app-layout>
