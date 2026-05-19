<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Tickers</h2>
            <a href="{{ route('tickers.create') }}"
               class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded hover:bg-indigo-700">
                + Add Ticker
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

            @if (session('status'))
                <div class="rounded border border-emerald-200 bg-emerald-50 text-emerald-700 px-4 py-3 text-sm">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Filters --}}
            <form method="GET" action="{{ route('tickers.index') }}" class="bg-white p-4 rounded-lg shadow flex flex-wrap gap-3 items-end">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Search</label>
                    <input type="text" name="search" value="{{ $validated['search'] ?? '' }}" placeholder="Symbol or description"
                           class="border border-gray-300 rounded px-2 py-1 text-sm w-48 focus:outline-none focus:ring focus:ring-indigo-200">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Category</label>
                    <select name="category" class="border border-gray-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring focus:ring-indigo-200">
                        <option value="">All</option>
                        @foreach ($categories as $cat)
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

            {{-- Bulk actions --}}
            <div class="bg-white p-3 rounded-lg shadow flex items-center gap-3">
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

            {{-- Table --}}
            <div class="bg-white rounded-lg shadow overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-600 border-b bg-gray-50">
                            <th class="py-3 px-4 text-center w-12">
                                <input id="select-all-tickers" type="checkbox" class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                            </th>
                            <th class="py-3 px-4">Symbol</th>
                            <th class="py-3 px-4">Description</th>
                            <th class="py-3 px-4">Category</th>
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
                                <td colspan="7" class="py-8 text-center text-gray-400">No tickers found. <a href="{{ route('tickers.create') }}" class="text-indigo-600 underline">Add one.</a></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between text-sm text-gray-500">
                <div>
                    Showing {{ $tickers->firstItem() ?? 0 }}-{{ $tickers->lastItem() ?? 0 }} of {{ $tickers->total() }}
                    (Page {{ $tickers->currentPage() }} of {{ $tickers->lastPage() }})
                </div>
                <div>{{ $tickers->links() }}</div>
            </div>

        </div>
    </div>

    <script>
        (function () {
            const selectAll = document.getElementById('select-all-tickers');
            const rowCheckboxes = Array.from(document.querySelectorAll('.ticker-row-checkbox'));
            const bulkForm = document.getElementById('bulk-delete-form');
            const bulkIdsContainer = document.getElementById('bulk-delete-ids');
            const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
            const bulkSelectedHelp = document.getElementById('bulk-selected-help');

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

            syncBulkState();
        })();
    </script>
</x-app-layout>
