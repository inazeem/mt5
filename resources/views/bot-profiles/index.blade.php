<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Bot Profiles</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="bg-green-100 border border-green-200 text-green-800 p-4 rounded">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold">Manage Bot Profiles</h3>
                    <a href="{{ route('bot-profiles.create') }}"
                       class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">
                        + New Bot Profile
                    </a>
                </div>

                @if (empty($profiles))
                    <div class="text-center py-8 text-gray-500">
                        <p>No bot profiles configured yet.</p>
                        <a href="{{ route('bot-profiles.create') }}" class="text-indigo-600 hover:underline">Create your first bot profile</a>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-600 border-b bg-gray-50">
                                    <th class="py-3 px-4">Key</th>
                                    <th class="py-3 px-4">Name</th>
                                    <th class="py-3 px-4">Status</th>
                                    <th class="py-3 px-4">Strategy</th>
                                    <th class="py-3 px-4">Timeframe</th>
                                    <th class="py-3 px-4">Lot</th>
                                    <th class="py-3 px-4">TP/SL (pips)</th>
                                    <th class="py-3 px-4">Session (UTC)</th>
                                    <th class="py-3 px-4">Symbols</th>
                                    <th class="py-3 px-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($profiles as $profile)
                                    @php
                                        $status = (bool) ($profile['enabled'] ?? true) ? 'Enabled' : 'Disabled';
                                        $statusClass = $status === 'Enabled' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700';
                                    @endphp
                                    <tr class="border-b border-gray-100 hover:bg-gray-50">
                                        <td class="py-3 px-4 font-mono text-xs">{{ $profile['key'] ?? 'N/A' }}</td>
                                        <td class="py-3 px-4">{{ $profile['name'] ?? 'Unnamed' }}</td>
                                        <td class="py-3 px-4">
                                            <span class="inline-flex items-center rounded px-2.5 py-0.5 text-xs font-semibold {{ $statusClass }}">
                                                {{ $status }}
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-xs">
                                            @php
                                                $profileStrategies = isset($profile['strategies']) && is_array($profile['strategies'])
                                                    ? array_values($profile['strategies'])
                                                    : [];
                                                if (empty($profileStrategies) && !empty($profile['strategy'])) {
                                                    $profileStrategies = [(string) $profile['strategy']];
                                                }
                                            @endphp
                                            @if (!empty($profileStrategies))
                                                {{ strtoupper(implode(',', $profileStrategies)) }}
                                            @else
                                                <span class="text-gray-400">DEFAULT</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 text-xs">
                                            @php
                                                $trendTimeframes = isset($profile['signal_timeframes']) && is_array($profile['signal_timeframes'])
                                                    ? array_values($profile['signal_timeframes'])
                                                    : [];
                                                if (empty($trendTimeframes) && !empty($profile['signal_timeframe'])) {
                                                    $trendTimeframes = [(string) $profile['signal_timeframe']];
                                                }
                                            @endphp
                                            @if (!empty($trendTimeframes))
                                                {{ strtoupper(implode(',', $trendTimeframes)) }}
                                                @php
                                                    $entryTimeframe = isset($profile['entry_timeframe']) ? strtolower(trim((string) $profile['entry_timeframe'])) : '';
                                                    if ($entryTimeframe === '' || !in_array($entryTimeframe, $trendTimeframes, true)) {
                                                        $entryTimeframe = $trendTimeframes[0] ?? '';
                                                    }
                                                @endphp
                                                @if ($entryTimeframe !== '')
                                                    <div class="text-[10px] text-gray-500 mt-1">Entry: {{ strtoupper($entryTimeframe) }}</div>
                                                @endif
                                            @else
                                                <span class="text-gray-400">DEFAULT</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4">{{ isset($profile['lot']) ? number_format($profile['lot'], 4) : '—' }}</td>
                                        <td class="py-3 px-4">
                                            @php
                                                $tp = isset($profile['tp_pips']) ? $profile['tp_pips'] : null;
                                                $sl = isset($profile['sl_pips']) ? $profile['sl_pips'] : null;
                                            @endphp
                                            {{ $tp !== null ? number_format($tp, 1) : '—' }} / {{ $sl !== null ? number_format($sl, 1) : '—' }}
                                        </td>
                                        <td class="py-3 px-4 text-xs">
                                            @php
                                                $start = $profile['session_start_utc'] ?? null;
                                                $end = $profile['session_end_utc'] ?? null;
                                            @endphp
                                            {{ $start !== null ? $start : '—' }}:00 — {{ $end !== null ? $end : '—' }}:59
                                        </td>
                                        <td class="py-3 px-4 text-xs">
                                            @php
                                                $symbols = $profile['symbols'] ?? [];
                                            @endphp
                                            @if (!empty($symbols))
                                                <span class="inline-flex items-center rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-700">
                                                    {{ count($symbols) }} symbol(s)
                                                </span>
                                            @else
                                                <span class="text-gray-400">All</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 space-x-2">
                                            <a href="{{ route('bot-profiles.edit', $profile['key']) }}"
                                               class="text-indigo-600 hover:text-indigo-900 font-semibold text-xs">
                                                Edit
                                            </a>
                                            <form method="POST" action="{{ route('bot-profiles.destroy', $profile['key']) }}" style="display: inline;"
                                                  onsubmit="return confirm('Delete this bot profile? This cannot be undone.');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-rose-600 hover:text-rose-900 font-semibold text-xs">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="mt-6 p-4 bg-blue-50 border border-blue-200 rounded">
                    <p class="text-xs text-blue-800">
                        <strong>Tip:</strong> Leave parameter fields blank to use the default values from Auto-Bot Parameters in Settings.
                        Settings values are used as fallback if a profile parameter is not specified.
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
