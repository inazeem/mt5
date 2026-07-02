<x-app-layout>
    <x-page-header title="Dashboard" subtitle="Overview of your MT5 bot platform — status, quick actions, and recent activity.">
        <x-slot name="actions">
            <a href="{{ route('setup.index') }}" class="inline-flex rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">Setup Guide</a>
        </x-slot>
    </x-page-header>

    <div class="mx-auto max-w-6xl space-y-6">
        @if (session('status'))
            <x-alert type="success">{{ session('status') }}</x-alert>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-card label="Instances online" :value="$onlineCount.' / '.$instanceCount" hint="MT5 EA bridge terminals" />
            <x-stat-card label="Active profiles" :value="$enabledProfiles" hint="Enabled bot profiles" />
            <x-stat-card label="Demo-only" :value="$demoOnly ? 'Enabled' : 'Disabled'" :hint="$demoOnly ? 'Safer for testing' : 'Live trading possible'" />
            <x-stat-card label="Environment" :value="parse_url(config('app.url'), PHP_URL_HOST) ?? 'local'" />
        </div>

        <x-guide-panel title="New here? Start with the Setup Guide" :open="true">
            <p>Follow the checklist to connect MT5 via EA Bridge, add tickers, create bot profiles, and run your first test trade.</p>
            <p><a href="{{ route('setup.index') }}">Open Setup Guide →</a></p>
        </x-guide-panel>

        <div>
            <h2 class="mb-4 text-lg font-semibold text-slate-900 dark:text-white">Quick actions</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['MT5 Instances', 'Connect LaravelBridge and manage tokens', route('ea-bridge.index'), 'indigo'],
                    ['Bot Console', 'Manual trades and auto-bot settings', route('bot.index'), 'emerald'],
                    ['Bot Profiles', 'Strategy, risk, and instance mapping', route('bot-profiles.index'), 'violet'],
                    ['Analytics', 'Performance and trade history', route('bot.analytics'), 'sky'],
                    ['Settings', 'MetaApi, Alpaca, AI, demo mode', route('settings.edit'), 'slate'],
                    ['Tickers', 'Symbol universe for scanning', route('tickers.index'), 'amber'],
                ] as $action)
                    <a href="{{ $action[2] }}" class="group rounded-xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-indigo-300 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-indigo-700">
                        <h3 class="font-semibold text-slate-900 group-hover:text-indigo-600 dark:text-white dark:group-hover:text-indigo-400">{{ $action[0] }}</h3>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $action[1] }}</p>
                    </a>
                @endforeach
            </div>
        </div>

        <x-card title="Recent activity">
            @if ($recentAlerts->isEmpty())
                <x-empty-state title="No recent activity" action-label="Open Bot" :action-href="route('bot.index')">
                    Trade and guardrail events will appear here.
                </x-empty-state>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-slate-500 dark:border-slate-800">
                                <th class="py-2 pr-4">Event</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Symbol</th>
                                <th class="py-2">When</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recentAlerts as $log)
                                <tr class="border-b border-slate-100 dark:border-slate-800">
                                    <td class="py-2 pr-4">{{ $log->event_type }}</td>
                                    <td class="py-2 pr-4"><x-badge variant="{{ $log->status === 'success' ? 'success' : 'default' }}">{{ $log->status ?? '—' }}</x-badge></td>
                                    <td class="py-2 pr-4">{{ $log->symbol ?? '—' }}</td>
                                    <td class="py-2">{{ $log->created_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
