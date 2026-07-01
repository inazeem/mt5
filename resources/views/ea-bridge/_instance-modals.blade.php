@php
    $modalId = (string) $terminal->id;
    $revealedToken = $revealedTokens[$terminal->id] ?? null;
    $pollUrl = url('/api/ea/poll');
@endphp

{{-- Edit --}}
<x-modal name="ea-edit-{{ $modalId }}" focusable>
    <form method="POST" action="{{ route('ea-bridge.terminals.update', $terminal) }}" class="p-6 space-y-4">
        @csrf
        <h3 class="text-lg font-medium text-gray-900">Edit {{ $terminal->label() }}</h3>
        <div>
            <label class="block text-sm font-medium text-gray-700">Display Name</label>
            <input name="display_name" type="text" required value="{{ old('display_name.'.$terminal->id, $terminal->display_name) }}" class="mt-1 block w-full rounded border-gray-300 text-sm" />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Instance Key</label>
            <input name="instance_key" type="text" value="{{ old('instance_key.'.$terminal->id, $terminal->instance_key) }}" class="mt-1 block w-full rounded border-gray-300 font-mono text-xs" />
        </div>
        <div class="flex flex-wrap gap-4">
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="enabled" value="1" @checked(old('enabled.'.$terminal->id, $terminal->enabled)) class="rounded border-gray-300" />
                Enabled
            </label>
            <label class="inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_demo" value="1" @checked(old('is_demo.'.$terminal->id, $terminal->is_demo)) class="rounded border-gray-300" />
                Demo account
            </label>
        </div>
        <div class="flex justify-end gap-3 pt-2">
            <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
            <button type="submit" class="inline-flex px-4 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-500">Save</button>
        </div>
    </form>
</x-modal>

{{-- Credentials / token --}}
<x-modal name="ea-token-{{ $modalId }}" focusable>
    <div class="p-6 space-y-4">
        <h3 class="text-lg font-medium text-gray-900">MT5 credentials — {{ $terminal->label() }}</h3>
        <p class="text-sm text-gray-600">Paste these into LaravelBridge inputs on the matching MT5 chart. Only one EA should poll at a time per token.</p>

        <div>
            <label class="block text-sm font-medium text-gray-700">Poll URL (InpServerUrl)</label>
            <input type="text" readonly value="{{ $pollUrl }}" class="mt-1 block w-full rounded border-gray-300 font-mono text-xs bg-gray-50" onclick="this.select()" />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">Instance Key (InpInstanceKey)</label>
            <input type="text" readonly value="{{ $terminal->instance_key }}" class="mt-1 block w-full rounded border-gray-300 font-mono text-xs bg-gray-50" onclick="this.select()" />
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700">API Token (InpApiToken)</label>
            @if ($revealedToken)
                <input type="text" readonly value="{{ $revealedToken }}" class="mt-1 block w-full rounded border-gray-300 font-mono text-xs bg-amber-50" onclick="this.select()" />
                <p class="mt-1 text-xs text-amber-700">Copy now — hidden again after you leave this page unless you click Show Token.</p>
            @else
                <input type="text" readonly value="••••••••••••••••••••••••••••••••" class="mt-1 block w-full rounded border-gray-300 font-mono text-xs text-gray-400 bg-gray-50" />
                <p class="mt-1 text-xs text-gray-500">Token is hidden. Use <strong>Show Token</strong> to copy the current one, or <strong>Regenerate</strong> only if you need a new token.</p>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-4 border-t border-gray-100">
            <x-secondary-button type="button" x-on:click="$dispatch('close')">Close</x-secondary-button>
            <form method="POST" action="{{ route('ea-bridge.terminals.reveal-token', $terminal) }}" class="inline">
                @csrf
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Show Token
                </button>
            </form>
            <form method="POST" action="{{ route('ea-bridge.terminals.token', $terminal) }}" class="inline" onsubmit="return confirm('Regenerate token for {{ addslashes($terminal->label()) }}? The old token stops working in MT5 until you update InpApiToken.');">
                @csrf
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Regenerate
                </button>
            </form>
        </div>
    </div>
</x-modal>

{{-- Test trade --}}
<x-modal name="ea-test-{{ $modalId }}" focusable>
    <form method="POST" action="{{ route('ea-bridge.terminals.test-trade', $terminal) }}" class="p-6 space-y-4">
        @csrf
        <h3 class="text-lg font-medium text-gray-900">Test trade — {{ $terminal->label() }}</h3>
        @if (! $terminal->isOnline())
            <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded p-3">This instance is offline. Start LaravelBridge in MT5 first.</p>
        @else
            <p class="text-sm text-gray-600">Queues a small BUY on the selected symbol. The EA should execute on the next poll (~1s).</p>
        @endif
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Symbol</label>
                <input name="symbol" type="text" value="GBPUSD" class="mt-1 block w-full rounded border-gray-300 text-sm" @disabled(! $terminal->isOnline()) />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Lot</label>
                <input name="lot" type="number" step="0.001" min="0.001" max="1" value="0.01" class="mt-1 block w-full rounded border-gray-300 text-sm" @disabled(! $terminal->isOnline()) />
            </div>
        </div>
        <p class="text-xs text-gray-500">Test trades sent: {{ $terminal->test_commands_count ?? 0 }}</p>
        <div class="flex justify-end gap-3 pt-2">
            <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
            <button type="submit" class="inline-flex px-4 py-2 bg-emerald-600 text-white text-sm rounded hover:bg-emerald-500 disabled:opacity-50" @disabled(! $terminal->isOnline())>
                Queue Test Trade
            </button>
        </div>
    </form>
</x-modal>

{{-- Delete --}}
<x-modal name="ea-delete-{{ $modalId }}" focusable>
    <form method="POST" action="{{ route('ea-bridge.terminals.destroy', $terminal) }}" class="p-6 space-y-4">
        @csrf
        @method('delete')
        <h3 class="text-lg font-medium text-gray-900">Delete {{ $terminal->label() }}?</h3>
        <p class="text-sm text-gray-600">
            Removes this instance from Laravel. MT5 will stop authenticating with its token.
            @if ($terminal->isOnline())
                <span class="text-amber-700">This instance is currently online.</span>
            @endif
        </p>
        @php $linkedProfiles = $linkedProfilesByTerminal[$terminal->id] ?? []; @endphp
        @if (! empty($linkedProfiles))
            <p class="text-sm text-red-700 bg-red-50 border border-red-200 rounded p-3">
                Linked bot profiles: {{ implode(', ', $linkedProfiles) }}. Unlink them before deleting.
            </p>
        @endif
        <div class="flex justify-end gap-3 pt-2">
            <x-secondary-button type="button" x-on:click="$dispatch('close')">Cancel</x-secondary-button>
            @if (! empty($linkedProfiles))
                <x-danger-button disabled>Delete Instance</x-danger-button>
            @else
                <x-danger-button>Delete Instance</x-danger-button>
            @endif
        </div>
    </form>
</x-modal>
