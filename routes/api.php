<?php

use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Telegram Webhook
Route::prefix('telegram')->group(function () {
    // Main webhook endpoint
    Route::post('/webhook', [TelegramController::class, 'webhook'])->name('telegram.webhook');

    // Webhook management (should be protected in production)
    Route::post('/set-webhook', [TelegramController::class, 'setWebhook'])->name('telegram.set-webhook');
    Route::post('/delete-webhook', [TelegramController::class, 'deleteWebhook'])->name('telegram.delete-webhook');
    Route::get('/webhook-info', [TelegramController::class, 'getWebhookInfo'])->name('telegram.webhook-info');

    // Health check
    Route::get('/health', [TelegramController::class, 'health'])->name('telegram.health');
});

