<?php

use App\Http\Controllers\Api\EaBridgeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['ea.bridge'])->prefix('ea')->group(function () {
    Route::post('/poll', [EaBridgeController::class, 'poll']);
});
