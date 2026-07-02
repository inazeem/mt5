<x-app-layout>
    <x-page-header
        title="Setup Guide"
        subtitle="Step-by-step instructions to connect MT5, configure bots, and go live safely."
    />

    <div class="mx-auto max-w-4xl space-y-6">
        <x-card title="Quick start checklist" x-data="{
            steps: Object.fromEntries(@js(['migrate','settings','instances','ea','tickers','profiles','test','scheduler']).map(k => [k, !!JSON.parse(localStorage.getItem('mt5_setup_checklist') || '{}')[k]])),
            save() { localStorage.setItem('mt5_setup_checklist', JSON.stringify(this.steps)); },
            completedLabel() { const keys = @js(['migrate','settings','instances','ea','tickers','profiles','test','scheduler']); return Object.values(this.steps).filter(Boolean).length + ' of ' + keys.length + ' steps completed'; }
        }">
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Tick steps as you complete them. Progress is saved in your browser.</p>
            <div class="space-y-3">
                @foreach ([
                    ['id' => 'migrate', 'title' => 'Run migrations on the server', 'body' => 'php artisan migrate on Herd or production.'],
                    ['id' => 'settings', 'title' => 'Configure Settings', 'href' => route('settings.edit'), 'body' => 'Set demo-only mode, AI keys, and optional MetaApi/Alpaca credentials.'],
                    ['id' => 'instances', 'title' => 'Create MT5 Instances', 'href' => route('ea-bridge.index'), 'body' => 'One row per MT5 install. Copy API token into LaravelBridge.'],
                    ['id' => 'ea', 'title' => 'Attach LaravelBridge in MT5', 'body' => 'Whitelist '.parse_url(url('/'), PHP_URL_HOST).' in WebRequest. Set InpServerUrl and InpApiToken.'],
                    ['id' => 'tickers', 'title' => 'Add tickers', 'href' => route('tickers.index'), 'body' => 'Define symbols the bot can scan and trade.'],
                    ['id' => 'profiles', 'title' => 'Create bot profiles', 'href' => route('bot-profiles.index'), 'body' => 'Strategy, risk, symbols, and MT5 instance mapping.'],
                    ['id' => 'test', 'title' => 'Run a test trade', 'href' => route('ea-bridge.index'), 'body' => 'Use Test Trade on an online instance to confirm the EA executes.'],
                    ['id' => 'scheduler', 'title' => 'Enable the scheduler', 'body' => 'Run mt5:auto-forex on a schedule (cron or task runner).'],
                ] as $index => $step)
                    <label class="flex gap-3 rounded-lg border border-slate-200 p-4 cursor-pointer hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50">
                        <input type="checkbox" class="mt-1 rounded border-slate-300" x-model="steps['{{ $step['id'] }}']" @change="save()">
                        <div>
                            <span class="font-medium text-slate-900 dark:text-white">{{ $index + 1 }}. {{ $step['title'] }}</span>
                            @if (! empty($step['href']))
                                <a href="{{ $step['href'] }}" class="ml-2 text-sm text-indigo-600 hover:underline dark:text-indigo-400">Open</a>
                            @endif
                            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">{{ $step['body'] }}</p>
                        </div>
                    </label>
                @endforeach
            </div>
            <p class="mt-4 text-xs text-slate-500" x-text="completedLabel()"></p>
        </x-card>

        <x-guide-panel title="How it works" :open="true">
            <p>Laravel orchestrates your bots. MT5 executes trades through one of these paths:</p>
            <ul>
                <li><strong>EA Bridge (default)</strong> — LaravelBridge EA polls <code>{{ $pollUrl }}</code> every second with a per-instance token. Laravel queues BUY/SELL commands; the EA executes on the matching terminal.</li>
                <li><strong>MetaApi</strong> — Cloud API using token + account ID from Settings. Choose per profile under Execution broker.</li>
                <li><strong>Alpaca</strong> — Crypto profiles only; uses Alpaca paper keys in Settings.</li>
            </ul>
            <p>Bot profiles map strategies and risk to symbols. The auto-forex command scans tickers, applies filters, and places trades through the selected broker.</p>
        </x-guide-panel>

        <x-card title="Current status">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-stat-card label="MT5 instances" :value="$instanceCount" :hint="$onlineCount.' online'" />
                <x-stat-card label="Bot profiles" :value="$profileCount" />
                <x-stat-card label="Demo-only mode" :value="$demoOnly ? 'On' : 'Off'" :hint="$demoOnly ? 'Live trades blocked' : 'Review risk settings'" />
                <x-stat-card label="MetaApi configured" :value="$hasMetaApi ? 'Yes' : 'No'" />
            </div>
        </x-card>

        <x-card title="Production (iiadigital)">
            <div class="guide-prose">
                <p>For <strong>https://mt5.iiadigital.co.uk</strong>:</p>
                <ul>
                    <li>Set <code>APP_URL</code> to your live domain and run <code>php artisan migrate</code>.</li>
                    <li>Create instances on the <strong>live</strong> site — tokens from Herd do not transfer.</li>
                    <li>Whitelist <code>https://mt5.iiadigital.co.uk</code> in MT5 WebRequest settings.</li>
                    <li>Use that URL as <code>InpServerUrl</code> in LaravelBridge.</li>
                </ul>
            </div>
        </x-card>
    </div>
</x-app-layout>
