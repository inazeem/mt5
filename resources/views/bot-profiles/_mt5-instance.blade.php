<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 space-y-4" x-data="{ broker: '{{ old('mt5_broker', $selectedBroker ?? 'ea_bridge') }}' }">
    <div>
        <h3 class="text-sm font-semibold text-emerald-900">Forex / MT5 execution</h3>
        <p class="text-xs text-emerald-800 mt-1">
            Choose how this profile connects to MT5. EA Bridge uses your LaravelBridge EA; MetaApi uses cloud credentials from <a href="{{ route('settings.edit') }}" class="text-indigo-600 hover:underline">Settings</a>.
        </p>
    </div>

    <div>
        <label class="block text-sm font-medium text-gray-700">Execution broker</label>
        <select name="mt5_broker" x-model="broker" class="mt-1 block w-full rounded border-gray-300 text-sm">
            <option value="ea_bridge">EA Bridge (LaravelBridge) — recommended</option>
            <option value="metaapi">MetaApi (cloud MT5)</option>
        </select>
        @error('mt5_broker')
            <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div x-show="broker === 'ea_bridge'" x-cloak class="space-y-3">
        <p class="text-xs text-emerald-800">
            Select one or more terminals — signals mirror to every selected online instance.
        </p>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">MT5 Instances</label>
            @if ($mt5Instances->isEmpty())
                <p class="text-xs text-gray-500">No instances registered yet.</p>
            @else
                <div class="space-y-2 rounded border border-emerald-100 bg-white p-3 max-h-48 overflow-y-auto">
                    @foreach ($mt5Instances as $instance)
                        <label class="flex items-start gap-2 text-sm text-gray-700">
                            <input
                                type="checkbox"
                                name="mt5_instance_keys[]"
                                value="{{ $instance->instance_key }}"
                                class="mt-0.5 rounded border-gray-300"
                                @checked(in_array($instance->instance_key, $selectedInstanceKeys ?? [], true))
                            />
                            <span>
                                <span class="font-medium">{{ $instance->label() }}</span>
                                <span class="block font-mono text-xs text-gray-500">{{ $instance->instance_key }}</span>
                                <span class="text-xs {{ $instance->isOnline() ? 'text-green-700' : 'text-gray-500' }}">
                                    {{ $instance->isOnline() ? 'Online' : 'Offline' }}
                                    · {{ $instance->environmentLabel() }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
            <p class="text-xs text-gray-500 mt-2">
                Leave all unchecked to use the first online terminal. Register instances on
                <a href="{{ route('ea-bridge.index') }}" class="text-indigo-600 hover:underline">MT5 Instances</a>.
            </p>
            @error('mt5_instance_keys')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
            @error('mt5_instance_keys.*')
                <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div x-show="broker === 'metaapi'" x-cloak class="text-xs text-gray-600 bg-white border border-emerald-100 rounded p-3">
        Uses global <strong>MetaApi token + account ID</strong> from Settings. Trailing stops and MetaApi outage cooldown apply. Instance checkboxes above are ignored for this profile.
    </div>
</div>
