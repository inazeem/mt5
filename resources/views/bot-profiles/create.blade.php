<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Create Bot Profile</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white p-6 rounded-lg shadow">
                @if ($errors->any())
                    <div class="mb-4 p-4 bg-rose-50 border border-rose-200 rounded">
                        <h4 class="text-sm font-semibold text-rose-800 mb-2">Validation Errors</h4>
                        <ul class="list-disc list-inside text-sm text-rose-700 space-y-1">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('bot-profiles.store') }}" class="space-y-6">
                    @csrf
                    @php
                        $selectedSignalTimeframes = old('signal_timeframes');
                        if (!is_array($selectedSignalTimeframes)) {
                            $selectedSignalTimeframes = [];
                        }
                        $selectedEntryTimeframe = old('entry_timeframe');
                        if (!is_string($selectedEntryTimeframe) || $selectedEntryTimeframe === '') {
                            $selectedEntryTimeframe = $selectedSignalTimeframes[0] ?? '';
                        }
                        if ($selectedEntryTimeframe !== '' && !in_array($selectedEntryTimeframe, $selectedSignalTimeframes, true)) {
                            $selectedEntryTimeframe = $selectedSignalTimeframes[0] ?? '';
                        }
                        $selectedStrategies = old('strategies');
                        if (!is_array($selectedStrategies)) {
                            $selectedStrategies = old('strategy') ? [old('strategy')] : [];
                        }
                        $strategyParams = old('strategy_params', []);
                    @endphp

                    <div class="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3">
                        <p class="text-sm font-semibold text-indigo-900">Create a profile with clean defaults</p>
                        <p class="text-xs text-indigo-700 mt-1">Blank fields inherit global Auto-Bot settings. Use profile values only where this bot should differ.</p>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-4 space-y-4">
                        <h3 class="text-sm font-semibold text-gray-900">Profile Identity</h3>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Bot Key (unique identifier)</label>
                            <input type="text" name="key" required pattern="^[a-zA-Z0-9_-]+$"
                                   value="{{ old('key') }}" placeholder="e.g. scalp-1, trend-bot"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Letters, numbers, dashes, underscores only.</p>
                            @error('key')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Bot Name</label>
                            <input type="text" name="name" required maxlength="120"
                                   value="{{ old('name') }}" placeholder="e.g. Scalper Bot 1"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            @error('name')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="enabled" name="enabled" value="1" {{ old('enabled', true) ? 'checked' : '' }} class="rounded border-gray-300" />
                        <label for="enabled" class="text-sm text-gray-700">Enabled</label>
                        <p class="text-xs text-gray-500">Disabled profiles are skipped during auto-forex runs.</p>
                    </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 p-4">
                        <h3 class="text-sm font-semibold text-gray-900 mb-4">Execution, Strategy, and Risk</h3>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Lot Size</label>
                            <input type="number" name="lot" step="0.001" min="0.001"
                                   value="{{ old('lot') }}" placeholder="e.g. 0.01"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            @error('lot')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">TP Pips</label>
                            <input type="number" name="tp_pips" step="0.1" min="0.1"
                                   value="{{ old('tp_pips') }}" placeholder="e.g. 25"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            @error('tp_pips')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">SL Pips</label>
                            <input type="number" name="sl_pips" step="0.1" min="0.1"
                                   value="{{ old('sl_pips') }}" placeholder="e.g. 15"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            @error('sl_pips')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Trail Start Pips</label>
                            <input type="number" name="trail_start_pips" step="0.1" min="0.1"
                                   value="{{ old('trail_start_pips') }}" placeholder="e.g. 10"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Trail Pips</label>
                            <input type="number" name="trail_pips" step="0.1" min="0.1"
                                   value="{{ old('trail_pips') }}" placeholder="e.g. 8"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Trail TP Multiplier</label>
                            <input type="number" name="trail_tp_multiplier" step="0.1" min="1" max="10"
                                   value="{{ old('trail_tp_multiplier') }}" placeholder="e.g. 2"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Move Pips</label>
                            <input type="number" name="min_move_pips" step="0.1" min="0.1"
                                   value="{{ old('min_move_pips') }}" placeholder="e.g. 3"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Spread Pips</label>
                            <input type="number" name="max_spread_pips" step="0.1" min="0.1"
                                   value="{{ old('max_spread_pips') }}" placeholder="e.g. 2.5"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Cooldown (minutes)</label>
                            <input type="number" name="cooldown_minutes" min="0"
                                   value="{{ old('cooldown_minutes') }}" placeholder="e.g. 30"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Session Start (UTC)</label>
                            <input type="number" name="session_start_utc" min="0" max="23"
                                   value="{{ old('session_start_utc') }}" placeholder="e.g. 6"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Session End (UTC)</label>
                            <input type="number" name="session_end_utc" min="0" max="23"
                                   value="{{ old('session_end_utc') }}" placeholder="e.g. 20"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Trades / Day</label>
                            <input type="number" name="max_trades_per_day" min="1"
                                   value="{{ old('max_trades_per_day') }}" placeholder="e.g. 20"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Daily Loss %</label>
                            <input type="number" name="max_daily_loss_percent" step="0.1" min="0.1"
                                   value="{{ old('max_daily_loss_percent') }}" placeholder="e.g. 2"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Symbols / Cycle</label>
                            <input type="number" name="max_symbols" min="1"
                                   value="{{ old('max_symbols') }}" placeholder="e.g. 200"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">AI Min Confidence %</label>
                            <input type="number" name="ai_min_confidence" min="0" max="100"
                                   value="{{ old('ai_min_confidence') }}" placeholder="e.g. 70"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Bot Score %</label>
                            <input type="number" name="min_bot_score" min="0" max="100"
                                   value="{{ old('min_bot_score') }}" placeholder="e.g. 70"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Open Positions</label>
                            <input type="number" name="max_open_positions" min="1"
                                   value="{{ old('max_open_positions') }}" placeholder="e.g. 10"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Per Cycle</label>
                            <input type="number" name="max_per_cycle" min="1"
                                   value="{{ old('max_per_cycle') }}" placeholder="e.g. 5"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Effective Volume</label>
                            <input type="number" name="min_effective_volume" step="0.001" min="0.001"
                                   value="{{ old('min_effective_volume') }}" placeholder="e.g. 0.01"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                    </div>
                    </div>

                    <div class="space-y-2">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" id="ai_confirm" name="ai_confirm" value="1" {{ old('ai_confirm', true) ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm text-gray-700">Require AI confirmation before trades</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" id="scalper" name="scalper" value="1" {{ old('scalper') ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm text-gray-700">Enable scalper mode</span>
                        </label>
                    </div>

                    <div class="rounded border border-gray-200 p-4">
                        <h4 class="text-sm font-semibold text-gray-800">Strategy Parameters (Profile Override)</h4>
                        <p class="text-xs text-gray-500 mt-1">Leave blank to use global strategy parameters.</p>
                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div><label class="block text-xs font-medium text-gray-700">SMA Fast</label><input name="strategy_params[sma_fast]" type="number" min="2" max="200" value="{{ $strategyParams['sma_fast'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" /></div>
                            <div><label class="block text-xs font-medium text-gray-700">SMA Slow</label><input name="strategy_params[sma_slow]" type="number" min="3" max="300" value="{{ $strategyParams['sma_slow'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" /></div>
                            <div><label class="block text-xs font-medium text-gray-700">EMA Fast</label><input name="strategy_params[ema_fast]" type="number" min="2" max="200" value="{{ $strategyParams['ema_fast'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" /></div>
                            <div><label class="block text-xs font-medium text-gray-700">EMA Slow</label><input name="strategy_params[ema_slow]" type="number" min="3" max="300" value="{{ $strategyParams['ema_slow'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" /></div>
                            <div><label class="block text-xs font-medium text-gray-700">Bollinger Period</label><input name="strategy_params[bb_period]" type="number" min="5" max="300" value="{{ $strategyParams['bb_period'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" /></div>
                            <div><label class="block text-xs font-medium text-gray-700">Bollinger StdDev</label><input name="strategy_params[bb_stddev]" type="number" step="0.1" min="0.5" max="5" value="{{ $strategyParams['bb_stddev'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" /></div>
                            <div><label class="block text-xs font-medium text-gray-700">VWAP Period</label><input name="strategy_params[vwap_period]" type="number" min="5" max="500" value="{{ $strategyParams['vwap_period'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" /></div>
                            <div><label class="block text-xs font-medium text-gray-700">VWAP Min Distance (pips)</label><input name="strategy_params[vwap_min_distance_pips]" type="number" step="0.1" min="0.1" max="100" value="{{ $strategyParams['vwap_min_distance_pips'] ?? '' }}" class="mt-1 block w-full rounded border-gray-300" /></div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Symbols (comma-separated)</label>
                        <textarea name="symbols" rows="2" placeholder="e.g. EURUSD, GBPUSD, USDJPY"
                                  class="mt-1 block w-full rounded border-gray-300">{{ old('symbols') }}</textarea>
                        <p class="text-xs text-gray-500 mt-1">Leave blank to use all available symbols.</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Preferred Hours UTC (comma-separated)</label>
                            <input type="text" name="preferred_hours_utc"
                                   value="{{ old('preferred_hours_utc') }}" placeholder="e.g. 8,14,17"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Optional. If set, entries run only during these UTC hours.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Blocked Hours UTC (comma-separated)</label>
                            <input type="text" name="blocked_hours_utc"
                                   value="{{ old('blocked_hours_utc', '15') }}" placeholder="e.g. 15"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Default blocks 15:00 UTC due to recent underperformance.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Preferred Symbols (comma-separated)</label>
                            <input type="text" name="preferred_symbols"
                                   value="{{ old('preferred_symbols') }}" placeholder="e.g. USDJPY_SB,EURUSD_SB"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Optional entry whitelist applied after symbol discovery.</p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-4 space-y-4">
                        <h3 class="text-sm font-semibold text-indigo-900">Category and Signal Filters</h3>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div class="sm:col-span-3">
                                <label class="block text-sm font-medium text-indigo-900">Ticker Categories (optional)</label>
                                <p class="text-xs text-indigo-700 mt-1">If selected, this bot will scan only tickers in these categories.</p>
                                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                                    @foreach (['Forex', 'Stock', 'Commodity', 'Index', 'Crypto', 'Other'] as $tickerCategory)
                                        <label class="inline-flex items-center gap-2 rounded border border-indigo-100 bg-white px-3 py-2">
                                            <input type="checkbox" name="ticker_categories[]" value="{{ $tickerCategory }}"
                                                   {{ in_array($tickerCategory, old('ticker_categories', []), true) ? 'checked' : '' }}
                                                   class="rounded border-gray-300" />
                                            <span class="text-sm text-gray-700">{{ $tickerCategory }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div class="sm:col-span-3">
                                <label class="block text-sm font-medium text-indigo-900">Strategies Override (optional, must all align)</label>
                                <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                    @foreach (['momentum' => 'Momentum', 'sma_cross' => 'SMA Cross', 'ema_cross' => 'EMA Cross', 'bollinger_reversion' => 'Bollinger Reversion', 'vwap_reversion' => 'VWAP Reversion'] as $strategyValue => $strategyLabel)
                                        <label class="inline-flex items-center gap-2 rounded border border-indigo-100 bg-white px-3 py-2">
                                            <input type="checkbox" name="strategies[]" value="{{ $strategyValue }}"
                                                   {{ in_array($strategyValue, $selectedStrategies, true) ? 'checked' : '' }}
                                                   class="rounded border-gray-300" />
                                            <span class="text-sm text-gray-700">{{ $strategyLabel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Leave all unchecked to use global strategy mix.</p>
                                @error('strategies')
                                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                @enderror
                                @error('strategies.*')
                                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-indigo-900">Trend Timeframes (must all align)</label>
                                <div class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                                    @foreach (['5m', '15m', '30m', '1h', '4h'] as $timeframe)
                                        <label class="inline-flex items-center gap-2 rounded border border-indigo-100 bg-white px-3 py-2">
                                            <input type="checkbox" name="signal_timeframes[]" value="{{ $timeframe }}"
                                                   {{ in_array($timeframe, $selectedSignalTimeframes, true) ? 'checked' : '' }}
                                                   class="rounded border-gray-300" />
                                            <span class="text-sm text-gray-700">{{ strtoupper($timeframe) }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p class="text-xs text-gray-500 mt-1">Leave all unchecked to use global Auto-Bot timeframe defaults.</p>
                                @error('signal_timeframes')
                                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                @enderror
                                @error('signal_timeframes.*')
                                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-indigo-900">Entry Timeframe (final trigger)</label>
                                <select name="entry_timeframe" class="mt-1 block w-full rounded border-gray-300">
                                    <option value="">Auto (lowest selected)</option>
                                    @foreach ($selectedSignalTimeframes as $timeframe)
                                        <option value="{{ $timeframe }}" {{ $selectedEntryTimeframe === $timeframe ? 'selected' : '' }}>{{ strtoupper($timeframe) }}</option>
                                    @endforeach
                                </select>
                                <p class="text-xs text-gray-500 mt-1">Must be one of selected trend timeframes.</p>
                                @error('entry_timeframe')
                                    <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 border-t border-gray-200 pt-4">
                        <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700 shadow-sm">
                            Create Bot Profile
                        </button>
                        <a href="{{ route('bot-profiles.index') }}"
                           class="inline-flex items-center px-5 py-2.5 bg-gray-100 text-gray-700 text-xs font-semibold rounded hover:bg-gray-200 border border-gray-300">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
