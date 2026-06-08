{{-- Shared form fields for create & edit --}}
<div class="space-y-5">

    <div>
        <x-input-label for="symbol" value="Symbol *" />
        <x-text-input id="symbol" name="symbol" type="text" class="mt-1 block w-full uppercase"
                      value="{{ old('symbol', $ticker->symbol ?? '') }}" required maxlength="20"
                      placeholder="e.g. EURUSD" />
        <x-input-error :messages="$errors->get('symbol')" class="mt-1" />
    </div>

    <div>
        <x-input-label for="description" value="Description" />
        <x-text-input id="description" name="description" type="text" class="mt-1 block w-full"
                      value="{{ old('description', $ticker->description ?? '') }}" maxlength="255"
                      placeholder="e.g. Euro / US Dollar" />
        <x-input-error :messages="$errors->get('description')" class="mt-1" />
    </div>

    <div>
        <div class="flex items-center gap-2">
            <x-input-label for="category" value="Category" />
            <button type="button"
                    id="category-info-open-form"
                    class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-100 text-blue-700 text-xs font-bold"
                    aria-haspopup="dialog"
                    aria-controls="category-info-modal-form">
                i
            </button>
        </div>
        <select id="category" name="category"
                class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring focus:ring-indigo-200 focus:border-indigo-400 text-sm">
            <option value="">Select category</option>
            @foreach ($categoryOptions as $option)
                <option value="{{ $option }}" {{ old('category', $ticker->category ?? '') === $option ? 'selected' : '' }}>{{ $option }}</option>
            @endforeach
        </select>
        <x-input-error :messages="$errors->get('category')" class="mt-1" />
    </div>

    <div id="category-info-modal-form" class="hidden fixed inset-0 z-50">
        <div id="category-info-backdrop-form" class="absolute inset-0 bg-black/40"></div>
        <div class="relative z-10 flex min-h-full items-center justify-center p-4">
            <div class="w-full max-w-lg rounded-lg bg-white shadow-xl border border-gray-200">
                <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-800">Category Defaults</h3>
                    <button type="button" id="category-info-close-form" class="text-gray-500 hover:text-gray-700 text-sm">Close</button>
                </div>
                <div class="p-4 text-sm text-gray-700 space-y-2">
                    <p><span class="font-semibold">Spread:</span> Forex=global, Stock=max(global,25), Commodity=max(global,15), Other=max(global,10)</p>
                    <p><span class="font-semibold">TP:</span> Forex=global, Stock=max(global,120), Commodity=max(global,80), Other=max(global,60)</p>
                    <p><span class="font-semibold">SL:</span> Forex=global, Stock=max(global,60), Commodity=max(global,40), Other=max(global,30)</p>
                    <p><span class="font-semibold">Category options:</span> Forex, Stock, Commodity, Index, Crypto, Other</p>
                    <p class="text-xs text-gray-500">Ticker-level overrides (`max_spread_pips`, `max_tp_pips`, `max_sl_pips`) take priority when set.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <input id="is_active" name="is_active" type="checkbox" value="1"
               {{ old('is_active', $ticker->is_active ?? true) ? 'checked' : '' }}
               class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
        <x-input-label for="is_active" value="Active (bot will trade this symbol)" />
    </div>

    <div>
        <x-input-label for="pip_size" value="Pip / Tick Size" />
        <x-text-input id="pip_size" name="pip_size" type="number" step="0.00000001" min="0" class="mt-1 block w-48"
                      value="{{ old('pip_size', $ticker->pip_size ?? '') }}"
                      placeholder="Leave blank for auto" />
        <p class="mt-1 text-xs text-gray-400">Forex auto-detected (0.0001 / 0.01 JPY). Stocks: 0.1 · Indices: 1.0 · Crypto: 0.01</p>
        <x-input-error :messages="$errors->get('pip_size')" class="mt-1" />
    </div>

    <div>
        <x-input-label for="max_spread_pips" value="Max Spread (pips) Override" />
        <x-text-input id="max_spread_pips" name="max_spread_pips" type="number" step="0.001" min="0" class="mt-1 block w-48"
                      value="{{ old('max_spread_pips', $ticker->max_spread_pips ?? '') }}"
                      placeholder="Leave blank for category default" />
        <p class="mt-1 text-xs text-gray-400">If set, bot uses this ticker-level spread cap. If blank, bot uses category/default spread caps.</p>
        <x-input-error :messages="$errors->get('max_spread_pips')" class="mt-1" />
    </div>

    <div>
        <x-input-label for="max_tp_pips" value="TP (pips) Override" />
        <x-text-input id="max_tp_pips" name="max_tp_pips" type="number" step="0.001" min="0" class="mt-1 block w-48"
                      value="{{ old('max_tp_pips', $ticker->max_tp_pips ?? '') }}"
                      placeholder="Leave blank for category default" />
        <p class="mt-1 text-xs text-gray-400">If set, bot uses this ticker-level TP pips. If blank, bot uses category/default TP.</p>
        <x-input-error :messages="$errors->get('max_tp_pips')" class="mt-1" />
    </div>

    <div>
        <x-input-label for="max_sl_pips" value="SL (pips) Override" />
        <x-text-input id="max_sl_pips" name="max_sl_pips" type="number" step="0.001" min="0" class="mt-1 block w-48"
                      value="{{ old('max_sl_pips', $ticker->max_sl_pips ?? '') }}"
                      placeholder="Leave blank for category default" />
        <p class="mt-1 text-xs text-gray-400">If set, bot uses this ticker-level SL pips. If blank, bot uses category/default SL.</p>
        <x-input-error :messages="$errors->get('max_sl_pips')" class="mt-1" />
    </div>

    <div>
        <x-input-label for="notes" value="Notes" />
        <textarea id="notes" name="notes" rows="3" maxlength="2000"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring focus:ring-indigo-200 focus:border-indigo-400 text-sm"
                  placeholder="Optional notes about this ticker…">{{ old('notes', $ticker->notes ?? '') }}</textarea>
        <x-input-error :messages="$errors->get('notes')" class="mt-1" />
    </div>

</div>

<script>
    (function () {
        const openBtn = document.getElementById('category-info-open-form');
        const closeBtn = document.getElementById('category-info-close-form');
        const modal = document.getElementById('category-info-modal-form');
        const backdrop = document.getElementById('category-info-backdrop-form');

        if (!openBtn || !closeBtn || !modal || !backdrop) {
            return;
        }

        const openModal = () => modal.classList.remove('hidden');
        const closeModal = () => modal.classList.add('hidden');

        openBtn.addEventListener('click', openModal);
        closeBtn.addEventListener('click', closeModal);
        backdrop.addEventListener('click', closeModal);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });
    })();
</script>
