<?php

use App\Http\Controllers\Api\IntelligenceController;
use Illuminate\Support\Facades\Route;

Route::prefix('intelligence')->middleware('throttle:30,1,intelligence-general:')->group(function () {
    Route::post('assistant', [IntelligenceController::class, 'assistant'])->middleware('throttle:15,1,intelligence-assistant:');
    Route::get('inventory', [IntelligenceController::class, 'inventory']);
    Route::get('settings', [IntelligenceController::class, 'settings']);
    Route::put('settings', [IntelligenceController::class, 'saveSettings'])->middleware('verified');
    Route::post('reorder-reviews', [IntelligenceController::class, 'review'])->middleware('verified');
});
