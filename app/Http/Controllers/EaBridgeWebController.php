<?php

namespace App\Http\Controllers;

use App\Models\Mt5EaCommand;
use App\Models\Mt5EaTerminal;
use App\Services\EaBridgeService;
use App\Services\SymbolMapper;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class EaBridgeWebController extends Controller
{
    public function index(EaBridgeService $eaBridge)
    {
        $terminals = Mt5EaTerminal::query()
            ->withCount([
                'commands as test_commands_count' => static fn ($query) => $query->where('source', 'test'),
            ])
            ->orderByRaw('CASE WHEN last_seen_at >= ? THEN 0 ELSE 1 END', [now()->subSeconds(10)])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('is_demo')
            ->orderBy('display_name')
            ->get();

        $recentCommands = Mt5EaCommand::query()
            ->with('terminal')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $revealedTokens = session('ea_instance_tokens', []);
        $openCredentialsFor = session('ea_open_credentials_terminal');
        $onlineCount = $terminals->filter(static fn (Mt5EaTerminal $t) => $t->isOnline())->count();
        $linkedProfilesByTerminal = $terminals->mapWithKeys(static fn (Mt5EaTerminal $t) => [
            $t->id => $eaBridge->botProfileKeysUsingInstance((string) $t->instance_key),
        ]);

        return view('ea-bridge.index', compact(
            'terminals',
            'recentCommands',
            'revealedTokens',
            'openCredentialsFor',
            'onlineCount',
            'linkedProfilesByTerminal',
        ));
    }

    public function store(Request $request, EaBridgeService $eaBridge)
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:120'],
            'instance_key' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'is_demo' => ['nullable', 'boolean'],
        ]);

        $validated['is_demo'] = $request->boolean('is_demo', true);

        try {
            $terminal = $eaBridge->createInstance($validated);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['display_name' => $e->getMessage()]);
        }

        return redirect()
            ->route('ea-bridge.index')
            ->with('status', 'Instance "'.$terminal->label().'" created. Copy its API token into MT5.')
            ->with('ea_instance_tokens', [
                $terminal->id => (string) $terminal->api_token,
            ])
            ->with('ea_open_credentials_terminal', $terminal->id);
    }

    public function updateTerminal(Request $request, Mt5EaTerminal $terminal, EaBridgeService $eaBridge)
    {
        $validated = $request->validate([
            'instance_key' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'enabled' => ['nullable', 'boolean'],
            'is_demo' => ['nullable', 'boolean'],
            'symbol_suffix' => ['nullable', 'string', 'in:auto,none,spread_bet'],
            'symbol_map' => ['nullable', 'string', 'max:5000'],
        ]);

        $validated['enabled'] = $request->boolean('enabled');
        $validated['is_demo'] = $request->boolean('is_demo');

        if ($request->has('symbol_map')) {
            $validated['symbol_map'] = SymbolMapper::parseMapInput((string) $request->input('symbol_map', ''));
        }

        try {
            $eaBridge->updateTerminalInstance($terminal->id, $validated);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['instance_key' => $e->getMessage()]);
        }

        return back()->with('status', 'Instance "'.$terminal->fresh()->label().'" updated.');
    }

    public function regenerateToken(Mt5EaTerminal $terminal, EaBridgeService $eaBridge)
    {
        $plainToken = $eaBridge->regenerateTerminalToken($terminal);

        return back()
            ->with('status', 'New API token generated for "'.$terminal->label().'". Update InpApiToken in MT5.')
            ->with('ea_instance_tokens', [
                $terminal->id => $plainToken,
            ])
            ->with('ea_open_credentials_terminal', $terminal->id);
    }

    public function revealToken(Mt5EaTerminal $terminal, EaBridgeService $eaBridge)
    {
        try {
            $plainToken = $eaBridge->revealTerminalToken($terminal);
        } catch (RuntimeException $e) {
            return back()->withErrors(['reveal_token' => $e->getMessage()]);
        }

        return back()
            ->with('status', 'API token shown for "'.$terminal->label().'".')
            ->with('ea_instance_tokens', [
                $terminal->id => $plainToken,
            ])
            ->with('ea_open_credentials_terminal', $terminal->id);
    }

    public function destroy(Mt5EaTerminal $terminal, EaBridgeService $eaBridge)
    {
        try {
            $label = $terminal->label();
            $eaBridge->deleteInstance($terminal);
        } catch (RuntimeException $e) {
            return back()->withErrors(['delete_instance' => $e->getMessage()]);
        }

        return redirect()
            ->route('ea-bridge.index')
            ->with('status', 'Instance "'.$label.'" deleted.');
    }

    public function testTrade(Request $request, Mt5EaTerminal $terminal, EaBridgeService $eaBridge)
    {
        $validated = $request->validate([
            'symbol' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'lot' => ['nullable', 'numeric', 'min:0.001', 'max:1'],
        ]);

        try {
            $command = $eaBridge->queueTestTrade(
                $terminal,
                strtoupper((string) ($validated['symbol'] ?? 'GBPUSD')),
                (float) ($validated['lot'] ?? 0.01)
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withErrors(['test_trade' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            'Test trade #'.$command->id.' queued for "'.$terminal->label().'" (BUY '.($validated['symbol'] ?? 'GBPUSD').' 0.01 lot).'
        );
    }

    public function queueCommand(Request $request, EaBridgeService $eaBridge)
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:BUY,SELL,CLOSE,CLOSE_ALL,MODIFY'],
            'symbol' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
            'lot' => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'sl' => ['nullable', 'numeric', 'min:0'],
            'tp' => ['nullable', 'numeric', 'min:0'],
            'ticket' => ['nullable', 'integer', 'min:1'],
            'mt5_instance_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ]);

        $validated['source'] = 'manual';

        try {
            $command = $eaBridge->queueCommand($validated);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back()->with('status', 'Command #'.$command->id.' queued for the EA.');
    }
}
