<div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 space-y-3">
    <h3 class="text-sm font-semibold text-emerald-900">MT5 Instance (EA Bridge)</h3>
    <p class="text-xs text-emerald-800">
        Forex/MT5 bot profiles execute through LaravelBridge — no MetaAPI. Pick which connected terminal should receive this profile's trades.
    </p>
    <div>
        <label class="block text-sm font-medium text-gray-700">MT5 Instance Key</label>
        <select name="mt5_instance_key" class="mt-1 block w-full rounded border-gray-300">
            <option value="">First online terminal</option>
            @foreach ($mt5Instances as $instance)
                <option value="{{ $instance->instance_key }}"
                    @selected(old('mt5_instance_key', $selectedInstanceKey ?? '') === $instance->instance_key)>
                    {{ $instance->label() }} ({{ $instance->instance_key }}){{ $instance->isOnline() ? ' — online' : ' — offline' }}
                </option>
            @endforeach
        </select>
        <p class="text-xs text-gray-500 mt-1">
            Register and name terminals on <a href="{{ route('ea-bridge.index') }}" class="text-indigo-600 hover:underline">EA Bridge</a>.
        </p>
        @error('mt5_instance_key')
            <p class="text-xs text-rose-600 mt-1">{{ $message }}</p>
        @enderror
    </div>
</div>
