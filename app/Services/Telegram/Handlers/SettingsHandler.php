<?php

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramUser;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Storage;

class SettingsHandler
{
    protected TelegramBotService $bot;

    public function __construct(TelegramBotService $bot)
    {
        $this->bot = $bot;
    }

    public function showSettings(TelegramUser $user): void
    {
        $notifStatus = $user->notifications_enabled ? '✅ Yoqilgan' : '❌ O\'chirilgan';
        $languages = ['uz' => "🇺🇿 O'zbek", 'ru' => '🇷🇺 Русский', 'en' => '🇬🇧 English'];
        $langLabel = $languages[$user->language] ?? "🇺🇿 O'zbek";

        $message = "⚙️ <b>Sozlamalar</b>\n\n";
        $message .= "🔔 Bildirishnomalar: {$notifStatus}\n";
        $message .= "💱 Valyuta: {$user->currency}\n";
        $message .= "🌐 Til: {$langLabel}\n";
        $message .= "⏰ Vaqt zonasi: {$user->timezone}\n\n";

        if ($user->daily_budget_limit) {
            $message .= "📅 Kunlik byudjet: " . number_format($user->daily_budget_limit, 0, '.', ' ') . " so'm\n";
        }
        if ($user->monthly_budget_limit) {
            $message .= "📆 Oylik byudjet: " . number_format($user->monthly_budget_limit, 0, '.', ' ') . " so'm\n";
        }

        $this->bot->sendMessageWithKeyboard(
            $user->telegram_id,
            $message,
            $this->bot->buildSettingsKeyboard()
        );
    }

    public function showNotificationSettings(TelegramUser $user): void
    {
        $message = "🔔 <b>Bildirishnoma sozlamalari</b>\n\n";
        
        $notifTypes = [
            'notifications_enabled' => ['label' => 'Asosiy bildirishnomalar', 'value' => $user->notifications_enabled],
            'morning_reminder' => ['label' => 'Ertalabki eslatma', 'value' => $user->morning_reminder],
            'evening_reminder' => ['label' => 'Kechki xulosa', 'value' => $user->evening_reminder],
            'budget_alerts' => ['label' => 'Byudjet ogohlantirishlari', 'value' => $user->budget_alerts],
        ];

        foreach ($notifTypes as $key => $notif) {
            $status = $notif['value'] ? '✅' : '❌';
            $message .= "{$status} {$notif['label']}\n";
        }

        $keyboard = [
            [
                ['text' => ($user->notifications_enabled ? '❌' : '✅') . ' Asosiy', 'callback_data' => 'toggle_notif:notifications_enabled'],
            ],
            [
                ['text' => ($user->morning_reminder ? '❌' : '✅') . ' Ertalabki', 'callback_data' => 'toggle_notif:morning_reminder'],
                ['text' => ($user->evening_reminder ? '❌' : '✅') . ' Kechki', 'callback_data' => 'toggle_notif:evening_reminder'],
            ],
            [
                ['text' => ($user->budget_alerts ? '❌' : '✅') . ' Byudjet', 'callback_data' => 'toggle_notif:budget_alerts'],
            ],
            [
                ['text' => '🔙 Orqaga', 'callback_data' => 'settings_back'],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showCurrencySettings(TelegramUser $user): void
    {
        $message = "💱 <b>Valyuta sozlamalari</b>\n\n";
        $message .= "Joriy valyuta: <b>{$user->currency}</b>\n\n";
        $message .= "Asosiy valyutani tanlang:";

        $currencies = [
            'UZS' => "🇺🇿 So'm (UZS)",
            'USD' => '🇺🇸 Dollar (USD)',
            'EUR' => '🇪🇺 Yevro (EUR)',
            'RUB' => '🇷🇺 Rubl (RUB)',
        ];

        $keyboard = [];
        foreach ($currencies as $code => $label) {
            $current = $user->currency === $code ? ' ✓' : '';
            $keyboard[] = [['text' => $label . $current, 'callback_data' => "set_currency:{$code}"]];
        }
        $keyboard[] = [['text' => '🔙 Orqaga', 'callback_data' => 'settings_back']];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showLanguageSettings(TelegramUser $user): void
    {
        $message = "🌐 <b>Til sozlamalari</b>\n\n";
        
        $languages = [
            'uz' => "🇺🇿 O'zbek tili",
            'ru' => '🇷🇺 Русский язык',
            'en' => '🇬🇧 English',
        ];

        $currentLang = $languages[$user->language] ?? "🇺🇿 O'zbek tili";
        $message .= "Joriy til: <b>{$currentLang}</b>\n\n";
        $message .= "Tilni tanlang:";

        $keyboard = [];
        foreach ($languages as $code => $label) {
            $current = $user->language === $code ? ' ✓' : '';
            $keyboard[] = [['text' => $label . $current, 'callback_data' => "set_language:{$code}"]];
        }
        $keyboard[] = [['text' => '🔙 Orqaga', 'callback_data' => 'settings_back']];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showTimezoneSettings(TelegramUser $user): void
    {
        $message = "⏰ <b>Vaqt zonasi sozlamalari</b>\n\n";
        $message .= "Joriy vaqt zonasi: <b>{$user->timezone}</b>\n\n";
        $message .= "Vaqt zonasini tanlang:";

        $timezones = [
            'Asia/Tashkent' => '🇺🇿 Toshkent (UTC+5)',
            'Europe/Moscow' => '🇷🇺 Moskva (UTC+3)',
            'Asia/Dubai' => '🇦🇪 Dubay (UTC+4)',
            'Asia/Almaty' => '🇰🇿 Olmaota (UTC+6)',
            'Europe/London' => '🇬🇧 London (UTC+0)',
        ];

        $keyboard = [];
        foreach ($timezones as $tz => $label) {
            $current = $user->timezone === $tz ? ' ✓' : '';
            $keyboard[] = [['text' => $label . $current, 'callback_data' => "set_timezone:{$tz}"]];
        }
        $keyboard[] = [['text' => '🔙 Orqaga', 'callback_data' => 'settings_back']];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function setCurrency(TelegramUser $user, string $currency, ?int $messageId): void
    {
        $user->currency = $currency;
        $user->save();

        $currencies = [
            'UZS' => "🇺🇿 So'm",
            'USD' => '🇺🇸 Dollar',
            'EUR' => '🇪🇺 Yevro',
            'RUB' => '🇷🇺 Rubl',
        ];

        $message = "✅ Valyuta o'zgartirildi: <b>{$currencies[$currency]}</b>";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function setLanguage(TelegramUser $user, string $language, ?int $messageId): void
    {
        $user->language = $language;
        $user->save();

        $languages = [
            'uz' => "🇺🇿 O'zbek tili",
            'ru' => '🇷🇺 Русский язык',
            'en' => '🇬🇧 English',
        ];

        $message = "✅ Til o'zgartirildi: <b>{$languages[$language]}</b>";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function setTimezone(TelegramUser $user, string $timezone, ?int $messageId): void
    {
        $user->timezone = $timezone;
        $user->save();

        $message = "✅ Vaqt zonasi o'zgartirildi: <b>{$timezone}</b>";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function toggleNotification(TelegramUser $user, string $type, ?int $messageId): void
    {
        $validTypes = ['notifications_enabled', 'morning_reminder', 'evening_reminder', 'budget_alerts'];
        
        if (!in_array($type, $validTypes)) {
            return;
        }

        $user->$type = !$user->$type;
        $user->save();

        $labels = [
            'notifications_enabled' => 'Asosiy bildirishnomalar',
            'morning_reminder' => 'Ertalabki eslatma',
            'evening_reminder' => 'Kechki xulosa',
            'budget_alerts' => 'Byudjet ogohlantirishlari',
        ];

        $status = $user->$type ? 'yoqildi ✅' : 'o\'chirildi ❌';
        $message = "🔔 {$labels[$type]} {$status}";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }

        // Refresh notification settings view
        $this->showNotificationSettings($user);
    }

    public function exportData(TelegramUser $user): void
    {
        $this->bot->sendChatAction($user->telegram_id, 'upload_document');

        $data = $this->prepareExportData($user);

        // Generate CSV
        $csv = $this->generateCSV($data);

        // Save temporarily
        $filename = "export_{$user->telegram_id}_" . now()->format('Y-m-d_H-i-s') . ".csv";
        $path = storage_path("app/exports/{$filename}");
        
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        
        file_put_contents($path, $csv);

        // Send file
        $this->bot->sendDocument(
            $user->telegram_id,
            new \CURLFile($path, 'text/csv', $filename),
            "📤 Ma'lumotlar eksporti\n📅 " . now()->format('d.m.Y H:i')
        );

        // Cleanup
        unlink($path);
    }

    public function startImport(TelegramUser $user): void
    {
        $user->setState('importing_data');

        $this->bot->sendMessage(
            $user->telegram_id,
            "📥 <b>Ma'lumotlarni import qilish</b>\n\n" .
            "CSV faylni yuboring.\n\n" .
            "Fayl formati:\n" .
            "• Vazifalar: title, date, priority, category\n" .
            "• Tranzaksiyalar: type, amount, category, note, date\n" .
            "• Qarzlar: type, person, amount, due_date\n\n" .
            "❌ Bekor qilish: /bekor"
        );
    }

    public function processImport(TelegramUser $user, string $filePath): void
    {
        $this->bot->sendChatAction($user->telegram_id, 'typing');

        try {
            $content = file_get_contents($filePath);
            $lines = explode("\n", trim($content));
            
            if (count($lines) < 2) {
                $this->bot->sendMessage($user->telegram_id, "❌ Fayl bo'sh yoki noto'g'ri format.");
                return;
            }

            $header = str_getcsv(array_shift($lines));
            $imported = ['tasks' => 0, 'transactions' => 0, 'debts' => 0];

            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                
                $row = str_getcsv($line);
                $data = array_combine($header, $row);

                // Detect type and import
                if (isset($data['title'])) {
                    $this->importTask($user, $data);
                    $imported['tasks']++;
                } elseif (isset($data['amount']) && isset($data['type'])) {
                    if (isset($data['person'])) {
                        $this->importDebt($user, $data);
                        $imported['debts']++;
                    } else {
                        $this->importTransaction($user, $data);
                        $imported['transactions']++;
                    }
                }
            }

            $user->clearState();

            $message = "✅ <b>Import yakunlandi!</b>\n\n" .
                "📋 Vazifalar: {$imported['tasks']}\n" .
                "💰 Tranzaksiyalar: {$imported['transactions']}\n" .
                "💳 Qarzlar: {$imported['debts']}";

            $this->bot->sendMessage($user->telegram_id, $message);

        } catch (\Exception $e) {
            $user->clearState();
            $this->bot->sendMessage(
                $user->telegram_id,
                "❌ Import xatosi: " . $e->getMessage()
            );
        }
    }

    protected function prepareExportData(TelegramUser $user): array
    {
        return [
            'tasks' => $user->tasks()->get()->map(fn($t) => [
                'title' => $t->title,
                'description' => $t->description,
                'date' => $t->date?->format('Y-m-d'),
                'time' => $t->time,
                'priority' => $t->priority,
                'category' => $t->category,
                'status' => $t->status,
                'rating' => $t->rating,
                'created_at' => $t->created_at->format('Y-m-d H:i:s'),
            ])->toArray(),

            'transactions' => $user->transactions()->get()->map(fn($t) => [
                'type' => $t->type,
                'amount' => $t->amount,
                'currency' => $t->currency,
                'category' => $t->category,
                'note' => $t->note,
                'date' => $t->date->format('Y-m-d'),
                'created_at' => $t->created_at->format('Y-m-d H:i:s'),
            ])->toArray(),

            'debts' => $user->debts()->get()->map(fn($d) => [
                'type' => $d->type,
                'person_name' => $d->person_name,
                'amount' => $d->amount,
                'amount_paid' => $d->amount_paid,
                'currency' => $d->currency,
                'due_date' => $d->due_date?->format('Y-m-d'),
                'status' => $d->status,
                'note' => $d->note,
                'created_at' => $d->created_at->format('Y-m-d H:i:s'),
            ])->toArray(),
        ];
    }

    protected function generateCSV(array $data): string
    {
        $output = "";

        // Tasks section
        if (!empty($data['tasks'])) {
            $output .= "=== VAZIFALAR ===\n";
            $output .= implode(',', array_keys($data['tasks'][0])) . "\n";
            foreach ($data['tasks'] as $row) {
                $output .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v ?? '') . '"', $row)) . "\n";
            }
            $output .= "\n";
        }

        // Transactions section
        if (!empty($data['transactions'])) {
            $output .= "=== TRANZAKSIYALAR ===\n";
            $output .= implode(',', array_keys($data['transactions'][0])) . "\n";
            foreach ($data['transactions'] as $row) {
                $output .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v ?? '') . '"', $row)) . "\n";
            }
            $output .= "\n";
        }

        // Debts section
        if (!empty($data['debts'])) {
            $output .= "=== QARZLAR ===\n";
            $output .= implode(',', array_keys($data['debts'][0])) . "\n";
            foreach ($data['debts'] as $row) {
                $output .= implode(',', array_map(fn($v) => '"' . str_replace('"', '""', $v ?? '') . '"', $row)) . "\n";
            }
        }

        return $output;
    }

    protected function importTask(TelegramUser $user, array $data): void
    {
        $user->tasks()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'date' => isset($data['date']) ? \Carbon\Carbon::parse($data['date']) : today(),
            'priority' => $data['priority'] ?? 'medium',
            'category' => $data['category'] ?? 'other',
        ]);
    }

    protected function importTransaction(TelegramUser $user, array $data): void
    {
        $user->transactions()->create([
            'type' => $data['type'],
            'amount' => (float)$data['amount'],
            'currency' => $data['currency'] ?? $user->currency,
            'category' => $data['category'] ?? 'other',
            'note' => $data['note'] ?? null,
            'date' => isset($data['date']) ? \Carbon\Carbon::parse($data['date']) : today(),
        ]);
    }

    protected function importDebt(TelegramUser $user, array $data): void
    {
        $user->debts()->create([
            'type' => $data['type'],
            'person_name' => $data['person'],
            'amount' => (float)$data['amount'],
            'currency' => $data['currency'] ?? $user->currency,
            'due_date' => isset($data['due_date']) ? \Carbon\Carbon::parse($data['due_date']) : null,
            'date' => today(),
        ]);
    }
}
