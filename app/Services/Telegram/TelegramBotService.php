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

    public function sendDocument(int $chatId, $document, string $caption = ''): array
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
                Log::error("Telegram API xatolik: {$method}", [
                    'method' => $method,
                    'params' => $this->sanitizeParams($params),
                    'response' => $data,
                    'http_status' => $response->status(),
                ]);
            }

            return $data ?? ['ok' => false, 'error' => 'Bo\'sh javob'];
        } catch (\Exception $e) {
            Log::error("Telegram API istisno: {$method}", [
                'method' => $method,
                'params' => $this->sanitizeParams($params),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    protected function sanitizeParams(array $params): array
    {
        $sanitized = $params;
        if (isset($sanitized['document']) && $sanitized['document'] instanceof \CURLFile) {
            $sanitized['document'] = '[FAYL]';
        }
        if (isset($sanitized['photo']) && $sanitized['photo'] instanceof \CURLFile) {
            $sanitized['photo'] = '[FAYL]';
        }
        return $sanitized;
    }

    // Foydalanuvchi boshqaruvi
    public function getOrCreateUser(array $from): TelegramUser
    {
        return TelegramUser::updateOrCreate(
            ['telegram_id' => $from['id']],
            [
                'username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name' => $from['last_name'] ?? null,
                'language_code' => $from['language_code'] ?? 'uz',
            ]
        );
    }

    // Klaviatura yaratuvchilar - O'ZBEK TILIDA
    public function buildMainMenuKeyboard(): array
    {
        return [
            [['text' => '📋 Vazifalar'], ['text' => '💰 Moliya']],
            [['text' => '📅 Taqvim'], ['text' => '💳 Qarzlar']],
            [['text' => '📊 Statistika'], ['text' => '🤖 AI Yordamchi']],
            [['text' => '⚙️ Sozlamalar']],
        ];
    }

    public function buildTasksKeyboard(): array
    {
        return [
            [['text' => '➕ Vazifa qo\'shish'], ['text' => '📋 Bugungi vazifalar']],
            [['text' => '📅 Haftalik'], ['text' => '📆 Oylik']],
            [['text' => '🌅 Ertalabki reja'], ['text' => '🌙 Kechki xulosa']],
            [['text' => '🔙 Orqaga']],
        ];
    }

    public function buildFinanceKeyboard(): array
    {
        return [
            [['text' => '💵 Daromad qo\'shish'], ['text' => '💸 Xarajat qo\'shish']],
            [['text' => '📊 Bugungi hisobot'], ['text' => '📈 Oylik hisobot']],
            [['text' => '💱 Valyuta kursi'], ['text' => '📉 Tahlil']],
            [['text' => '🔙 Orqaga']],
        ];
    }

    public function buildDebtsKeyboard(): array
    {
        return [
            [['text' => '📤 Qarz berdim'], ['text' => '📥 Qarz oldim']],
            [['text' => '📋 Faol qarzlar'], ['text' => '⏰ Muddati yaqin']],
            [['text' => '✅ To\'langan'], ['text' => '📊 Qarz xulosasi']],
            [['text' => '🔙 Orqaga']],
        ];
    }

    public function buildCalendarKeyboard(): array
    {
        return [
            [['text' => '📅 Bugun'], ['text' => '📆 Shu hafta']],
            [['text' => '🗓️ Shu oy'], ['text' => '📊 Shu yil']],
            [['text' => '🔍 Maxsus oraliq']],
            [['text' => '🔙 Orqaga']],
        ];
    }

    public function buildSettingsKeyboard(): array
    {
        return [
            [['text' => '🔔 Bildirishnomalar'], ['text' => '💱 Valyuta']],
            [['text' => '🌐 Til'], ['text' => '⏰ Vaqt zonasi']],
            [['text' => '📤 Eksport'], ['text' => '📥 Import']],
            [['text' => '🔙 Orqaga']],
        ];
    }

    public function buildPriorityInlineKeyboard(string $prefix = 'priority'): array
    {
        return [
            [
                ['text' => '🔴 Yuqori', 'callback_data' => "{$prefix}:high"],
                ['text' => '🟡 O\'rta', 'callback_data' => "{$prefix}:medium"],
                ['text' => '🟢 Past', 'callback_data' => "{$prefix}:low"],
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
                ['text' => '✅ Tasdiqlash', 'callback_data' => "{$prefix}:confirm"],
                ['text' => '❌ Bekor qilish', 'callback_data' => "{$prefix}:cancel"],
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
                ['text' => '✅ Ha', 'callback_data' => "{$prefix}:yes"],
                ['text' => '❌ Yo\'q', 'callback_data' => "{$prefix}:no"],
            ],
        ];
    }
}
