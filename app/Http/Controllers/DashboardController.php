<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\BotTradeLog;
use App\Models\Mt5EaTerminal;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $settings = AppSetting::singleton();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];
        $enabledProfiles = collect($profiles)->filter(static fn ($p) => ($p['enabled'] ?? true))->count();

        $terminals = Mt5EaTerminal::query()->where('enabled', true)->get();
        $onlineCount = $terminals->filter(static fn (Mt5EaTerminal $t) => $t->isOnline())->count();

        $recentAlerts = BotTradeLog::query()
            ->whereIn('event_type', ['guardrail', 'trade_open', 'trade_close'])
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        return view('dashboard', [
            'demoOnly' => (bool) $settings->demo_only,
            'enabledProfiles' => $enabledProfiles,
            'instanceCount' => $terminals->count(),
            'onlineCount' => $onlineCount,
            'recentAlerts' => $recentAlerts,
        ]);
    }
}
