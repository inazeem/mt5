<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mt5EaTerminal;
use App\Services\EaBridgeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class EaBridgeController extends Controller
{
    public function poll(Request $request, EaBridgeService $eaBridge)
    {
        $validated = $request->validate([
            'login' => ['required', 'integer', 'min:1'],
            'server' => ['nullable', 'string', 'max:255'],
            'terminal_name' => ['nullable', 'string', 'max:255'],
            'broker_company' => ['nullable', 'string', 'max:255'],
            'balance' => ['nullable', 'numeric'],
            'equity' => ['nullable', 'numeric'],
            'margin' => ['nullable', 'numeric'],
            'free_margin' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:12'],
            'instance_key' => ['nullable', 'string', 'max:100'],
            'trade_allowed' => ['nullable', 'boolean'],
            'positions' => ['nullable', 'array'],
            'quotes' => ['nullable', 'array'],
            'candles' => ['nullable', 'array'],
            'command_result' => ['nullable', 'array'],
            'command_result.id' => ['nullable', 'integer', 'min:1'],
            'command_result.ok' => ['nullable', 'boolean'],
            'command_result.message' => ['nullable', 'string', 'max:2000'],
            'command_result.ticket' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            /** @var Mt5EaTerminal $terminal */
            $terminal = $request->attributes->get('ea_terminal');
            $response = DB::transaction(static fn () => $eaBridge->handlePoll($terminal, $validated));

            return response()->json($response);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'error' => 'Server error',
            ], 500);
        }
    }
}
