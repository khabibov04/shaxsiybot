<?php

use App\Http\Controllers\TelegramController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;

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

    // Debug endpoint - REMOVE IN PRODUCTION
    Route::get('/debug', function () {
        $debugInfo = [
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'bot_token_set' => !empty(config('telegram.bot_token')),
            'webhook_url' => config('telegram.webhook_url'),
            'db_connection' => config('database.default'),
            'log_channel' => config('logging.default'),
            'storage_writable' => is_writable(storage_path('logs')),
            'env' => [
                'APP_ENV' => env('APP_ENV'),
                'APP_DEBUG' => env('APP_DEBUG'),
                'DB_CONNECTION' => env('DB_CONNECTION'),
            ],
        ];

        // Test logging
        try {
            Log::info('Debug endpoint accessed', ['time' => now()]);
            Log::channel('telegram')->info('Telegram debug test', ['time' => now()]);
            $debugInfo['log_test'] = 'success';
        } catch (\Exception $e) {
            $debugInfo['log_test'] = 'failed: ' . $e->getMessage();
        }

        // Test database
        try {
            \DB::connection()->getPdo();
            $debugInfo['db_test'] = 'connected';
        } catch (\Exception $e) {
            $debugInfo['db_test'] = 'failed: ' . $e->getMessage();
        }

        return response()->json($debugInfo, 200, [], JSON_PRETTY_PRINT);
    });
});

