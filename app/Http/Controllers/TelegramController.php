<?php

namespace App\Http\Controllers;

use App\Services\Telegram\MessageHandler;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TelegramController extends Controller
{
    protected TelegramBotService $bot;
    protected MessageHandler $handler;

    public function __construct(TelegramBotService $bot, MessageHandler $handler)
    {
        $this->bot = $bot;
        $this->handler = $handler;
    }

    /**
     * Handle incoming webhook from Telegram
     */
    public function webhook(Request $request): JsonResponse
    {
        $update = $request->all();

        // Log incoming request
        $this->logTelegram('info', 'Webhook received', [
            'update_id' => $update['update_id'] ?? null,
            'message_id' => $update['message']['message_id'] ?? $update['callback_query']['message']['message_id'] ?? null,
            'chat_id' => $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null,
            'from_id' => $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null,
            'text' => $update['message']['text'] ?? $update['callback_query']['data'] ?? null,
        ]);

        try {
            $this->handler->handle($update);
            
            $this->logTelegram('info', 'Webhook processed successfully', [
                'update_id' => $update['update_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Log detailed error
            $this->logTelegram('error', 'Webhook processing failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'update' => $update,
            ]);

            // Notify admin if configured
            $this->notifyAdminOnError($e, $update);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Log to telegram channel or fallback to default
     */
    protected function logTelegram(string $level, string $message, array $context = []): void
    {
        try {
            // Try telegram channel first
            if (config('logging.channels.telegram')) {
                Log::channel('telegram')->{$level}($message, $context);
            } else {
                Log::{$level}("[TELEGRAM] {$message}", $context);
            }
        } catch (\Exception $e) {
            // Fallback to default channel
            Log::{$level}("[TELEGRAM] {$message}", $context);
        }
    }

    /**
     * Notify admin about errors
     */
    protected function notifyAdminOnError(\Throwable $e, array $update): void
    {
        try {
            $adminIds = config('telegram.admin_ids', []);
            
            if (empty($adminIds)) {
                return;
            }

            $errorMessage = "🚨 <b>Bot Error!</b>\n\n" .
                "📍 <b>Error:</b> " . htmlspecialchars($e->getMessage()) . "\n" .
                "📁 <b>File:</b> " . basename($e->getFile()) . ":" . $e->getLine() . "\n" .
                "🕐 <b>Time:</b> " . now()->format('Y-m-d H:i:s') . "\n\n" .
                "📨 <b>Update ID:</b> " . ($update['update_id'] ?? 'N/A');

            foreach ($adminIds as $adminId) {
                if (!empty($adminId)) {
                    $this->bot->sendMessage((int)$adminId, $errorMessage);
                }
            }
        } catch (\Exception $notifyError) {
            $this->logTelegram('warning', 'Failed to notify admin', [
                'error' => $notifyError->getMessage(),
            ]);
        }
    }

    /**
     * Set the webhook URL
     */
    public function setWebhook(Request $request): JsonResponse
    {
        $url = $request->input('url') ?? config('telegram.webhook_url');

        if (empty($url)) {
            return response()->json([
                'ok' => false,
                'error' => 'Webhook URL is required',
            ], 400);
        }

        $result = $this->bot->setWebhook($url);
        
        $this->logTelegram('info', 'Webhook set', ['url' => $url, 'result' => $result]);

        return response()->json($result);
    }

    /**
     * Delete the webhook
     */
    public function deleteWebhook(): JsonResponse
    {
        $result = $this->bot->deleteWebhook();
        
        $this->logTelegram('info', 'Webhook deleted', ['result' => $result]);

        return response()->json($result);
    }

    /**
     * Get webhook info
     */
    public function getWebhookInfo(): JsonResponse
    {
        $result = $this->bot->getWebhookInfo();

        return response()->json($result);
    }

    /**
     * Health check endpoint
     */
    public function health(): JsonResponse
    {
        $this->logTelegram('info', 'Health check accessed');
        
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'bot_configured' => !empty(config('telegram.bot_token')),
        ]);
    }
}
