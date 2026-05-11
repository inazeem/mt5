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
            'bot_trail_tp_multiplier'  => ['nullable', 'numeric', 'min:1', 'max:10'],
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
            'bot_profiles'             => ['nullable', 'string'],
        ]);

        $validated['demo_only'] = $request->boolean('demo_only');
        $validated['bot_ai_confirm'] = $request->boolean('bot_ai_confirm');
        $validated['bot_profiles'] = $this->normalizeBotProfiles($validated['bot_profiles'] ?? null);

        foreach (['mt5_manager_password', 'claude_api_key', 'perplexity_api_key', 'metaapi_token'] as $secretField) {
            if (empty($validated[$secretField])) {
                unset($validated[$secretField]);
            }
        }

        $settings->fill($validated);
        $settings->save();

        return redirect()->route('settings.edit')->with('status', 'Settings saved.');
    }

    private function normalizeBotProfiles(?string $rawProfiles): ?array
    {
        if ($rawProfiles === null || trim($rawProfiles) === '') {
            return null;
        }

        try {
            $decoded = json_decode($rawProfiles, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bot_profiles' => 'Bot profiles must be valid JSON: '.$e->getMessage(),
            ]);
        }

        if (!is_array($decoded)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bot_profiles' => 'Bot profiles JSON must be an array of profile objects.',
            ]);
        }

        $profiles = [];
        foreach ($decoded as $index => $profile) {
            if (!is_array($profile)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'bot_profiles' => 'Each bot profile must be an object. Invalid entry at index '.$index.'.',
                ]);
            }

            $name = trim((string) ($profile['name'] ?? ''));
            if ($name === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'bot_profiles' => 'Each bot profile requires a non-empty name. Invalid entry at index '.$index.'.',
                ]);
            }

            $keyRaw = (string) ($profile['key'] ?? $name);
            $key = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_-]/', '_', $keyRaw)));
            $key = trim($key, '_');
            if ($key === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'bot_profiles' => 'Each bot profile key must contain letters, numbers, dashes, or underscores. Invalid entry at index '.$index.'.',
                ]);
            }

            $profiles[] = [
                'key' => $key,
                'name' => $name,
                'enabled' => (bool) ($profile['enabled'] ?? true),
                'lot' => isset($profile['lot']) ? (float) $profile['lot'] : null,
                'tp_pips' => isset($profile['tp_pips']) ? (float) $profile['tp_pips'] : null,
                'sl_pips' => isset($profile['sl_pips']) ? (float) $profile['sl_pips'] : null,
                'trail_start_pips' => isset($profile['trail_start_pips']) ? (float) $profile['trail_start_pips'] : null,
                'trail_pips' => isset($profile['trail_pips']) ? (float) $profile['trail_pips'] : null,
                'trail_tp_multiplier' => isset($profile['trail_tp_multiplier']) ? (float) $profile['trail_tp_multiplier'] : null,
                'min_move_pips' => isset($profile['min_move_pips']) ? (float) $profile['min_move_pips'] : null,
                'max_spread_pips' => isset($profile['max_spread_pips']) ? (float) $profile['max_spread_pips'] : null,
                'cooldown_minutes' => isset($profile['cooldown_minutes']) ? (int) $profile['cooldown_minutes'] : null,
                'session_start_utc' => isset($profile['session_start_utc']) ? (int) $profile['session_start_utc'] : null,
                'session_end_utc' => isset($profile['session_end_utc']) ? (int) $profile['session_end_utc'] : null,
                'max_trades_per_day' => isset($profile['max_trades_per_day']) ? (int) $profile['max_trades_per_day'] : null,
                'max_daily_loss_percent' => isset($profile['max_daily_loss_percent']) ? (float) $profile['max_daily_loss_percent'] : null,
                'ai_confirm' => isset($profile['ai_confirm']) ? (bool) $profile['ai_confirm'] : null,
                'ai_min_confidence' => isset($profile['ai_min_confidence']) ? (int) $profile['ai_min_confidence'] : null,
                'max_symbols' => isset($profile['max_symbols']) ? (int) $profile['max_symbols'] : null,
                'max_open_positions' => isset($profile['max_open_positions']) ? (int) $profile['max_open_positions'] : null,
                'max_per_cycle' => isset($profile['max_per_cycle']) ? (int) $profile['max_per_cycle'] : null,
                'min_bot_score' => isset($profile['min_bot_score']) ? (int) $profile['min_bot_score'] : null,
                'min_effective_volume' => isset($profile['min_effective_volume']) ? (float) $profile['min_effective_volume'] : null,
                'scalper' => isset($profile['scalper']) ? (bool) $profile['scalper'] : null,
                'symbols' => isset($profile['symbols']) && is_array($profile['symbols'])
                    ? array_values(array_filter(array_map(static fn ($s) => strtoupper(trim((string) $s)), $profile['symbols']), static fn ($s) => $s !== ''))
                    : null,
            ];
        }

        $uniqueKeys = array_unique(array_column($profiles, 'key'));
        if (count($uniqueKeys) !== count($profiles)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'bot_profiles' => 'Bot profile keys must be unique.',
            ]);
        }

        return $profiles;
    }
}
