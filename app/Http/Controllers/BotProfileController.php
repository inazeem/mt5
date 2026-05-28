<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BotProfileController extends Controller
{
    public function index()
    {
        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];

        return view('bot-profiles.index', compact('settings', 'profiles'));
    }

    public function create()
    {
        return view('bot-profiles.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'key'                    => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'name'                   => ['required', 'string', 'max:120'],
            'enabled'                => ['nullable', 'boolean'],
            'lot'                    => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'tp_pips'                => ['nullable', 'numeric', 'min:0.1'],
            'sl_pips'                => ['nullable', 'numeric', 'min:0.1'],
            'trail_start_pips'       => ['nullable', 'numeric', 'min:0.1'],
            'trail_pips'             => ['nullable', 'numeric', 'min:0.1'],
            'trail_tp_multiplier'    => ['nullable', 'numeric', 'min:1', 'max:10'],
            'min_move_pips'          => ['nullable', 'numeric', 'min:0.1'],
            'max_spread_pips'        => ['nullable', 'numeric', 'min:0.1'],
            'cooldown_minutes'       => ['nullable', 'integer', 'min:0'],
            'session_start_utc'      => ['nullable', 'integer', 'min:0', 'max:23'],
            'session_end_utc'        => ['nullable', 'integer', 'min:0', 'max:23'],
            'max_trades_per_day'     => ['nullable', 'integer', 'min:1'],
            'max_daily_loss_percent' => ['nullable', 'numeric', 'min:0.1'],
            'ai_confirm'             => ['nullable', 'boolean'],
            'ai_min_confidence'      => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_symbols'            => ['nullable', 'integer', 'min:1'],
            'max_open_positions'     => ['nullable', 'integer', 'min:1'],
            'max_per_cycle'          => ['nullable', 'integer', 'min:1'],
            'min_bot_score'          => ['nullable', 'integer', 'min:0', 'max:100'],
            'min_effective_volume'   => ['nullable', 'numeric', 'min:0.001'],
            'scalper'                => ['nullable', 'boolean'],
            'symbols'                => ['nullable', 'string'],
            'preferred_hours_utc'    => ['nullable', 'string'],
            'blocked_hours_utc'      => ['nullable', 'string'],
            'preferred_symbols'      => ['nullable', 'string'],
        ]);

        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];

        $key = strtolower(trim($validated['key']));
        if (collect($profiles)->pluck('key')->contains($key)) {
            throw ValidationException::withMessages([
                'key' => 'Bot key must be unique.',
            ]);
        }

        $symbols = $this->parseSymbolsCsv($validated['symbols'] ?? null);
        $preferredSymbols = $this->parseSymbolsCsv($validated['preferred_symbols'] ?? null);
        $preferredHoursUtc = $this->parseHoursCsv($validated['preferred_hours_utc'] ?? null);
        $blockedHoursUtc = $this->parseHoursCsv($validated['blocked_hours_utc'] ?? null);

        $newProfile = [
            'key' => $key,
            'name' => trim($validated['name']),
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'lot' => isset($validated['lot']) ? (float) $validated['lot'] : null,
            'tp_pips' => isset($validated['tp_pips']) ? (float) $validated['tp_pips'] : null,
            'sl_pips' => isset($validated['sl_pips']) ? (float) $validated['sl_pips'] : null,
            'trail_start_pips' => isset($validated['trail_start_pips']) ? (float) $validated['trail_start_pips'] : null,
            'trail_pips' => isset($validated['trail_pips']) ? (float) $validated['trail_pips'] : null,
            'trail_tp_multiplier' => isset($validated['trail_tp_multiplier']) ? (float) $validated['trail_tp_multiplier'] : null,
            'min_move_pips' => isset($validated['min_move_pips']) ? (float) $validated['min_move_pips'] : null,
            'max_spread_pips' => isset($validated['max_spread_pips']) ? (float) $validated['max_spread_pips'] : null,
            'cooldown_minutes' => isset($validated['cooldown_minutes']) ? (int) $validated['cooldown_minutes'] : null,
            'session_start_utc' => isset($validated['session_start_utc']) ? (int) $validated['session_start_utc'] : null,
            'session_end_utc' => isset($validated['session_end_utc']) ? (int) $validated['session_end_utc'] : null,
            'max_trades_per_day' => isset($validated['max_trades_per_day']) ? (int) $validated['max_trades_per_day'] : null,
            'max_daily_loss_percent' => isset($validated['max_daily_loss_percent']) ? (float) $validated['max_daily_loss_percent'] : null,
            'ai_confirm' => isset($validated['ai_confirm']) ? (bool) $validated['ai_confirm'] : null,
            'ai_min_confidence' => isset($validated['ai_min_confidence']) ? (int) $validated['ai_min_confidence'] : null,
            'max_symbols' => isset($validated['max_symbols']) ? (int) $validated['max_symbols'] : null,
            'max_open_positions' => isset($validated['max_open_positions']) ? (int) $validated['max_open_positions'] : null,
            'max_per_cycle' => isset($validated['max_per_cycle']) ? (int) $validated['max_per_cycle'] : null,
            'min_bot_score' => isset($validated['min_bot_score']) ? (int) $validated['min_bot_score'] : null,
            'min_effective_volume' => isset($validated['min_effective_volume']) ? (float) $validated['min_effective_volume'] : null,
            'scalper' => isset($validated['scalper']) ? (bool) $validated['scalper'] : null,
            'symbols' => !empty($symbols) ? $symbols : null,
            'preferred_symbols' => !empty($preferredSymbols) ? $preferredSymbols : null,
            'preferred_hours_utc' => !empty($preferredHoursUtc) ? $preferredHoursUtc : null,
            'blocked_hours_utc' => !empty($blockedHoursUtc) ? $blockedHoursUtc : null,
        ];

        $profiles[] = $newProfile;
        $settings->bot_profiles = $profiles;
        $settings->save();

        return redirect()->route('bot-profiles.index')->with('status', 'Bot profile created successfully.');
    }

    public function edit(string $key)
    {
        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];
        $profile = collect($profiles)->firstWhere('key', $key);

        if (!$profile) {
            abort(404, 'Bot profile not found.');
        }

        return view('bot-profiles.edit', compact('profile'));
    }

    public function update(Request $request, string $key)
    {
        $validated = $request->validate([
            'name'                   => ['required', 'string', 'max:120'],
            'enabled'                => ['nullable', 'boolean'],
            'lot'                    => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'tp_pips'                => ['nullable', 'numeric', 'min:0.1'],
            'sl_pips'                => ['nullable', 'numeric', 'min:0.1'],
            'trail_start_pips'       => ['nullable', 'numeric', 'min:0.1'],
            'trail_pips'             => ['nullable', 'numeric', 'min:0.1'],
            'trail_tp_multiplier'    => ['nullable', 'numeric', 'min:1', 'max:10'],
            'min_move_pips'          => ['nullable', 'numeric', 'min:0.1'],
            'max_spread_pips'        => ['nullable', 'numeric', 'min:0.1'],
            'cooldown_minutes'       => ['nullable', 'integer', 'min:0'],
            'session_start_utc'      => ['nullable', 'integer', 'min:0', 'max:23'],
            'session_end_utc'        => ['nullable', 'integer', 'min:0', 'max:23'],
            'max_trades_per_day'     => ['nullable', 'integer', 'min:1'],
            'max_daily_loss_percent' => ['nullable', 'numeric', 'min:0.1'],
            'ai_confirm'             => ['nullable', 'boolean'],
            'ai_min_confidence'      => ['nullable', 'integer', 'min:0', 'max:100'],
            'max_symbols'            => ['nullable', 'integer', 'min:1'],
            'max_open_positions'     => ['nullable', 'integer', 'min:1'],
            'max_per_cycle'          => ['nullable', 'integer', 'min:1'],
            'min_bot_score'          => ['nullable', 'integer', 'min:0', 'max:100'],
            'min_effective_volume'   => ['nullable', 'numeric', 'min:0.001'],
            'scalper'                => ['nullable', 'boolean'],
            'symbols'                => ['nullable', 'string'],
            'preferred_hours_utc'    => ['nullable', 'string'],
            'blocked_hours_utc'      => ['nullable', 'string'],
            'preferred_symbols'      => ['nullable', 'string'],
        ]);

        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];
        $profileIndex = collect($profiles)->search(static fn ($p) => $p['key'] === $key);

        if ($profileIndex === false) {
            abort(404, 'Bot profile not found.');
        }

        $symbols = $this->parseSymbolsCsv($validated['symbols'] ?? null);
        $preferredSymbols = $this->parseSymbolsCsv($validated['preferred_symbols'] ?? null);
        $preferredHoursUtc = $this->parseHoursCsv($validated['preferred_hours_utc'] ?? null);
        $blockedHoursUtc = $this->parseHoursCsv($validated['blocked_hours_utc'] ?? null);

        $profiles[$profileIndex] = array_merge($profiles[$profileIndex], [
            'name' => trim($validated['name']),
            'enabled' => (bool) ($validated['enabled'] ?? true),
            'lot' => isset($validated['lot']) ? (float) $validated['lot'] : null,
            'tp_pips' => isset($validated['tp_pips']) ? (float) $validated['tp_pips'] : null,
            'sl_pips' => isset($validated['sl_pips']) ? (float) $validated['sl_pips'] : null,
            'trail_start_pips' => isset($validated['trail_start_pips']) ? (float) $validated['trail_start_pips'] : null,
            'trail_pips' => isset($validated['trail_pips']) ? (float) $validated['trail_pips'] : null,
            'trail_tp_multiplier' => isset($validated['trail_tp_multiplier']) ? (float) $validated['trail_tp_multiplier'] : null,
            'min_move_pips' => isset($validated['min_move_pips']) ? (float) $validated['min_move_pips'] : null,
            'max_spread_pips' => isset($validated['max_spread_pips']) ? (float) $validated['max_spread_pips'] : null,
            'cooldown_minutes' => isset($validated['cooldown_minutes']) ? (int) $validated['cooldown_minutes'] : null,
            'session_start_utc' => isset($validated['session_start_utc']) ? (int) $validated['session_start_utc'] : null,
            'session_end_utc' => isset($validated['session_end_utc']) ? (int) $validated['session_end_utc'] : null,
            'max_trades_per_day' => isset($validated['max_trades_per_day']) ? (int) $validated['max_trades_per_day'] : null,
            'max_daily_loss_percent' => isset($validated['max_daily_loss_percent']) ? (float) $validated['max_daily_loss_percent'] : null,
            'ai_confirm' => isset($validated['ai_confirm']) ? (bool) $validated['ai_confirm'] : null,
            'ai_min_confidence' => isset($validated['ai_min_confidence']) ? (int) $validated['ai_min_confidence'] : null,
            'max_symbols' => isset($validated['max_symbols']) ? (int) $validated['max_symbols'] : null,
            'max_open_positions' => isset($validated['max_open_positions']) ? (int) $validated['max_open_positions'] : null,
            'max_per_cycle' => isset($validated['max_per_cycle']) ? (int) $validated['max_per_cycle'] : null,
            'min_bot_score' => isset($validated['min_bot_score']) ? (int) $validated['min_bot_score'] : null,
            'min_effective_volume' => isset($validated['min_effective_volume']) ? (float) $validated['min_effective_volume'] : null,
            'scalper' => isset($validated['scalper']) ? (bool) $validated['scalper'] : null,
            'symbols' => !empty($symbols) ? $symbols : null,
            'preferred_symbols' => !empty($preferredSymbols) ? $preferredSymbols : null,
            'preferred_hours_utc' => !empty($preferredHoursUtc) ? $preferredHoursUtc : null,
            'blocked_hours_utc' => !empty($blockedHoursUtc) ? $blockedHoursUtc : null,
        ]);

        $settings->bot_profiles = $profiles;
        $settings->save();

        return redirect()->route('bot-profiles.index')->with('status', 'Bot profile updated successfully.');
    }

    public function destroy(string $key)
    {
        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];
        $profiles = array_values(array_filter($profiles, static fn ($p) => ($p['key'] ?? '') !== $key));

        $settings->bot_profiles = !empty($profiles) ? $profiles : null;
        $settings->save();

        return redirect()->route('bot-profiles.index')->with('status', 'Bot profile deleted successfully.');
    }

    private function parseSymbolsCsv(?string $raw): array
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($symbol) => strtoupper(trim((string) $symbol)), explode(',', $text)),
            static fn ($symbol) => $symbol !== ''
        )));
    }

    private function parseHoursCsv(?string $raw): array
    {
        $text = trim((string) $raw);
        if ($text === '') {
            return [];
        }

        $hours = [];
        foreach (explode(',', $text) as $chunk) {
            $value = trim($chunk);
            if ($value === '' || !is_numeric($value)) {
                continue;
            }

            $hour = (int) $value;
            if ($hour < 0 || $hour > 23) {
                continue;
            }

            $hours[] = $hour;
        }

        $hours = array_values(array_unique($hours));
        sort($hours);

        return $hours;
    }
}
