<?php

namespace App\Http\Controllers;

use App\Models\Ticker;
use Illuminate\Http\Request;

class TickerController extends Controller
{
    private const CATEGORY_OPTIONS = [
        'Forex',
        'Stock',
        'Commodity',
        'Index',
        'Crypto',
        'Other',
    ];

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search'   => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:50'],
            'active'   => ['nullable', 'in:1,0'],
            'per_page' => ['nullable', 'integer', 'in:20,25,50,100'],
        ]);

        $query = Ticker::query()->orderBy('symbol');

        if (!empty($validated['search'])) {
            $term = $validated['search'];
            $query->where(function ($q) use ($term) {
                $q->where('symbol', 'like', "%{$term}%")
                  ->orWhere('description', 'like', "%{$term}%");
            });
        }

        if (isset($validated['category']) && $validated['category'] !== '') {
            $query->where('category', $validated['category']);
        }

        if (isset($validated['active']) && $validated['active'] !== '') {
            $query->where('is_active', (bool) $validated['active']);
        }

        $perPage = (int) ($validated['per_page'] ?? 20);
        $tickers = $query->paginate($perPage)->withQueryString();

        $categories = Ticker::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $categoryOptions = self::CATEGORY_OPTIONS;
        $filterCategories = collect(array_merge($categoryOptions, $categories->all()))
            ->filter(static fn ($value) => is_string($value) && trim($value) !== '')
            ->unique()
            ->values();

        return view('tickers.index', compact('tickers', 'categories', 'categoryOptions', 'filterCategories', 'validated', 'perPage'));
    }

    public function create()
    {
        $categories = Ticker::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $categoryOptions = self::CATEGORY_OPTIONS;

        return view('tickers.create', compact('categories', 'categoryOptions'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'symbol'      => ['required', 'string', 'max:20', 'unique:tickers,symbol', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'category'    => ['nullable', 'string', 'in:'.implode(',', self::CATEGORY_OPTIONS)],
            'is_active'   => ['nullable', 'boolean'],
            'pip_size'    => ['nullable', 'numeric', 'min:0.00000001', 'max:1000'],
            'max_spread_pips' => ['nullable', 'numeric', 'min:0.001', 'max:100000'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['symbol'] = strtoupper($validated['symbol']);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['pip_size'] = isset($validated['pip_size']) && $validated['pip_size'] !== '' ? (float) $validated['pip_size'] : null;
        $validated['max_spread_pips'] = isset($validated['max_spread_pips']) && $validated['max_spread_pips'] !== '' ? (float) $validated['max_spread_pips'] : null;

        Ticker::create($validated);

        return redirect()->route('tickers.index')
            ->with('status', "Ticker {$validated['symbol']} added.");
    }

    public function edit(Ticker $ticker)
    {
        $categories = Ticker::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $categoryOptions = self::CATEGORY_OPTIONS;

        return view('tickers.edit', compact('ticker', 'categories', 'categoryOptions'));
    }

    public function update(Request $request, Ticker $ticker)
    {
        $validated = $request->validate([
            'symbol'      => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._\-\/]+$/', "unique:tickers,symbol,{$ticker->id}"],
            'description' => ['nullable', 'string', 'max:255'],
            'category'    => ['nullable', 'string', 'in:'.implode(',', self::CATEGORY_OPTIONS)],
            'is_active'   => ['nullable', 'boolean'],
            'pip_size'    => ['nullable', 'numeric', 'min:0.00000001', 'max:1000'],
            'max_spread_pips' => ['nullable', 'numeric', 'min:0.001', 'max:100000'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['symbol'] = strtoupper($validated['symbol']);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['pip_size'] = isset($validated['pip_size']) && $validated['pip_size'] !== '' ? (float) $validated['pip_size'] : null;
        $validated['max_spread_pips'] = isset($validated['max_spread_pips']) && $validated['max_spread_pips'] !== '' ? (float) $validated['max_spread_pips'] : null;

        $ticker->update($validated);

        return redirect()->route('tickers.index')
            ->with('status', "Ticker {$ticker->symbol} updated.");
    }

    public function toggleActive(Request $request, Ticker $ticker)
    {
        $ticker->update([
            'is_active' => !$ticker->is_active,
        ]);

        $state = $ticker->is_active ? 'active' : 'inactive';

        return redirect()->to($request->headers->get('referer') ?? route('tickers.index'))
            ->with('status', "Ticker {$ticker->symbol} is now {$state}.");
    }

    public function destroy(Ticker $ticker)
    {
        $symbol = $ticker->symbol;
        $ticker->delete();

        return redirect()->route('tickers.index')
            ->with('status', "Ticker {$symbol} deleted.");
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:tickers,id'],
        ]);

        $deletedCount = Ticker::query()
            ->whereIn('id', $validated['ids'])
            ->delete();

        return redirect()->to($request->headers->get('referer') ?? route('tickers.index'))
            ->with('status', "Deleted {$deletedCount} ticker(s).");
    }
}
