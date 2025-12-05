<?php

namespace App\Services\Telegram;

use App\Models\TelegramUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramBotService
{
    protected string $token;
    protected string $apiUrl;

    public function __construct()
    {
        $this->token = config('telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}";
    }

    public function setWebhook(string $url): array
    {
        return $this->request('setWebhook', ['url' => $url]);
    }

    public function deleteWebhook(): array
    {
        return $this->request('deleteWebhook');
    }

    public function getWebhookInfo(): array
    {
        return $this->request('getWebhookInfo');
    }

    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        return $this->request('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ], $options));
    }

    public function sendMessageWithKeyboard(int $chatId, string $text, array $keyboard, bool $resize = true): array
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => $resize,
                'one_time_keyboard' => false,
            ]),
        ]);
    }

    public function sendMessageWithInlineKeyboard(int $chatId, string $text, array $keyboard): array
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => json_encode([
                'inline_keyboard' => $keyboard,
            ]),
        ]);
    }

    public function editMessage(int $chatId, int $messageId, string $text, array $keyboard = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
        }

        return $this->request('editMessageText', $params);
    }

    public function deleteMessage(int $chatId, int $messageId): array
    {
        return $this->request('deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): array
    {
        return $this->request('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $showAlert,
        ]);
    }

    public function sendPhoto(int $chatId, string $photo, string $caption = '', array $keyboard = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'photo' => $photo,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
        }

        return $this->request('sendPhoto', $params);
    }

    public function sendDocument(int $chatId, string $document, string $caption = ''): array
    {
        return $this->request('sendDocument', [
            'chat_id' => $chatId,
            'document' => $document,
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ]);
    }

    public function sendVoice(int $chatId, string $voice, string $caption = ''): array
    {
        return $this->request('sendVoice', [
            'chat_id' => $chatId,
            'voice' => $voice,
            'caption' => $caption,
        ]);
    }

    public function getFile(string $fileId): array
    {
        return $this->request('getFile', ['file_id' => $fileId]);
    }

    public function downloadFile(string $filePath): ?string
    {
        $url = "https://api.telegram.org/file/bot{$this->token}/{$filePath}";
        $response = Http::get($url);

        if ($response->successful()) {
            return $response->body();
        }

        return null;
    }

    public function sendChatAction(int $chatId, string $action = 'typing'): array
    {
        return $this->request('sendChatAction', [
            'chat_id' => $chatId,
            'action' => $action,
        ]);
    }

    public function removeKeyboard(int $chatId, string $text): array
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => json_encode(['remove_keyboard' => true]),
        ]);
    }

    protected function request(string $method, array $params = []): array
    {
        try {
            $response = Http::post("{$this->apiUrl}/{$method}", $params);
            $data = $response->json();

            if (!$response->successful() || !($data['ok'] ?? false)) {
                Log::channel('telegram')->error("Telegram API error: {$method}", [
                    'method' => $method,
                    'params' => $this->sanitizeParams($params),
                    'response' => $data,
                    'http_status' => $response->status(),
                ]);
            }

            return $data ?? ['ok' => false, 'error' => 'Empty response'];
        } catch (\Exception $e) {
            Log::channel('telegram')->error("Telegram API exception: {$method}", [
                'method' => $method,
                'params' => $this->sanitizeParams($params),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Remove sensitive data from params for logging
     */
    protected function sanitizeParams(array $params): array
    {
        // Don't log file contents
        $sanitized = $params;
        if (isset($sanitized['document']) && $sanitized['document'] instanceof \CURLFile) {
            $sanitized['document'] = '[FILE]';
        }
        if (isset($sanitized['photo']) && $sanitized['photo'] instanceof \CURLFile) {
            $sanitized['photo'] = '[FILE]';
        }
        return $sanitized;
    }

    // User management
    public function getOrCreateUser(array $from): TelegramUser
    {
        return TelegramUser::updateOrCreate(
            ['telegram_id' => $from['id']],
            [
                'username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name' => $from['last_name'] ?? null,
                'language_code' => $from['language_code'] ?? 'en',
            ]
        );
    }

    // Keyboard builders
    public function buildMainMenuKeyboard(): array
    {
        return [
            [['text' => '📋 Tasks'], ['text' => '💰 Finance']],
            [['text' => '📅 Calendar'], ['text' => '💳 Debts']],
            [['text' => '📊 Statistics'], ['text' => '🤖 AI Assistant']],
            [['text' => '⚙️ Settings']],
        ];
    }

    public function buildTasksKeyboard(): array
    {
        return [
            [['text' => '➕ Add Task'], ['text' => '📋 Today\'s Tasks']],
            [['text' => '📅 Week Tasks'], ['text' => '📆 Month Tasks']],
            [['text' => '🌅 Morning Plan'], ['text' => '🌙 Evening Summary']],
            [['text' => '🔙 Back to Menu']],
        ];
    }

    public function buildFinanceKeyboard(): array
    {
        return [
            [['text' => '💵 Add Income'], ['text' => '💸 Add Expense']],
            [['text' => '📊 Today Report'], ['text' => '📈 Month Report']],
            [['text' => '💱 Currency Rates'], ['text' => '📉 Analysis']],
            [['text' => '🔙 Back to Menu']],
        ];
    }

    public function buildDebtsKeyboard(): array
    {
        return [
            [['text' => '📤 I Gave Debt'], ['text' => '📥 I Received Debt']],
            [['text' => '📋 Active Debts'], ['text' => '⏰ Due Soon']],
            [['text' => '✅ Paid Debts'], ['text' => '📊 Debt Summary']],
            [['text' => '🔙 Back to Menu']],
        ];
    }

    public function buildCalendarKeyboard(): array
    {
        return [
            [['text' => '📅 Today'], ['text' => '📆 This Week']],
            [['text' => '🗓️ This Month'], ['text' => '📊 This Year']],
            [['text' => '🔍 Custom Range']],
            [['text' => '🔙 Back to Menu']],
        ];
    }

    public function buildSettingsKeyboard(): array
    {
        return [
            [['text' => '🔔 Notifications'], ['text' => '💱 Currency']],
            [['text' => '🌐 Language'], ['text' => '⏰ Time Zone']],
            [['text' => '📤 Export Data'], ['text' => '📥 Import Data']],
            [['text' => '🔙 Back to Menu']],
        ];
    }

    public function buildPriorityInlineKeyboard(string $prefix = 'priority'): array
    {
        return [
            [
                ['text' => '🔴 High', 'callback_data' => "{$prefix}:high"],
                ['text' => '🟡 Medium', 'callback_data' => "{$prefix}:medium"],
                ['text' => '🟢 Low', 'callback_data' => "{$prefix}:low"],
            ],
        ];
    }

    public function buildCategoryInlineKeyboard(array $categories, string $prefix = 'category'): array
    {
        $keyboard = [];
        $row = [];

        foreach ($categories as $key => $label) {
            $row[] = ['text' => $label, 'callback_data' => "{$prefix}:{$key}"];

            if (count($row) === 2) {
                $keyboard[] = $row;
                $row = [];
            }
        }

        if (!empty($row)) {
            $keyboard[] = $row;
        }

        return $keyboard;
    }

    public function buildConfirmKeyboard(string $prefix): array
    {
        return [
            [
                ['text' => '✅ Confirm', 'callback_data' => "{$prefix}:confirm"],
                ['text' => '❌ Cancel', 'callback_data' => "{$prefix}:cancel"],
            ],
        ];
    }

    public function buildRatingKeyboard(string $prefix = 'rating'): array
    {
        return [
            [
                ['text' => '⭐', 'callback_data' => "{$prefix}:1"],
                ['text' => '⭐⭐', 'callback_data' => "{$prefix}:2"],
                ['text' => '⭐⭐⭐', 'callback_data' => "{$prefix}:3"],
                ['text' => '⭐⭐⭐⭐', 'callback_data' => "{$prefix}:4"],
                ['text' => '⭐⭐⭐⭐⭐', 'callback_data' => "{$prefix}:5"],
            ],
        ];
    }

    public function buildYesNoKeyboard(string $prefix): array
    {
        return [
            [
                ['text' => '✅ Yes', 'callback_data' => "{$prefix}:yes"],
                ['text' => '❌ No', 'callback_data' => "{$prefix}:no"],
            ],
        ];
    }
}

