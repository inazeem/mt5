<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\BotController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
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

    Route::get('/bot', [BotController::class, 'index'])->name('bot.index');
    Route::get('/bot/analytics', [BotController::class, 'analytics'])->name('bot.analytics');
    Route::get('/bot/analytics/export', [BotController::class, 'exportCsv'])->name('bot.analytics.export');
    Route::post('/bot/trade', [BotController::class, 'store'])->name('bot.trade');
    Route::post('/bot/close-position', [BotController::class, 'closePosition'])->name('bot.close-position');
    Route::get('/bot/price', [BotController::class, 'price'])->name('bot.price');

    Route::get('/ai', [AiController::class, 'index'])->name('ai.index');
    Route::post('/ai/ask', [AiController::class, 'ask'])->name('ai.ask');

    Route::delete('/tickers/bulk-delete', [TickerController::class, 'bulkDestroy'])->name('tickers.bulk-delete');
    Route::patch('/tickers/{ticker}/toggle-active', [TickerController::class, 'toggleActive'])->name('tickers.toggle-active');
    Route::resource('/tickers', TickerController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
});

require __DIR__.'/auth.php';
