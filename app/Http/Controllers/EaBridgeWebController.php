<?php

namespace App\Http\Controllers;

use App\Models\Mt5EaCommand;
use App\Models\Mt5EaTerminal;
use App\Services\EaBridgeService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EaBridgeWebController extends Controller
{
    public function index(EaBridgeService $eaBridge)
    {
        $token = $eaBridge->resolveToken();
        $terminals = Mt5EaTerminal::query()
            ->orderByDesc('last_seen_at')
            ->get();
        $recentCommands = Mt5EaCommand::query()
            ->with('terminal')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return view('ea-bridge.index', compact('token', 'terminals', 'recentCommands'));
    }

    public function updateTerminal(Request $request, Mt5EaTerminal $terminal, EaBridgeService $eaBridge)
    {
        $validated = $request->validate([
            'instance_key' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'display_name' => ['nullable', 'string', 'max:120'],
            'enabled' => ['nullable', 'boolean'],
            'is_demo' => ['nullable', 'boolean'],
        ]);

        $validated['enabled'] = $request->boolean('enabled');
        $validated['is_demo'] = $request->boolean('is_demo');

        try {
            $eaBridge->updateTerminalInstance($terminal->id, $validated);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['instance_key' => $e->getMessage()]);
        }

        return back()->with('status', 'MT5 instance "'.$terminal->fresh()->label().'" updated.');
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
            'account_login' => ['nullable', 'integer', 'min:1'],
            'mt5_instance_key' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ]);

        try {
            $command = $eaBridge->queueCommand($validated);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['action' => $e->getMessage()]);
        }

        return back()->with('status', 'Command #'.$command->id.' queued for the EA.');
    }

    public function regenerateToken(EaBridgeService $eaBridge)
    {
        $eaBridge->regenerateToken();

        return back()->with('status', 'EA bridge token regenerated. Update the token in MT5.');
    }
}
