<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function edit()
    {
        $settings = AppSetting::singleton();

        return view('settings.edit', compact('settings'));
    }

    public function update(Request $request)
    {
        $settings = AppSetting::singleton();

        $validated = $request->validate([
            'owner_email'         => ['nullable', 'email'],
            'demo_only'           => ['nullable', 'boolean'],
            'mt5_server'          => ['nullable', 'string', 'max:255'],
            'mt5_port'            => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mt5_manager_login'   => ['nullable', 'regex:/^\d+$/', 'max:255'],
            'mt5_manager_password'=> ['nullable', 'string', 'max:255'],
            'mt5_account_login'   => ['nullable', 'regex:/^\d+$/', 'max:255'],
            'mt5_action_deal'     => ['nullable', 'integer', 'min:0', 'max:10'],
            'mt5_volume_multiplier' => ['nullable', 'integer', 'min:1'],
            'ai_provider'         => ['required', 'in:claude,perplexity'],
            'claude_api_key'      => ['nullable', 'string', 'max:255'],
            'claude_model'        => ['nullable', 'string', 'max:255'],
            'perplexity_api_key'  => ['nullable', 'string', 'max:255'],
            'perplexity_model'    => ['nullable', 'string', 'max:255'],
            'metaapi_token'       => ['nullable', 'string', 'max:4096'],
            'metaapi_account_id'  => ['nullable', 'string', 'max:255'],
            'metaapi_region'      => ['nullable', 'string', 'max:100'],
            // Auto-bot
            'bot_lot'                  => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'bot_tp_pips'              => ['nullable', 'numeric', 'min:0.1'],
            'bot_sl_pips'              => ['nullable', 'numeric', 'min:0.1'],
            'bot_trail_start_pips'     => ['nullable', 'numeric', 'min:0.1'],
            'bot_trail_pips'           => ['nullable', 'numeric', 'min:0.1'],
            'bot_min_move_pips'        => ['nullable', 'numeric', 'min:0.1'],
            'bot_max_spread_pips'      => ['nullable', 'numeric', 'min:0.1'],
            'bot_cooldown_minutes'     => ['nullable', 'integer', 'min:0'],
            'bot_session_start_utc'    => ['nullable', 'integer', 'min:0', 'max:23'],
            'bot_session_end_utc'      => ['nullable', 'integer', 'min:0', 'max:23'],
            'bot_max_trades_per_day'   => ['nullable', 'integer', 'min:1'],
            'bot_max_daily_loss_percent' => ['nullable', 'numeric', 'min:0.1'],
            'bot_ai_confirm'           => ['nullable', 'boolean'],
            'bot_max_symbols'          => ['nullable', 'integer', 'min:1'],
            'bot_ai_min_confidence'    => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $validated['demo_only'] = $request->boolean('demo_only');
        $validated['bot_ai_confirm'] = $request->boolean('bot_ai_confirm');

        foreach (['mt5_manager_password', 'claude_api_key', 'perplexity_api_key', 'metaapi_token'] as $secretField) {
            if (empty($validated[$secretField])) {
                unset($validated[$secretField]);
            }
        }

        $settings->fill($validated);
        $settings->save();

        return redirect()->route('settings.edit')->with('status', 'Settings saved.');
    }
}
