<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\BotProfileController;
use App\Http\Controllers\EaBridgeWebController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StrategyController;
use App\Http\Controllers\TickerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'owner'])->name('dashboard');

Route::middleware(['auth', 'owner'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::get('/strategies', [StrategyController::class, 'edit'])->name('strategies.edit');
    Route::put('/strategies', [StrategyController::class, 'update'])->name('strategies.update');

    Route::get('/bot', [BotController::class, 'index'])->name('bot.index');
    Route::get('/bot/analytics', [BotController::class, 'analytics'])->name('bot.analytics');
    Route::get('/bot/analytics/live', [BotController::class, 'analyticsLive'])->name('bot.analytics.live');
    Route::get('/bot/health', [BotController::class, 'health'])->name('bot.health');
    Route::get('/bot/alerts', [BotController::class, 'alerts'])->name('bot.alerts');
    Route::post('/bot/alerts/clear', [BotController::class, 'clearAlerts'])->name('bot.alerts.clear');
    Route::get('/bot/analytics/export', [BotController::class, 'exportCsv'])->name('bot.analytics.export');
    Route::get('/bot/alerts/export', [BotController::class, 'exportCsv'])->name('bot.alerts.export');
    Route::post('/bot/auto-settings', [BotController::class, 'updateAutoSettings'])->name('bot.auto-settings');
    Route::get('/ea-bridge', [EaBridgeWebController::class, 'index'])->name('ea-bridge.index');
    Route::post('/ea-bridge/instances', [EaBridgeWebController::class, 'store'])->name('ea-bridge.instances.store');
    Route::post('/ea-bridge/terminals/{terminal}', [EaBridgeWebController::class, 'updateTerminal'])->name('ea-bridge.terminals.update');
    Route::post('/ea-bridge/terminals/{terminal}/token', [EaBridgeWebController::class, 'regenerateToken'])->name('ea-bridge.terminals.token');
    Route::post('/ea-bridge/terminals/{terminal}/reveal-token', [EaBridgeWebController::class, 'revealToken'])->name('ea-bridge.terminals.reveal-token');
    Route::post('/ea-bridge/terminals/{terminal}/test-trade', [EaBridgeWebController::class, 'testTrade'])->name('ea-bridge.terminals.test-trade');
    Route::delete('/ea-bridge/terminals/{terminal}', [EaBridgeWebController::class, 'destroy'])->name('ea-bridge.terminals.destroy');
    Route::post('/ea-bridge/commands', [EaBridgeWebController::class, 'queueCommand'])->name('ea-bridge.commands');

    Route::post('/bot/trade', [BotController::class, 'store'])->name('bot.trade');
    Route::post('/bot/close-position', [BotController::class, 'closePosition'])->name('bot.close-position');
    Route::get('/bot/price', [BotController::class, 'price'])->name('bot.price');

    Route::resource('/bot-profiles', BotProfileController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);

    Route::get('/ai', [AiController::class, 'index'])->name('ai.index');
    Route::post('/ai/ask', [AiController::class, 'ask'])->name('ai.ask');

    Route::delete('/tickers/bulk-delete', [TickerController::class, 'bulkDestroy'])->name('tickers.bulk-delete');
    Route::patch('/tickers/{ticker}/toggle-active', [TickerController::class, 'toggleActive'])->name('tickers.toggle-active');
    Route::resource('/tickers', TickerController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
});

require __DIR__.'/auth.php';
