<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Strategy Parameters</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="bg-green-100 border border-green-200 text-green-800 p-4 rounded">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="bg-rose-50 border border-rose-200 text-rose-800 p-4 rounded">
                    <h4 class="font-semibold mb-1">Please fix the following:</h4>
                    <ul class="list-disc list-inside text-sm space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php
                $params = old('bot_strategy_params', $settings->bot_strategy_params ?? []);
                $activeStrategies = $settings->bot_strategies ?? ['momentum'];
                if (!is_array($activeStrategies) || empty($activeStrategies)) {
                    $activeStrategies = ['momentum'];
                }

                $strategyCards = [
                    [
                        'key' => 'momentum',
                        'name' => 'Momentum',
                        'desc' => 'Uses live tick acceleration. No custom strategy fields here.',
                        'status' => 'Uses Bot Settings: Min Move Pips.',
                    ],
                    [
                        'key' => 'sma_cross',
                        'name' => 'SMA Cross',
                        'desc' => 'Signals when fast SMA crosses slow SMA.',
                        'status' => 'Editable fields: Fast Period, Slow Period.',
                    ],
                    [
                        'key' => 'ema_cross',
                        'name' => 'EMA Cross',
                        'desc' => 'Like SMA cross, but reacts faster to recent price.',
                        'status' => 'Editable fields: Fast Period, Slow Period.',
                    ],
                    [
                        'key' => 'bollinger_reversion',
                        'name' => 'Bollinger Reversion',
                        'desc' => 'Looks for overextension and mean reversion.',
                        'status' => 'Editable fields: Band Period, StdDev Multiplier.',
                    ],
                    [
                        'key' => 'vwap_reversion',
                        'name' => 'VWAP Reversion',
                        'desc' => 'Trades return to VWAP after distance threshold.',
                        'status' => 'Editable fields: VWAP Period, Min Distance (pips).',
                    ],
                ];
            @endphp

            <div class="bg-white p-6 rounded-lg shadow">
                <p class="text-sm text-gray-600">Active strategy mix from Bot Settings: <strong>{{ strtoupper(implode(', ', $activeStrategies)) }}</strong></p>
                <p class="text-xs text-gray-500 mt-1">All selected strategies must agree on trade direction before entry.</p>
            </div>

            <section class="bg-blue-50 border border-blue-100 p-5 rounded-lg space-y-2">
                <h3 class="text-base font-semibold text-blue-900">Why TradingView parameters can look different</h3>
                <ul class="list-disc list-inside text-sm text-blue-900 space-y-1">
                    <li>TradingView can use a different data feed and broker symbol, so candle values differ slightly.</li>
                    <li>This bot runs on MT5/MetaApi candles and symbol-specific pip sizes, so values can shift.</li>
                    <li>Indicator warmup length and update timing can differ between platforms.</li>
                    <li>Some indicators in TV are session-anchored by default, while this bot uses fixed rolling windows.</li>
                </ul>
                <p class="text-xs text-blue-800">Tip: keep the same timeframe, symbol, and candle source when comparing results.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-xs text-blue-900">
                        <thead>
                            <tr class="text-left border-b border-blue-200">
                                <th class="py-1 pr-4">Our Site</th>
                                <th class="py-1 pr-4">Typical TradingView Label</th>
                                <th class="py-1">Meaning</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b border-blue-100">
                                <td class="py-1 pr-4">sma_fast / sma_slow</td>
                                <td class="py-1 pr-4">Fast Length / Slow Length</td>
                                <td class="py-1">Moving-average lengths for crossover.</td>
                            </tr>
                            <tr class="border-b border-blue-100">
                                <td class="py-1 pr-4">ema_fast / ema_slow</td>
                                <td class="py-1 pr-4">Fast Length / Slow Length</td>
                                <td class="py-1">EMA lengths for crossover.</td>
                            </tr>
                            <tr class="border-b border-blue-100">
                                <td class="py-1 pr-4">bb_period / bb_stddev</td>
                                <td class="py-1 pr-4">Length / Mult</td>
                                <td class="py-1">Bollinger window and band width factor.</td>
                            </tr>
                            <tr>
                                <td class="py-1 pr-4">vwap_period / vwap_min_distance_pips</td>
                                <td class="py-1 pr-4">VWAP Length / Threshold</td>
                                <td class="py-1">Rolling VWAP window and trigger distance.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bg-white p-6 rounded-lg shadow space-y-4">
                <h3 class="text-lg font-semibold text-gray-900">Strategies</h3>
                <p class="text-sm text-gray-600">Edit each strategy in a popup. Save from popup updates only that strategy fields.</p>
                <div class="space-y-3">
                    @foreach ($strategyCards as $card)
                        <div class="border border-gray-200 rounded-lg p-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">{{ $card['name'] }}</p>
                                <p class="text-xs text-gray-600 mt-1">{{ $card['desc'] }}</p>
                                <p class="text-xs text-gray-500 mt-1">{{ $card['status'] }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                @if (in_array($card['key'], $activeStrategies, true))
                                    <span class="text-[11px] px-2 py-1 rounded bg-green-100 text-green-800">Active</span>
                                @else
                                    <span class="text-[11px] px-2 py-1 rounded bg-gray-100 text-gray-700">Inactive</span>
                                @endif
                                <button type="button" data-open-modal="modal-{{ $card['key'] }}" class="inline-flex items-center px-3 py-2 rounded border border-indigo-200 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">
                                    Edit
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <div id="modal-sma_cross" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" data-close-modal="modal-sma_cross"></div>
                <div class="relative bg-white rounded-lg shadow-xl w-full max-w-xl p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Edit SMA Cross</h3>
                        <button type="button" class="text-gray-500 hover:text-gray-700" data-close-modal="modal-sma_cross">Close</button>
                    </div>
                    <form method="POST" action="{{ route('strategies.update') }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <div>
                            <div class="flex items-center gap-2">
                                <label class="block text-sm font-medium text-gray-700">Fast Period</label>
                                <button type="button" class="text-xs text-indigo-600 border border-indigo-200 rounded px-2 py-0.5" data-info-target="info-sma-fast">Info</button>
                            </div>
                            <input name="bot_strategy_params[sma_fast]" type="number" min="2" max="200" value="{{ $params['sma_fast'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p id="info-sma-fast" class="hidden text-xs text-indigo-800 mt-1">Best start: 9-12 for intraday trend reaction. Lower = faster but more false entries.</p>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <label class="block text-sm font-medium text-gray-700">Slow Period</label>
                                <button type="button" class="text-xs text-indigo-600 border border-indigo-200 rounded px-2 py-0.5" data-info-target="info-sma-slow">Info</button>
                            </div>
                            <input name="bot_strategy_params[sma_slow]" type="number" min="3" max="300" value="{{ $params['sma_slow'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p id="info-sma-slow" class="hidden text-xs text-indigo-800 mt-1">Best start: 21-50 to smooth noise. Keep Slow > Fast to preserve crossover meaning.</p>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" data-close-modal="modal-sma_cross" class="px-3 py-2 text-xs border rounded border-gray-300">Cancel</button>
                            <button type="submit" class="px-3 py-2 text-xs rounded bg-indigo-600 text-white">Save SMA</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="modal-ema_cross" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" data-close-modal="modal-ema_cross"></div>
                <div class="relative bg-white rounded-lg shadow-xl w-full max-w-xl p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Edit EMA Cross</h3>
                        <button type="button" class="text-gray-500 hover:text-gray-700" data-close-modal="modal-ema_cross">Close</button>
                    </div>
                    <form method="POST" action="{{ route('strategies.update') }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <div>
                            <div class="flex items-center gap-2">
                                <label class="block text-sm font-medium text-gray-700">Fast Period</label>
                                <button type="button" class="text-xs text-indigo-600 border border-indigo-200 rounded px-2 py-0.5" data-info-target="info-ema-fast">Info</button>
                            </div>
                            <input name="bot_strategy_params[ema_fast]" type="number" min="2" max="200" value="{{ $params['ema_fast'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p id="info-ema-fast" class="hidden text-xs text-indigo-800 mt-1">Best start: 8-10 for quick trend detection. Too low can overtrade in chop.</p>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <label class="block text-sm font-medium text-gray-700">Slow Period</label>
                                <button type="button" class="text-xs text-indigo-600 border border-indigo-200 rounded px-2 py-0.5" data-info-target="info-ema-slow">Info</button>
                            </div>
                            <input name="bot_strategy_params[ema_slow]" type="number" min="3" max="300" value="{{ $params['ema_slow'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p id="info-ema-slow" class="hidden text-xs text-indigo-800 mt-1">Best start: 20-30 for stability. Wider gap vs Fast usually reduces whipsaws.</p>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" data-close-modal="modal-ema_cross" class="px-3 py-2 text-xs border rounded border-gray-300">Cancel</button>
                            <button type="submit" class="px-3 py-2 text-xs rounded bg-indigo-600 text-white">Save EMA</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="modal-bollinger_reversion" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" data-close-modal="modal-bollinger_reversion"></div>
                <div class="relative bg-white rounded-lg shadow-xl w-full max-w-xl p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Edit Bollinger Reversion</h3>
                        <button type="button" class="text-gray-500 hover:text-gray-700" data-close-modal="modal-bollinger_reversion">Close</button>
                    </div>
                    <form method="POST" action="{{ route('strategies.update') }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <div>
                            <div class="flex items-center gap-2">
                                <label class="block text-sm font-medium text-gray-700">Band Period</label>
                                <button type="button" class="text-xs text-indigo-600 border border-indigo-200 rounded px-2 py-0.5" data-info-target="info-bb-period">Info</button>
                            </div>
                            <input name="bot_strategy_params[bb_period]" type="number" min="5" max="300" value="{{ $params['bb_period'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p id="info-bb-period" class="hidden text-xs text-indigo-800 mt-1">Best start: 20. Lower reacts faster, higher is smoother and more selective.</p>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <label class="block text-sm font-medium text-gray-700">StdDev Multiplier</label>
                                <button type="button" class="text-xs text-indigo-600 border border-indigo-200 rounded px-2 py-0.5" data-info-target="info-bb-stddev">Info</button>
                            </div>
                            <input name="bot_strategy_params[bb_stddev]" type="number" step="0.1" min="0.5" max="5" value="{{ $params['bb_stddev'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p id="info-bb-stddev" class="hidden text-xs text-indigo-800 mt-1">Best start: 2.0. Higher gives fewer but stronger extremes; lower gives frequent but noisier entries.</p>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" data-close-modal="modal-bollinger_reversion" class="px-3 py-2 text-xs border rounded border-gray-300">Cancel</button>
                            <button type="submit" class="px-3 py-2 text-xs rounded bg-indigo-600 text-white">Save Bollinger</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="modal-vwap_reversion" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" data-close-modal="modal-vwap_reversion"></div>
                <div class="relative bg-white rounded-lg shadow-xl w-full max-w-xl p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Edit VWAP Reversion</h3>
                        <button type="button" class="text-gray-500 hover:text-gray-700" data-close-modal="modal-vwap_reversion">Close</button>
                    </div>
                    <form method="POST" action="{{ route('strategies.update') }}" class="space-y-4">
                        @csrf
                        @method('PUT')
                        <div>
                            <div class="flex items-center gap-2">
                                <label class="block text-sm font-medium text-gray-700">VWAP Period</label>
                                <button type="button" class="text-xs text-indigo-600 border border-indigo-200 rounded px-2 py-0.5" data-info-target="info-vwap-period">Info</button>
                            </div>
                            <input name="bot_strategy_params[vwap_period]" type="number" min="5" max="500" value="{{ $params['vwap_period'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p id="info-vwap-period" class="hidden text-xs text-indigo-800 mt-1">Best start: 30-60 for intraday mean levels. Higher reduces noise but reacts later.</p>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <label class="block text-sm font-medium text-gray-700">Min Distance (pips)</label>
                                <button type="button" class="text-xs text-indigo-600 border border-indigo-200 rounded px-2 py-0.5" data-info-target="info-vwap-distance">Info</button>
                            </div>
                            <input name="bot_strategy_params[vwap_min_distance_pips]" type="number" step="0.1" min="0.1" max="100" value="{{ $params['vwap_min_distance_pips'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" />
                            <p id="info-vwap-distance" class="hidden text-xs text-indigo-800 mt-1">Best start: 3-8 pips on majors. Higher threshold filters weak deviations.</p>
                        </div>
                        <div class="flex justify-end gap-2 pt-2">
                            <button type="button" data-close-modal="modal-vwap_reversion" class="px-3 py-2 text-xs border rounded border-gray-300">Cancel</button>
                            <button type="submit" class="px-3 py-2 text-xs rounded bg-indigo-600 text-white">Save VWAP</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="modal-momentum" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/40" data-close-modal="modal-momentum"></div>
                <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg p-6 space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-gray-900">Momentum Strategy</h3>
                        <button type="button" class="text-gray-500 hover:text-gray-700" data-close-modal="modal-momentum">Close</button>
                    </div>
                    <p class="text-sm text-gray-600">Momentum strategy uses Bot Settings value <strong>Min Move Pips</strong> as the threshold. It does not have separate strategy fields on this page.</p>
                    <p class="text-xs text-gray-500">Best start for Min Move Pips: 2.5-4.0 on major forex pairs, then adjust by spread and volatility.</p>
                    <div class="flex justify-end pt-2">
                        <button type="button" data-close-modal="modal-momentum" class="px-3 py-2 text-xs border rounded border-gray-300">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-open-modal]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-open-modal');
                const modal = document.getElementById(id);
                if (!modal) return;
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            });
        });

        document.querySelectorAll('[data-close-modal]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-close-modal');
                const modal = document.getElementById(id);
                if (!modal) return;
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            });
        });

        document.querySelectorAll('[data-info-target]').forEach((button) => {
            button.addEventListener('click', () => {
                const id = button.getAttribute('data-info-target');
                const panel = document.getElementById(id);
                if (!panel) return;
                panel.classList.toggle('hidden');
            });
        });
    </script>
</x-app-layout>
