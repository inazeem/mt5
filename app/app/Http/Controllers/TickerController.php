<?php

namespace App\Http\Controllers;

use App\Models\Ticker;
use Illuminate\Http\Request;

class TickerController extends Controller
{
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

        return view('tickers.index', compact('tickers', 'categories', 'validated', 'perPage'));
    }

    public function create()
    {
        $categories = Ticker::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return view('tickers.create', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'symbol'      => ['required', 'string', 'max:20', 'unique:tickers,symbol', 'regex:/^[A-Za-z0-9._\-\/]+$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'category'    => ['nullable', 'string', 'max:50'],
            'is_active'   => ['nullable', 'boolean'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['symbol'] = strtoupper($validated['symbol']);
        $validated['is_active'] = $request->boolean('is_active');

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

        return view('tickers.edit', compact('ticker', 'categories'));
    }

    public function update(Request $request, Ticker $ticker)
    {
        $validated = $request->validate([
            'symbol'      => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9._\-\/]+$/', "unique:tickers,symbol,{$ticker->id}"],
            'description' => ['nullable', 'string', 'max:255'],
            'category'    => ['nullable', 'string', 'max:50'],
            'is_active'   => ['nullable', 'boolean'],
            'notes'       => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['symbol'] = strtoupper($validated['symbol']);
        $validated['is_active'] = $request->boolean('is_active');

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
