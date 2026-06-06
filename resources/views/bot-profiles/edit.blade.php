<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Edit Bot Profile: {{ $profile['name'] ?? 'Bot' }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
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

                <form method="POST" action="{{ route('bot-profiles.update', $profile['key']) }}" class="space-y-6">
                    @csrf
                    @method('PUT')
                    @php
                        $selectedSignalTimeframes = old('signal_timeframes');
                        if (!is_array($selectedSignalTimeframes)) {
                            $selectedSignalTimeframes = isset($profile['signal_timeframes']) && is_array($profile['signal_timeframes'])
                                ? $profile['signal_timeframes']
                                : [];
                        }
                        if (empty($selectedSignalTimeframes) && !empty($profile['signal_timeframe'])) {
                            $selectedSignalTimeframes = [(string) $profile['signal_timeframe']];
                        }
                        $selectedStrategies = old('strategies');
                        if (!is_array($selectedStrategies)) {
                            $selectedStrategies = isset($profile['strategies']) && is_array($profile['strategies'])
                                ? $profile['strategies']
                                : (isset($profile['strategy']) && $profile['strategy'] !== '' ? [(string) $profile['strategy']] : []);
                        }
                        $strategyParams = old('strategy_params', isset($profile['strategy_params']) && is_array($profile['strategy_params']) ? $profile['strategy_params'] : []);
                    @endphp

                    <div class="p-3 bg-gray-50 rounded text-xs text-gray-600 font-mono">
                        Bot Key: <strong>{{ $profile['key'] }}</strong>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Bot Name</label>
                        <input type="text" name="name" required maxlength="120"
                               value="{{ old('name', $profile['name'] ?? '') }}" placeholder="e.g. Scalper Bot 1"
                               class="mt-1 block w-full rounded border-gray-300" />
                        @error('name')
                            <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex items-center gap-2">
                        <input type="checkbox" id="enabled" name="enabled" value="1" {{ old('enabled', (bool) ($profile['enabled'] ?? true)) ? 'checked' : '' }} class="rounded border-gray-300" />
                        <label for="enabled" class="text-sm text-gray-700">Enabled</label>
                        <p class="text-xs text-gray-500">Disabled profiles are skipped during auto-forex runs.</p>
                    </div>

                    <hr class="border-gray-200" />

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Lot Size</label>
                            <input type="number" name="lot" step="0.001" min="0.001"
                                   value="{{ old('lot', isset($profile['lot']) ? $profile['lot'] : '') }}" placeholder="e.g. 0.01"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            @error('lot')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">TP Pips</label>
                            <input type="number" name="tp_pips" step="0.1" min="0.1"
                                   value="{{ old('tp_pips', isset($profile['tp_pips']) ? $profile['tp_pips'] : '') }}" placeholder="e.g. 25"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            @error('tp_pips')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">SL Pips</label>
                            <input type="number" name="sl_pips" step="0.1" min="0.1"
                                   value="{{ old('sl_pips', isset($profile['sl_pips']) ? $profile['sl_pips'] : '') }}" placeholder="e.g. 15"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            @error('sl_pips')
                                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Trail Start Pips</label>
                            <input type="number" name="trail_start_pips" step="0.1" min="0.1"
                                   value="{{ old('trail_start_pips', isset($profile['trail_start_pips']) ? $profile['trail_start_pips'] : '') }}" placeholder="e.g. 10"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Trail Pips</label>
                            <input type="number" name="trail_pips" step="0.1" min="0.1"
                                   value="{{ old('trail_pips', isset($profile['trail_pips']) ? $profile['trail_pips'] : '') }}" placeholder="e.g. 8"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Trail TP Multiplier</label>
                            <input type="number" name="trail_tp_multiplier" step="0.1" min="1" max="10"
                                   value="{{ old('trail_tp_multiplier', isset($profile['trail_tp_multiplier']) ? $profile['trail_tp_multiplier'] : '') }}" placeholder="e.g. 2"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Move Pips</label>
                            <input type="number" name="min_move_pips" step="0.1" min="0.1"
                                   value="{{ old('min_move_pips', isset($profile['min_move_pips']) ? $profile['min_move_pips'] : '') }}" placeholder="e.g. 3"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Spread Pips</label>
                            <input type="number" name="max_spread_pips" step="0.1" min="0.1"
                                   value="{{ old('max_spread_pips', isset($profile['max_spread_pips']) ? $profile['max_spread_pips'] : '') }}" placeholder="e.g. 2.5"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Cooldown (minutes)</label>
                            <input type="number" name="cooldown_minutes" min="0"
                                   value="{{ old('cooldown_minutes', isset($profile['cooldown_minutes']) ? $profile['cooldown_minutes'] : '') }}" placeholder="e.g. 30"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div class="sm:col-span-3">
                            <label class="block text-sm font-medium text-gray-700">Strategies Override (optional, must all align)</label>
                            <div class="mt-2 flex flex-wrap gap-4">
                                @foreach (['momentum' => 'Momentum', 'sma_cross' => 'SMA Cross', 'ema_cross' => 'EMA Cross', 'bollinger_reversion' => 'Bollinger Reversion', 'vwap_reversion' => 'VWAP Reversion'] as $strategyValue => $strategyLabel)
                                    <label class="inline-flex items-center gap-2">
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
                            <label class="block text-sm font-medium text-gray-700">Trend Timeframes (must all align)</label>
                            <div class="mt-2 flex flex-wrap gap-4">
                                @foreach (['5m', '15m', '30m', '1h', '4h'] as $timeframe)
                                    <label class="inline-flex items-center gap-2">
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
                            <label class="block text-sm font-medium text-gray-700">Session Start (UTC)</label>
                            <input type="number" name="session_start_utc" min="0" max="23"
                                   value="{{ old('session_start_utc', isset($profile['session_start_utc']) ? $profile['session_start_utc'] : '') }}" placeholder="e.g. 6"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Session End (UTC)</label>
                            <input type="number" name="session_end_utc" min="0" max="23"
                                   value="{{ old('session_end_utc', isset($profile['session_end_utc']) ? $profile['session_end_utc'] : '') }}" placeholder="e.g. 20"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Trades / Day</label>
                            <input type="number" name="max_trades_per_day" min="1"
                                   value="{{ old('max_trades_per_day', isset($profile['max_trades_per_day']) ? $profile['max_trades_per_day'] : '') }}" placeholder="e.g. 20"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Daily Loss %</label>
                            <input type="number" name="max_daily_loss_percent" step="0.1" min="0.1"
                                   value="{{ old('max_daily_loss_percent', isset($profile['max_daily_loss_percent']) ? $profile['max_daily_loss_percent'] : '') }}" placeholder="e.g. 2"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Symbols / Cycle</label>
                            <input type="number" name="max_symbols" min="1"
                                   value="{{ old('max_symbols', isset($profile['max_symbols']) ? $profile['max_symbols'] : '') }}" placeholder="e.g. 200"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">AI Min Confidence %</label>
                            <input type="number" name="ai_min_confidence" min="0" max="100"
                                   value="{{ old('ai_min_confidence', isset($profile['ai_min_confidence']) ? $profile['ai_min_confidence'] : '') }}" placeholder="e.g. 70"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Bot Score %</label>
                            <input type="number" name="min_bot_score" min="0" max="100"
                                   value="{{ old('min_bot_score', isset($profile['min_bot_score']) ? $profile['min_bot_score'] : '') }}" placeholder="e.g. 70"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Open Positions</label>
                            <input type="number" name="max_open_positions" min="1"
                                   value="{{ old('max_open_positions', isset($profile['max_open_positions']) ? $profile['max_open_positions'] : '') }}" placeholder="e.g. 10"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Max Per Cycle</label>
                            <input type="number" name="max_per_cycle" min="1"
                                   value="{{ old('max_per_cycle', isset($profile['max_per_cycle']) ? $profile['max_per_cycle'] : '') }}" placeholder="e.g. 5"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Min Effective Volume</label>
                            <input type="number" name="min_effective_volume" step="0.001" min="0.001"
                                   value="{{ old('min_effective_volume', isset($profile['min_effective_volume']) ? $profile['min_effective_volume'] : '') }}" placeholder="e.g. 0.01"
                                   class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" id="ai_confirm" name="ai_confirm" value="1" {{ old('ai_confirm', (bool) ($profile['ai_confirm'] ?? true)) ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm text-gray-700">Require AI confirmation before trades</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" id="scalper" name="scalper" value="1" {{ old('scalper', (bool) ($profile['scalper'] ?? false)) ? 'checked' : '' }} class="rounded border-gray-300" />
                            <span class="text-sm text-gray-700">Enable scalper mode</span>
                        </label>
                    </div>

                    <div class="rounded border border-gray-200 p-4">
                        <h4 class="text-sm font-semibold text-gray-800">Strategy Parameters (Profile Override)</h4>
                        <p class="text-xs text-gray-500 mt-1">Leave blank to use global strategy parameters.</p>
                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-4 gap-3">
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
                        @php
                            $symbolsText = old('symbols');
                            if ($symbolsText === null && isset($profile['symbols']) && is_array($profile['symbols'])) {
                                $symbolsText = implode(', ', $profile['symbols']);
                            }
                        @endphp
                        <textarea name="symbols" rows="2" placeholder="e.g. EURUSD, GBPUSD, USDJPY"
                                  class="mt-1 block w-full rounded border-gray-300">{{ $symbolsText }}</textarea>
                        <p class="text-xs text-gray-500 mt-1">Leave blank to use all available symbols.</p>
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Preferred Hours UTC (comma-separated)</label>
                            @php
                                $preferredHoursText = old('preferred_hours_utc');
                                if ($preferredHoursText === null && isset($profile['preferred_hours_utc']) && is_array($profile['preferred_hours_utc'])) {
                                    $preferredHoursText = implode(', ', $profile['preferred_hours_utc']);
                                }
                            @endphp
                            <input type="text" name="preferred_hours_utc"
                                   value="{{ $preferredHoursText }}" placeholder="e.g. 8,14,17"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Optional. If set, entries run only during these UTC hours.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Blocked Hours UTC (comma-separated)</label>
                            @php
                                $blockedHoursText = old('blocked_hours_utc');
                                if ($blockedHoursText === null && isset($profile['blocked_hours_utc']) && is_array($profile['blocked_hours_utc'])) {
                                    $blockedHoursText = implode(', ', $profile['blocked_hours_utc']);
                                }
                                if ($blockedHoursText === null || trim($blockedHoursText) === '') {
                                    $blockedHoursText = '15';
                                }
                            @endphp
                            <input type="text" name="blocked_hours_utc"
                                   value="{{ $blockedHoursText }}" placeholder="e.g. 15"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Default blocks 15:00 UTC due to recent underperformance.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">Preferred Symbols (comma-separated)</label>
                            @php
                                $preferredSymbolsText = old('preferred_symbols');
                                if ($preferredSymbolsText === null && isset($profile['preferred_symbols']) && is_array($profile['preferred_symbols'])) {
                                    $preferredSymbolsText = implode(', ', $profile['preferred_symbols']);
                                }
                            @endphp
                            <input type="text" name="preferred_symbols"
                                   value="{{ $preferredSymbolsText }}" placeholder="e.g. USDJPY_SB,EURUSD_SB"
                                   class="mt-1 block w-full rounded border-gray-300" />
                            <p class="text-xs text-gray-500 mt-1">Optional entry whitelist applied after symbol discovery.</p>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">
                            Save Changes
                        </button>
                        <a href="{{ route('bot-profiles.index') }}"
                           class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 text-xs font-semibold rounded hover:bg-gray-300">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
