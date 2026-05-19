<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\AiService;
use Illuminate\Http\Request;
use Throwable;

class AiController extends Controller
{
    public function index()
    {
        $settings = AppSetting::singleton();

        return view('ai.index', [
            'settings' => $settings,
            'answer' => null,
            'prompt' => null,
            'provider' => null,
        ]);
    }

    public function ask(Request $request, AiService $aiService)
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
        ]);

        $settings = AppSetting::singleton();

        try {
            $result = $aiService->ask($validated['prompt']);

            return view('ai.index', [
                'settings' => $settings,
                'answer' => $result['answer'],
                'prompt' => $validated['prompt'],
                'provider' => $result['provider'],
            ]);
        } catch (Throwable $e) {
            return redirect()->route('ai.index')
                ->withInput()
                ->withErrors(['ai' => $e->getMessage()]);
        }
    }
}
