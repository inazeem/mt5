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
                <h3 class="text-lg font-semibold text-gray-900">MT5 Instance Profiles</h3>
                <p class="text-sm text-gray-600">Name each connected terminal and use the instance key in bot profiles. Bot trades route here — no MetaAPI.</p>
                @if ($terminals->isEmpty())
                    <p class="text-sm text-gray-500">No EA has polled yet. Start LaravelBridge on a chart to register this account.</p>
                @else
                    <div class="space-y-4">
                        @foreach ($terminals as $terminal)
                            <form method="POST" action="{{ route('ea-bridge.terminals.update', $terminal) }}" class="border border-gray-200 rounded-lg p-4 grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                                @csrf
                                <div>
                                    <p class="text-xs text-gray-500">Account</p>
                                    <p class="font-mono text-sm">{{ $terminal->account_login }}</p>
                                    <p class="text-xs text-gray-500">{{ $terminal->server }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Instance Key</label>
                                    <input name="instance_key" type="text" value="{{ old('instance_key.'.$terminal->id, $terminal->instance_key) }}" class="mt-1 block w-full rounded border-gray-300 font-mono text-xs" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Display Name</label>
                                    <input name="display_name" type="text" value="{{ old('display_name.'.$terminal->id, $terminal->display_name) }}" class="mt-1 block w-full rounded border-gray-300 text-sm" />
                                </div>
                                <div class="space-y-2">
                                    <label class="inline-flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="enabled" value="1" @checked(old('enabled.'.$terminal->id, $terminal->enabled)) class="rounded border-gray-300" />
                                        Enabled
                                    </label>
                                    <label class="inline-flex items-center gap-2 text-sm">
                                        <input type="checkbox" name="is_demo" value="1" @checked(old('is_demo.'.$terminal->id, $terminal->is_demo)) class="rounded border-gray-300" />
                                        Demo account
                                    </label>
                                    <button type="submit" class="inline-flex px-3 py-2 bg-indigo-600 text-white text-xs rounded hover:bg-indigo-500">Save</button>
                                    <p class="text-xs {{ $terminal->isOnline() ? 'text-green-700' : 'text-gray-500' }}">
                                        {{ $terminal->isOnline() ? 'Online' : 'Offline' }} · {{ $terminal->last_seen_at?->diffForHumans() ?? 'never' }}
                                    </p>
                                </div>
                            </form>
                        @endforeach
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
                        <label class="block text-sm font-medium text-gray-700">MT5 Instance (optional)</label>
                        <select name="mt5_instance_key" class="mt-1 block w-full rounded border-gray-300">
                            <option value="">Any online terminal</option>
                            @foreach ($terminals as $terminal)
                                @if ($terminal->instance_key)
                                    <option value="{{ $terminal->instance_key }}" @selected(old('mt5_instance_key') === $terminal->instance_key)>
                                        {{ $terminal->label() }}
                                    </option>
                                @endif
                            @endforeach
                        </select>
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
