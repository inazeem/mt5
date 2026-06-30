<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">EA Bridge</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
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

            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <h3 class="text-lg font-semibold text-gray-900">Connection</h3>
                <p class="text-sm text-gray-600">
                    Attach the <code>LaravelBridge</code> EA in MT5. It polls
                    <code>{{ url('/api/ea/poll') }}</code> every second with your account snapshot and executes queued commands.
                </p>
                <div>
                    <label class="block text-sm font-medium text-gray-700">API Token</label>
                    <input type="text" readonly value="{{ $token }}" class="mt-1 block w-full rounded border-gray-300 font-mono text-xs" onclick="this.select()" />
                </div>
                <form method="POST" action="{{ route('ea-bridge.token') }}" onsubmit="return confirm('Regenerate token? You must update MT5 EA inputs.');">
                    @csrf
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 text-white text-sm rounded hover:bg-gray-700">
                        Regenerate Token
                    </button>
                </form>
                <div class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded p-3 space-y-1">
                    <p><strong>MT5 setup:</strong> Tools → Options → Expert Advisors → allow WebRequest for your Laravel URL (e.g. <code>{{ parse_url(url('/'), PHP_URL_HOST) }}</code>).</p>
                    <p>Copy <code>mql5/Experts/LaravelBridge/LaravelBridge.mq5</code> into your MT5 <code>MQL5/Experts</code> folder and compile.</p>
                </div>
            </div>

            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <h3 class="text-lg font-semibold text-gray-900">Connected Terminals</h3>
                @if ($terminals->isEmpty())
                    <p class="text-sm text-gray-500">No EA has polled yet. Start LaravelBridge on a chart to register this account.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 border-b">
                                    <th class="py-2 pr-4">Login</th>
                                    <th class="py-2 pr-4">Server</th>
                                    <th class="py-2 pr-4">Balance</th>
                                    <th class="py-2 pr-4">Equity</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2">Last seen</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($terminals as $terminal)
                                    <tr class="border-b border-gray-100">
                                        <td class="py-2 pr-4 font-mono">{{ $terminal->account_login }}</td>
                                        <td class="py-2 pr-4">{{ $terminal->server ?? '—' }}</td>
                                        <td class="py-2 pr-4">{{ number_format((float) $terminal->balance, 2) }} {{ $terminal->currency }}</td>
                                        <td class="py-2 pr-4">{{ number_format((float) $terminal->equity, 2) }}</td>
                                        <td class="py-2 pr-4">
                                            @if ($terminal->isOnline())
                                                <span class="text-green-700">Online</span>
                                            @else
                                                <span class="text-gray-500">Offline</span>
                                            @endif
                                        </td>
                                        <td class="py-2">{{ $terminal->last_seen_at?->diffForHumans() ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <form method="POST" action="{{ route('ea-bridge.commands') }}" class="bg-white p-6 rounded-lg shadow space-y-4">
                @csrf
                <h3 class="text-lg font-semibold text-gray-900">Queue Command</h3>
                <p class="text-xs text-gray-500">SL and TP are in pips. The EA converts them to price before sending the order.</p>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Action</label>
                        <select name="action" class="mt-1 block w-full rounded border-gray-300" required>
                            <option value="BUY">BUY</option>
                            <option value="SELL">SELL</option>
                            <option value="CLOSE">CLOSE</option>
                            <option value="CLOSE_ALL">CLOSE_ALL</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Symbol</label>
                        <input name="symbol" type="text" value="{{ old('symbol', 'GBPUSD') }}" class="mt-1 block w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Lot</label>
                        <input name="lot" type="number" step="0.001" min="0.001" value="{{ old('lot', '0.10') }}" class="mt-1 block w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">SL (pips)</label>
                        <input name="sl" type="number" step="0.1" min="0" value="{{ old('sl', '20') }}" class="mt-1 block w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">TP (pips)</label>
                        <input name="tp" type="number" step="0.1" min="0" value="{{ old('tp', '40') }}" class="mt-1 block w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Account login (optional)</label>
                        <input name="account_login" type="number" min="1" value="{{ old('account_login') }}" class="mt-1 block w-full rounded border-gray-300" placeholder="All terminals" />
                    </div>
                </div>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-500">
                    Queue for EA
                </button>
            </form>

            <div class="bg-white p-6 rounded-lg shadow space-y-4">
                <h3 class="text-lg font-semibold text-gray-900">Recent Commands</h3>
                @if ($recentCommands->isEmpty())
                    <p class="text-sm text-gray-500">No commands queued yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 border-b">
                                    <th class="py-2 pr-4">#</th>
                                    <th class="py-2 pr-4">Action</th>
                                    <th class="py-2 pr-4">Symbol</th>
                                    <th class="py-2 pr-4">Lot</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2">Queued</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentCommands as $command)
                                    <tr class="border-b border-gray-100">
                                        <td class="py-2 pr-4">{{ $command->id }}</td>
                                        <td class="py-2 pr-4">{{ $command->action }}</td>
                                        <td class="py-2 pr-4">{{ $command->symbol ?? '—' }}</td>
                                        <td class="py-2 pr-4">{{ $command->lot ?? '—' }}</td>
                                        <td class="py-2 pr-4">{{ $command->status }}</td>
                                        <td class="py-2">{{ $command->queued_at?->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
