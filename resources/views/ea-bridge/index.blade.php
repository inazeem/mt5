<x-app-layout>
    <div @if ($openCredentialsFor) x-data x-init="$nextTick(() => $dispatch('open-modal', 'ea-token-{{ $openCredentialsFor }}'))" @endif>
    <x-page-header title="MT5 Instances" subtitle="Connect LaravelBridge EAs, manage tokens, and test trades per terminal." />

    <div class="mx-auto max-w-6xl space-y-6">
        <x-flash-messages />

        <x-guide-panel title="How MT5 Instances work">
            <ul>
                <li>Create one instance per MT5 install (demo or live). Each gets a unique API token.</li>
                <li>In MT5: whitelist your Laravel URL in WebRequest, set <code>InpServerUrl</code> and paste the token from <strong>Credentials → Show Token</strong>.</li>
                <li>Only one EA typically polls at a time per chart. Bot profiles can target one or more instances.</li>
                <li>Set <strong>Symbol suffix mode</strong> per instance: Pepperstone spread-bet uses <code>_SB</code>; IC Markets uses plain symbols.</li>
                <li>See the <a href="{{ route('setup.index') }}">Setup Guide</a> for full steps including production deployment.</li>
            </ul>
        </x-guide-panel>

            <x-card title="Add Instance">
                <p class="text-sm text-slate-600 dark:text-slate-400">One row per MT5 install. Name by broker and demo/live. Each gets its own API token.</p>
                <form method="POST" action="{{ route('ea-bridge.instances.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                    @csrf
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Display Name</label>
                        <input name="display_name" type="text" required value="{{ old('display_name') }}" placeholder="e.g. Pepperstone Demo" class="mt-1 block w-full rounded border-gray-300" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Instance Key (optional)</label>
                        <input name="instance_key" type="text" value="{{ old('instance_key') }}" placeholder="pepperstone-demo" class="mt-1 block w-full rounded border-gray-300 font-mono text-xs" />
                    </div>
                    <div class="space-y-2">
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" name="is_demo" value="1" @checked(old('is_demo', true)) class="rounded border-gray-300" />
                            Demo account
                        </label>
                        <button type="submit" class="inline-flex px-4 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-500">Create Instance</button>
                    </div>
                </form>
                <div class="text-xs text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700 rounded p-3">
                    You can register many instances, but typically <strong>only one EA polls at a time</strong> per MT5 chart.
                    @if ($terminals->isNotEmpty())
                        Currently <strong>{{ $onlineCount }}</strong> of {{ $terminals->count() }} online.
                    @endif
                </div>
            </x-card>

            <x-card title="Instances">
                @if ($terminals->isEmpty())
                    <p class="text-sm text-gray-500">No instances yet. Create one above, open <strong>Credentials</strong>, copy the token into MT5, then attach LaravelBridge.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 border-b">
                                    <th class="py-2 pr-4">Name</th>
                                    <th class="py-2 pr-4">Key</th>
                                    <th class="py-2 pr-4">Account</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($terminals as $terminal)
                                    <tr class="border-b border-gray-100 align-top {{ $terminal->is_demo ? 'bg-sky-50/40' : 'bg-rose-50/30' }}">
                                        <td class="py-3 pr-4">
                                            <div class="font-medium text-gray-900">{{ $terminal->label() }}</div>
                                            <div class="flex gap-1 mt-1">
                                                <span class="text-xs px-1.5 py-0.5 rounded {{ $terminal->is_demo ? 'bg-sky-100 text-sky-800' : 'bg-rose-100 text-rose-800' }}">{{ $terminal->environmentLabel() }}</span>
                                                @if (! $terminal->enabled)
                                                    <span class="text-xs px-1.5 py-0.5 rounded bg-gray-200 text-gray-600">Disabled</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="py-3 pr-4 font-mono text-xs text-gray-600">{{ $terminal->instance_key ?? '—' }}</td>
                                        <td class="py-3 pr-4 text-xs text-gray-600">
                                            @if ($terminal->isBound())
                                                <div>login {{ $terminal->account_login }}</div>
                                                <div>{{ $terminal->server }}</div>
                                                <div>{{ number_format((float) $terminal->balance, 2) }} {{ $terminal->currency }}</div>
                                            @else
                                                <span class="text-amber-700">Awaiting EA poll</span>
                                            @endif
                                        </td>
                                        <td class="py-3 pr-4">
                                            <span class="text-xs px-2 py-0.5 rounded {{ $terminal->isOnline() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                                {{ $terminal->isOnline() ? 'Online' : 'Offline' }}
                                            </span>
                                            <div class="text-xs text-gray-400 mt-1">{{ $terminal->last_seen_at?->diffForHumans() ?? 'never' }}</div>
                                        </td>
                                        <td class="py-3">
                                            <div class="flex flex-wrap gap-1">
                                                <button type="button" x-data x-on:click="$dispatch('open-modal', 'ea-token-{{ $terminal->id }}')" class="inline-flex px-2 py-1 bg-white border border-gray-300 text-xs rounded hover:bg-gray-50">
                                                    Credentials
                                                </button>
                                                <button type="button" x-data x-on:click="$dispatch('open-modal', 'ea-edit-{{ $terminal->id }}')" class="inline-flex px-2 py-1 bg-white border border-gray-300 text-xs rounded hover:bg-gray-50">
                                                    Edit
                                                </button>
                                                <button type="button" x-data x-on:click="$dispatch('open-modal', 'ea-test-{{ $terminal->id }}')" class="inline-flex px-2 py-1 bg-emerald-600 text-white text-xs rounded hover:bg-emerald-500">
                                                    Test Trade
                                                </button>
                                                <button type="button" x-data x-on:click="$dispatch('open-modal', 'ea-delete-{{ $terminal->id }}')" class="inline-flex px-2 py-1 bg-white border border-red-200 text-red-700 text-xs rounded hover:bg-red-50">
                                                    Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @foreach ($terminals as $terminal)
                        @include('ea-bridge._instance-modals', [
                            'terminal' => $terminal,
                            'revealedTokens' => $revealedTokens,
                            'linkedProfilesByTerminal' => $linkedProfilesByTerminal,
                        ])
                    @endforeach
                @endif
            </x-card>

            @if ($terminals->isNotEmpty())
                <x-card title="Manual Command">
                <form method="POST" action="{{ route('ea-bridge.commands') }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Instance</label>
                            <select name="mt5_instance_key" class="mt-1 block w-full rounded border-gray-300" required>
                                @foreach ($terminals as $terminal)
                                    @if ($terminal->instance_key)
                                        <option value="{{ $terminal->instance_key }}">{{ $terminal->label() }} ({{ $terminal->environmentLabel() }}){{ $terminal->isOnline() ? '' : ' — offline' }}</option>
                                    @endif
                                @endforeach
                            </select>
                        </div>
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
                            <input name="symbol" type="text" value="GBPUSD" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Lot</label>
                            <input name="lot" type="number" step="0.001" min="0.001" value="0.01" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">SL (pips)</label>
                            <input name="sl" type="number" step="0.1" min="0" value="20" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">TP (pips)</label>
                            <input name="tp" type="number" step="0.1" min="0" value="40" class="mt-1 block w-full rounded border-gray-300" />
                        </div>
                    </div>
                    <button type="submit" class="inline-flex px-4 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-500">Queue Command</button>
                </form>
                </x-card>
            @endif

            <x-card title="Recent Commands">
                @if ($recentCommands->isEmpty())
                    <p class="text-sm text-gray-500">No commands queued yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 border-b">
                                    <th class="py-2 pr-4">#</th>
                                    <th class="py-2 pr-4">Instance</th>
                                    <th class="py-2 pr-4">Source</th>
                                    <th class="py-2 pr-4">Action</th>
                                    <th class="py-2 pr-4">Symbol</th>
                                    <th class="py-2 pr-4">Status</th>
                                    <th class="py-2">Queued</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentCommands as $command)
                                    <tr class="border-b border-gray-100">
                                        <td class="py-2 pr-4">{{ $command->id }}</td>
                                        <td class="py-2 pr-4">{{ $command->terminal?->label() ?? $command->mt5_instance_key ?? '—' }}</td>
                                        <td class="py-2 pr-4">{{ $command->source ?? '—' }}</td>
                                        <td class="py-2 pr-4">{{ $command->action }}</td>
                                        <td class="py-2 pr-4">{{ $command->symbol ?? '—' }}</td>
                                        <td class="py-2 pr-4">{{ $command->status }}</td>
                                        <td class="py-2">{{ $command->queued_at?->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>
    </div>
</x-app-layout>
