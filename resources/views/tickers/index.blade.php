<x-app-layout>
    <x-page-header title="Tickers" subtitle="Symbols the bot can trade, with spread and TP/SL limits.">
        <x-slot name="actions">
            <a href="{{ route('tickers.create') }}" class="inline-flex rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500">+ Add Ticker</a>
        </x-slot>
    </x-page-header>

    <div class="mx-auto max-w-7xl space-y-4">
        <x-flash-messages />

            {{-- Filters --}}
            <x-card :padding="false">
            <form method="GET" action="{{ route('tickers.index') }}" class="flex flex-wrap gap-3 items-end p-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Search</label>
                    <input type="text" name="search" value="{{ $validated['search'] ?? '' }}" placeholder="Symbol or description"
                           class="border border-gray-300 rounded px-2 py-1 text-sm w-48 focus:outline-none focus:ring focus:ring-indigo-200">
                </div>
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <label class="block text-xs text-gray-500">Category</label>
                        <button type="button"
                                id="category-info-open-index"
                                class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-blue-100 text-blue-700 text-[10px] font-bold"
                                aria-haspopup="dialog"
                                aria-controls="category-info-modal-index">
                            i
                        </button>
                    </div>
                    <select name="category" class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring focus:ring-indigo-200">
                        <option value="">All</option>
                        @foreach ($filterCategories as $cat)
                            <option value="{{ $cat }}" {{ ($validated['category'] ?? '') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Status</label>
                    <select name="active" class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring focus:ring-indigo-200">
                        <option value="">All</option>
                        <option value="1" {{ ($validated['active'] ?? '') === '1' ? 'selected' : '' }}>Active</option>
                        <option value="0" {{ ($validated['active'] ?? '') === '0' ? 'selected' : '' }}>Inactive</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Per page</label>
                    <select name="per_page" class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring focus:ring-indigo-200">
                        @foreach ([20, 25, 50, 100] as $pp)
                            <option value="{{ $pp }}" {{ $perPage === $pp ? 'selected' : '' }}>{{ $pp }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded hover:bg-indigo-700">Filter</button>
                    <a href="{{ route('tickers.index') }}" class="px-3 py-1.5 bg-gray-200 text-gray-700 text-xs font-semibold rounded hover:bg-gray-300">Clear</a>
                </div>
                <div class="ml-auto text-xs text-gray-400 self-end">{{ $tickers->total() }} ticker(s)</div>
            </form>
            </x-card>

            <div id="category-info-modal-index" class="hidden fixed inset-0 z-50">
                <div id="category-info-backdrop-index" class="absolute inset-0 bg-black/40"></div>
                <div class="relative z-10 flex min-h-full items-center justify-center p-4">
                    <div class="w-full max-w-lg rounded-lg bg-white shadow-xl border border-gray-200">
                        <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-gray-800">Category Defaults</h3>
                            <button type="button" id="category-info-close-index" class="text-gray-500 hover:text-gray-700 text-sm">Close</button>
                        </div>
                        <div class="p-4 text-sm text-gray-700 space-y-2">
                            <p><span class="font-semibold">Spread:</span> Forex=global, Stock=max(global,40), Commodity=max(global,15), Other=max(global,10)</p>
                            <p><span class="font-semibold">TP:</span> Forex=global, Stock=max(global,160), Commodity=max(global,80), Other=max(global,60)</p>
                            <p><span class="font-semibold">SL:</span> Forex=global, Stock=max(global,80), Commodity=max(global,40), Other=max(global,30)</p>
                            <p><span class="font-semibold">Category options:</span> Forex, Stock, Commodity, Index, Crypto, Other</p>
                            <p class="text-xs text-gray-500">Ticker-level overrides (`max_spread_pips`, `max_tp_pips`, `max_sl_pips`) take priority when set.</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Bulk actions --}}
            <x-card :padding="false">
            <div class="flex items-center gap-3 p-3">
                <form id="bulk-delete-form" method="POST" action="{{ route('tickers.bulk-delete', request()->query()) }}" class="flex items-center gap-2">
                    @csrf
                    @method('DELETE')
                    <div id="bulk-delete-ids"></div>
                    <button id="bulk-delete-btn" type="submit"
                            class="px-3 py-1.5 bg-rose-600 text-white text-xs font-semibold rounded hover:bg-rose-700 disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled>
                        Delete Selected
                    </button>
                </form>
                <div id="bulk-selected-help" class="text-xs text-gray-500">Select rows, then click Delete Selected.</div>
            </div>
            </x-card>

            <x-card :padding="false">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-600 border-b bg-gray-50">
                            <th class="py-3 px-4 text-center w-12">
                                <input id="select-all-tickers" type="checkbox" class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                            </th>
                            <th class="py-3 px-4">Symbol</th>
                            <th class="py-3 px-4">Description</th>
                            <th class="py-3 px-4">Category</th>
                            <th class="py-3 px-4">Max Spread</th>
                            <th class="py-3 px-4">TP Override</th>
                            <th class="py-3 px-4">SL Override</th>
                            <th class="py-3 px-4 text-center">Active</th>
                            <th class="py-3 px-4">Notes</th>
                            <th class="py-3 px-4 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tickers as $ticker)
                            <tr class="border-b border-gray-100 hover:bg-gray-50">
                                <td class="py-2 px-4 text-center">
                                    <input type="checkbox" value="{{ $ticker->id }}"
                                           class="ticker-row-checkbox w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                                </td>
                                <td class="py-2 px-4 font-mono font-semibold">{{ $ticker->symbol }}</td>
                                <td class="py-2 px-4 text-gray-700">{{ $ticker->description ?? '-' }}</td>
                                <td class="py-2 px-4 text-gray-500">{{ $ticker->category ?? '-' }}</td>
                                <td class="py-2 px-4 text-gray-500">{{ $ticker->max_spread_pips !== null ? number_format((float) $ticker->max_spread_pips, 3) : '-' }}</td>
                                <td class="py-2 px-4 text-gray-500">{{ $ticker->max_tp_pips !== null ? number_format((float) $ticker->max_tp_pips, 3) : '-' }}</td>
                                <td class="py-2 px-4 text-gray-500">{{ $ticker->max_sl_pips !== null ? number_format((float) $ticker->max_sl_pips, 3) : '-' }}</td>
                                <td class="py-2 px-4 text-center">
                                    <form method="POST" action="{{ route('tickers.toggle-active', ['ticker' => $ticker] + request()->query()) }}" class="inline">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit"
                                                title="Toggle trading status"
                                                class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded {{ $ticker->is_active ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                                            {{ $ticker->is_active ? 'ON' : 'OFF' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="py-2 px-4 text-gray-500 max-w-xs truncate">{{ $ticker->notes ?? '-' }}</td>
                                <td class="py-2 px-4 text-right whitespace-nowrap">
                                    <a href="{{ route('tickers.edit', $ticker) }}"
                                       class="inline-flex items-center px-2.5 py-1 text-xs font-semibold bg-amber-100 text-amber-700 rounded hover:bg-amber-200 mr-1">
                                        Edit
                                    </a>
                                    <form method="POST" action="{{ route('tickers.destroy', $ticker) }}" class="inline"
                                          onsubmit="return confirm('Delete {{ $ticker->symbol }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="inline-flex items-center px-2.5 py-1 text-xs font-semibold bg-rose-100 text-rose-700 rounded hover:bg-rose-200">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="py-8 text-center text-gray-400">No tickers found. <a href="{{ route('tickers.create') }}" class="text-indigo-600 underline">Add one.</a></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm text-gray-500 dark:border-slate-800">
                <div>
                    Showing {{ $tickers->firstItem() ?? 0 }}-{{ $tickers->lastItem() ?? 0 }} of {{ $tickers->total() }}
                    (Page {{ $tickers->currentPage() }} of {{ $tickers->lastPage() }})
                </div>
                <div>{{ $tickers->links() }}</div>
            </div>
            </x-card>
    </div>

    <script>
        (function () {
            const selectAll = document.getElementById('select-all-tickers');
            const rowCheckboxes = Array.from(document.querySelectorAll('.ticker-row-checkbox'));
            const bulkForm = document.getElementById('bulk-delete-form');
            const bulkIdsContainer = document.getElementById('bulk-delete-ids');
            const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
            const bulkSelectedHelp = document.getElementById('bulk-selected-help');
            const categoryInfoOpen = document.getElementById('category-info-open-index');
            const categoryInfoClose = document.getElementById('category-info-close-index');
            const categoryInfoModal = document.getElementById('category-info-modal-index');
            const categoryInfoBackdrop = document.getElementById('category-info-backdrop-index');

            const selectedCount = () => rowCheckboxes.filter((cb) => cb.checked).length;

            const syncBulkState = () => {
                const count = selectedCount();

                if (bulkDeleteBtn) {
                    bulkDeleteBtn.disabled = count === 0;
                    bulkDeleteBtn.textContent = count > 0 ? `Delete Selected (${count})` : 'Delete Selected';
                }

                if (bulkSelectedHelp) {
                    bulkSelectedHelp.textContent = count > 0
                        ? `${count} row(s) selected.`
                        : 'Select rows, then click Delete Selected.';
                }

                if (selectAll) {
                    const allChecked = rowCheckboxes.length > 0 && rowCheckboxes.every((cb) => cb.checked);
                    selectAll.checked = allChecked;
                }
            };

            if (selectAll) {
                selectAll.addEventListener('change', () => {
                    rowCheckboxes.forEach((cb) => {
                        cb.checked = selectAll.checked;
                    });
                    syncBulkState();
                });
            }

            rowCheckboxes.forEach((cb) => {
                cb.addEventListener('change', syncBulkState);
            });

            if (bulkForm) {
                bulkForm.addEventListener('submit', (event) => {
                    const checkedIds = rowCheckboxes
                        .filter((cb) => cb.checked)
                        .map((cb) => cb.value);

                    if (checkedIds.length === 0) {
                        event.preventDefault();
                        return;
                    }

                    if (!confirm(`Delete ${checkedIds.length} selected ticker(s)?`)) {
                        event.preventDefault();
                        return;
                    }

                    bulkIdsContainer.innerHTML = '';

                    checkedIds.forEach((id) => {
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'ids[]';
                        hiddenInput.value = id;
                        bulkIdsContainer.appendChild(hiddenInput);
                    });
                });
            }

            if (categoryInfoOpen && categoryInfoClose && categoryInfoModal && categoryInfoBackdrop) {
                const openCategoryModal = () => categoryInfoModal.classList.remove('hidden');
                const closeCategoryModal = () => categoryInfoModal.classList.add('hidden');

                categoryInfoOpen.addEventListener('click', openCategoryModal);
                categoryInfoClose.addEventListener('click', closeCategoryModal);
                categoryInfoBackdrop.addEventListener('click', closeCategoryModal);

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape' && !categoryInfoModal.classList.contains('hidden')) {
                        closeCategoryModal();
                    }
                });
            }

            syncBulkState();
        })();
    </script>
</x-app-layout>
