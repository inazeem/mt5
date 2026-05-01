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
        <x-input-label for="category" value="Category" />
        {{-- Allow typing a new category or picking an existing one --}}
        <input id="category" name="category" type="text" list="category-list"
               value="{{ old('category', $ticker->category ?? '') }}" maxlength="50"
               placeholder="e.g. Forex, Crypto, Indices"
               class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring focus:ring-indigo-200 focus:border-indigo-400 text-sm">
        <datalist id="category-list">
            @foreach ($categories as $cat)
                <option value="{{ $cat }}">
            @endforeach
        </datalist>
        <x-input-error :messages="$errors->get('category')" class="mt-1" />
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
        <x-input-label for="notes" value="Notes" />
        <textarea id="notes" name="notes" rows="3" maxlength="2000"
                  class="mt-1 block w-full border-gray-300 rounded-md shadow-sm focus:ring focus:ring-indigo-200 focus:border-indigo-400 text-sm"
                  placeholder="Optional notes about this ticker…">{{ old('notes', $ticker->notes ?? '') }}</textarea>
        <x-input-error :messages="$errors->get('notes')" class="mt-1" />
    </div>

</div>
