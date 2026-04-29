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
        ]);

        $validated['demo_only'] = $request->boolean('demo_only');

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
