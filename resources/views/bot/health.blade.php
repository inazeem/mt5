<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Bot Health</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold">Runtime Status</h3>
                        <p class="text-sm text-gray-500">Use this page to see why the bot can or cannot place trades right now.</p>
                    </div>
                    <div class="flex gap-2">
                        <a href="{{ route('bot.analytics') }}"
                           class="inline-flex items-center px-3 py-1.5 bg-gray-200 text-gray-700 text-xs font-semibold rounded hover:bg-gray-300">
                            Analytics
                        </a>
                        <a href="{{ route('bot.alerts') }}"
                           class="inline-flex items-center px-3 py-1.5 bg-gray-200 text-gray-700 text-xs font-semibold rounded hover:bg-gray-300">
                            Alerts
                        </a>
                    </div>
                </div>

                <form method="GET" action="{{ route('bot.health') }}" class="flex flex-wrap gap-3 items-end">
                    <div>
                        <label class="block text-xs text-gray-500 mb-1">Health symbol</label>
                        <input type="text" name="symbol" value="{{ $healthSymbol }}"
                               class="border border-gray-300 rounded px-2 py-1 text-sm w-32 focus:outline-none focus:ring focus:ring-indigo-200">
                    </div>
                    <div>
                        <button type="submit" class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">Refresh Checks</button>
                    </div>
                </form>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">Bot</div>
                        <div class="mt-1 text-sm font-semibold text-slate-900">{{ $runtime['bot_name'] }}</div>
                        <div class="text-xs text-slate-500">{{ $runtime['bot_key'] }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">UTC Hour</div>
                        <div class="mt-1 text-lg font-bold text-slate-900">{{ $runtime['current_hour_utc'] }}</div>
                    </div>
                    <div class="rounded-lg border {{ $runtime['in_session'] ? 'border-emerald-200 bg-emerald-50' : 'border-rose-200 bg-rose-50' }} p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide {{ $runtime['in_session'] ? 'text-emerald-700' : 'text-rose-700' }}">Session</div>
                        <div class="mt-1 text-sm font-semibold {{ $runtime['in_session'] ? 'text-emerald-900' : 'text-rose-900' }}">{{ $runtime['session_start_utc'] }}:00-{{ $runtime['session_end_utc'] }}:59 UTC</div>
                        <div class="text-xs {{ $runtime['in_session'] ? 'text-emerald-700' : 'text-rose-700' }}">{{ $runtime['in_session'] ? 'Inside session' : 'Outside session' }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">Filters</div>
                        <div class="mt-1 text-sm text-slate-900">Trend: {{ $runtime['trend_filter'] ? 'ON' : 'OFF' }}</div>
                        <div class="text-sm text-slate-900">AI: {{ $runtime['ai_confirm'] ? 'ON' : 'OFF' }}</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">Max Symbols</div>
                        <div class="mt-1 text-lg font-bold text-slate-900">{{ $runtime['max_symbols'] }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">Max Per Cycle</div>
                        <div class="mt-1 text-lg font-bold text-slate-900">{{ $runtime['max_per_cycle'] }}</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">Min Move</div>
                        <div class="mt-1 text-lg font-bold text-slate-900">{{ number_format((float) $runtime['min_move_pips'], 2) }} pip</div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-white p-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-700">Max Spread</div>
                        <div class="mt-1 text-lg font-bold text-slate-900">{{ number_format((float) $runtime['max_spread_pips'], 2) }} pip</div>
                    </div>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <h3 class="text-lg font-semibold">Live Checks</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-2 pr-3">Check</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2 pr-3">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($checks as $check)
                                <tr class="border-b border-gray-100 align-top">
                                    <td class="py-2 pr-3 font-medium text-slate-900">{{ $check['name'] }}</td>
                                    <td class="py-2 pr-3">
                                        <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-semibold {{ $check['ok'] ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' }}">
                                            {{ $check['ok'] ? 'OK' : 'FAIL' }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-3 whitespace-pre-wrap break-words text-slate-700">{{ $check['detail'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <h3 class="text-lg font-semibold">Latest Cycle Summary</h3>
                @if ($latestCycleSummary)
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-800 whitespace-pre-wrap break-words">{{ json_encode($latestCycleSummary->meta_payload, JSON_PRETTY_PRINT) }}</div>
                @else
                    <p class="text-sm text-gray-500">No cycle summary has been logged yet.</p>
                @endif
            </div>

            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <h3 class="text-lg font-semibold">Recent Blocking Issues</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-600 border-b">
                                <th class="py-2 pr-3">Date</th>
                                <th class="py-2 pr-3">Type</th>
                                <th class="py-2 pr-3">Status</th>
                                <th class="py-2 pr-3">Symbol</th>
                                <th class="py-2 pr-3">Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentIssues as $issue)
                                <tr class="border-b border-gray-100 align-top">
                                    <td class="py-2 pr-3 whitespace-nowrap">{{ $issue->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td class="py-2 pr-3">{{ $issue->event_type }}</td>
                                    <td class="py-2 pr-3">{{ $issue->status }}</td>
                                    <td class="py-2 pr-3">{{ $issue->symbol ?? '-' }}</td>
                                    <td class="py-2 pr-3 whitespace-pre-wrap break-words text-slate-700">{{ trim(implode("\n", array_filter([$issue->message, $issue->error_message]))) ?: '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-4 text-gray-500">No recent issues found.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>