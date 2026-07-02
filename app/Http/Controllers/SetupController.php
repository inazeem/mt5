<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Mt5EaTerminal;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function index(): View
    {
        $settings = AppSetting::singleton();
        $terminals = Mt5EaTerminal::query()->where('enabled', true)->get();
        $profiles = is_array($settings->bot_profiles) ? $settings->bot_profiles : [];

        return view('setup.index', [
            'demoOnly' => (bool) $settings->demo_only,
            'hasMetaApi' => trim((string) $settings->metaapi_token) !== '' && trim((string) $settings->metaapi_account_id) !== '',
            'instanceCount' => $terminals->count(),
            'onlineCount' => $terminals->filter(static fn (Mt5EaTerminal $t) => $t->isOnline())->count(),
            'profileCount' => count($profiles),
            'pollUrl' => url('/api/ea/poll'),
            'appUrl' => config('app.url'),
        ]);
    }
}
