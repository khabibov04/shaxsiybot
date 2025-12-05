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

        Log::info('Telegram webhook received', ['update' => $update]);

        try {
            $this->handler->handle($update);
        } catch (\Exception $e) {
            Log::error('Telegram webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['ok' => true]);
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

        return response()->json($result);
    }

    /**
     * Delete the webhook
     */
    public function deleteWebhook(): JsonResponse
    {
        $result = $this->bot->deleteWebhook();

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
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'bot_configured' => !empty(config('telegram.bot_token')),
        ]);
    }
}

