<?php

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramUser;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\Debt;
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
        $message = "⚙️ <b>Settings</b>\n\n";
        
        $message .= "<b>Current Settings:</b>\n";
        $message .= "💱 Currency: {$user->currency}\n";
        $message .= "🌐 Language: {$user->language_code}\n";
        $message .= "⏰ Timezone: {$user->timezone}\n";
        $message .= "🌅 Morning time: {$user->morning_time}\n";
        $message .= "🌙 Evening time: {$user->evening_time}\n\n";

        $message .= "<b>Notifications:</b>\n";
        $message .= $user->morning_notifications ? "✅" : "❌";
        $message .= " Morning reminders\n";
        $message .= $user->evening_notifications ? "✅" : "❌";
        $message .= " Evening summaries\n";
        $message .= $user->debt_reminders ? "✅" : "❌";
        $message .= " Debt reminders\n";
        $message .= $user->budget_alerts ? "✅" : "❌";
        $message .= " Budget alerts\n\n";

        if ($user->monthly_budget_limit) {
            $message .= "<b>Budget Limits:</b>\n";
            if ($user->daily_budget_limit) {
                $message .= "📅 Daily: \${$user->daily_budget_limit}\n";
            }
            if ($user->monthly_budget_limit) {
                $message .= "📆 Monthly: \${$user->monthly_budget_limit}\n";
            }
        }

        $this->bot->sendMessageWithKeyboard(
            $user->telegram_id,
            $message,
            $this->bot->buildSettingsKeyboard()
        );
    }

    public function showNotificationSettings(TelegramUser $user): void
    {
        $message = "🔔 <b>Notification Settings</b>\n\n" .
            "Toggle notifications on/off:";

        $keyboard = [
            [
                [
                    'text' => ($user->morning_notifications ? '✅' : '❌') . ' Morning Reminders',
                    'callback_data' => 'toggle_notif:morning',
                ],
            ],
            [
                [
                    'text' => ($user->evening_notifications ? '✅' : '❌') . ' Evening Summaries',
                    'callback_data' => 'toggle_notif:evening',
                ],
            ],
            [
                [
                    'text' => ($user->debt_reminders ? '✅' : '❌') . ' Debt Reminders',
                    'callback_data' => 'toggle_notif:debt',
                ],
            ],
            [
                [
                    'text' => ($user->budget_alerts ? '✅' : '❌') . ' Budget Alerts',
                    'callback_data' => 'toggle_notif:budget',
                ],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function toggleNotification(TelegramUser $user, string $type, ?int $messageId): void
    {
        match ($type) {
            'morning' => $user->morning_notifications = !$user->morning_notifications,
            'evening' => $user->evening_notifications = !$user->evening_notifications,
            'debt' => $user->debt_reminders = !$user->debt_reminders,
            'budget' => $user->budget_alerts = !$user->budget_alerts,
            default => null,
        };

        $user->save();

        // Rebuild keyboard with updated states
        $keyboard = [
            [
                [
                    'text' => ($user->morning_notifications ? '✅' : '❌') . ' Morning Reminders',
                    'callback_data' => 'toggle_notif:morning',
                ],
            ],
            [
                [
                    'text' => ($user->evening_notifications ? '✅' : '❌') . ' Evening Summaries',
                    'callback_data' => 'toggle_notif:evening',
                ],
            ],
            [
                [
                    'text' => ($user->debt_reminders ? '✅' : '❌') . ' Debt Reminders',
                    'callback_data' => 'toggle_notif:debt',
                ],
            ],
            [
                [
                    'text' => ($user->budget_alerts ? '✅' : '❌') . ' Budget Alerts',
                    'callback_data' => 'toggle_notif:budget',
                ],
            ],
        ];

        $message = "🔔 <b>Notification Settings</b>\n\n" .
            "Toggle notifications on/off:";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        }
    }

    public function showCurrencySettings(TelegramUser $user): void
    {
        $message = "💱 <b>Currency Settings</b>\n\n" .
            "Current: {$user->currency}\n\n" .
            "Select your preferred currency:";

        $keyboard = [
            [
                ['text' => '🇺🇸 USD', 'callback_data' => 'set_currency:USD'],
                ['text' => '🇪🇺 EUR', 'callback_data' => 'set_currency:EUR'],
            ],
            [
                ['text' => '🇷🇺 RUB', 'callback_data' => 'set_currency:RUB'],
                ['text' => '🇺🇿 UZS', 'callback_data' => 'set_currency:UZS'],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function setCurrency(TelegramUser $user, string $currency, ?int $messageId): void
    {
        $user->currency = $currency;
        $user->save();

        $message = "✅ Currency set to {$currency}";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function showLanguageSettings(TelegramUser $user): void
    {
        $message = "🌐 <b>Language Settings</b>\n\n" .
            "Current: {$user->language_code}\n\n" .
            "Select your preferred language:";

        $keyboard = [
            [
                ['text' => '🇬🇧 English', 'callback_data' => 'set_language:en'],
                ['text' => '🇷🇺 Русский', 'callback_data' => 'set_language:ru'],
            ],
            [
                ['text' => '🇺🇿 O\'zbek', 'callback_data' => 'set_language:uz'],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function setLanguage(TelegramUser $user, string $language, ?int $messageId): void
    {
        $user->language_code = $language;
        $user->save();

        $messages = [
            'en' => '✅ Language set to English',
            'ru' => '✅ Язык изменён на русский',
            'uz' => '✅ Til o\'zbek tiliga o\'zgartirildi',
        ];

        $message = $messages[$language] ?? '✅ Language updated';

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function showTimezoneSettings(TelegramUser $user): void
    {
        $message = "⏰ <b>Timezone Settings</b>\n\n" .
            "Current: {$user->timezone}\n\n" .
            "Select your timezone:";

        $keyboard = [
            [
                ['text' => 'UTC+0', 'callback_data' => 'set_timezone:UTC'],
                ['text' => 'UTC+3 (Moscow)', 'callback_data' => 'set_timezone:Europe/Moscow'],
            ],
            [
                ['text' => 'UTC+5 (Tashkent)', 'callback_data' => 'set_timezone:Asia/Tashkent'],
                ['text' => 'UTC+6 (Almaty)', 'callback_data' => 'set_timezone:Asia/Almaty'],
            ],
            [
                ['text' => 'UTC-5 (New York)', 'callback_data' => 'set_timezone:America/New_York'],
                ['text' => 'UTC-8 (Los Angeles)', 'callback_data' => 'set_timezone:America/Los_Angeles'],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function setTimezone(TelegramUser $user, string $timezone, ?int $messageId): void
    {
        $user->timezone = $timezone;
        $user->save();

        $message = "✅ Timezone set to {$timezone}";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function exportData(TelegramUser $user): void
    {
        $this->bot->sendChatAction($user->telegram_id, 'upload_document');

        // Prepare tasks data
        $tasks = $user->tasks()->get()->map(fn($task) => [
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority,
            'category' => $task->category,
            'date' => $task->date?->format('Y-m-d'),
            'time' => $task->time,
            'status' => $task->status,
            'completed_at' => $task->completed_at?->format('Y-m-d H:i:s'),
            'rating' => $task->rating,
            'tags' => $task->tags,
        ]);

        // Prepare transactions data
        $transactions = $user->transactions()->get()->map(fn($tx) => [
            'type' => $tx->type,
            'amount' => $tx->amount,
            'currency' => $tx->currency,
            'category' => $tx->category,
            'note' => $tx->note,
            'date' => $tx->date?->format('Y-m-d'),
        ]);

        // Prepare debts data
        $debts = $user->debts()->get()->map(fn($debt) => [
            'type' => $debt->type,
            'person_name' => $debt->person_name,
            'amount' => $debt->amount,
            'amount_paid' => $debt->amount_paid,
            'currency' => $debt->currency,
            'note' => $debt->note,
            'date' => $debt->date?->format('Y-m-d'),
            'due_date' => $debt->due_date?->format('Y-m-d'),
            'status' => $debt->status,
        ]);

        $exportData = [
            'exported_at' => now()->toIso8601String(),
            'user' => [
                'telegram_id' => $user->telegram_id,
                'username' => $user->username,
                'name' => $user->getDisplayName(),
            ],
            'statistics' => [
                'total_points' => $user->total_points,
                'tasks_completed' => $user->tasks_completed,
                'streak_days' => $user->streak_days,
            ],
            'tasks' => $tasks,
            'transactions' => $transactions,
            'debts' => $debts,
        ];

        // Create JSON file
        $filename = "export_{$user->telegram_id}_" . now()->format('Y-m-d_His') . ".json";
        $filepath = storage_path("app/exports/{$filename}");

        if (!is_dir(dirname($filepath))) {
            mkdir(dirname($filepath), 0755, true);
        }

        file_put_contents($filepath, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Send file to user
        $this->bot->sendDocument(
            $user->telegram_id,
            new \CURLFile($filepath, 'application/json', $filename),
            "📤 <b>Data Export</b>\n\n" .
            "📋 Tasks: {$tasks->count()}\n" .
            "💰 Transactions: {$transactions->count()}\n" .
            "💳 Debts: {$debts->count()}\n\n" .
            "Exported: " . now()->format('M j, Y H:i')
        );

        // Clean up file after sending
        @unlink($filepath);
    }

    public function startImport(TelegramUser $user): void
    {
        $user->setState('importing_data', ['step' => 'waiting_file']);

        $this->bot->sendMessage(
            $user->telegram_id,
            "📥 <b>Import Data</b>\n\n" .
            "Send me a JSON export file to import your data.\n\n" .
            "⚠️ <b>Warning:</b> Importing will add new records. " .
            "Existing data will not be deleted.\n\n" .
            "Send the file or /cancel to abort."
        );
    }

    public function processImport(TelegramUser $user, array $data): void
    {
        $imported = [
            'tasks' => 0,
            'transactions' => 0,
            'debts' => 0,
        ];

        // Import tasks
        if (!empty($data['tasks'])) {
            foreach ($data['tasks'] as $taskData) {
                Task::create([
                    'telegram_user_id' => $user->id,
                    'title' => $taskData['title'],
                    'description' => $taskData['description'] ?? null,
                    'priority' => $taskData['priority'] ?? 'medium',
                    'category' => $taskData['category'] ?? 'other',
                    'date' => $taskData['date'] ?? null,
                    'time' => $taskData['time'] ?? null,
                    'status' => $taskData['status'] ?? 'pending',
                    'tags' => $taskData['tags'] ?? [],
                ]);
                $imported['tasks']++;
            }
        }

        // Import transactions
        if (!empty($data['transactions'])) {
            foreach ($data['transactions'] as $txData) {
                Transaction::create([
                    'telegram_user_id' => $user->id,
                    'type' => $txData['type'],
                    'amount' => $txData['amount'],
                    'currency' => $txData['currency'] ?? $user->currency,
                    'category' => $txData['category'] ?? 'other',
                    'note' => $txData['note'] ?? null,
                    'date' => $txData['date'] ?? today(),
                ]);
                $imported['transactions']++;
            }
        }

        // Import debts
        if (!empty($data['debts'])) {
            foreach ($data['debts'] as $debtData) {
                Debt::create([
                    'telegram_user_id' => $user->id,
                    'type' => $debtData['type'],
                    'person_name' => $debtData['person_name'],
                    'amount' => $debtData['amount'],
                    'amount_paid' => $debtData['amount_paid'] ?? 0,
                    'currency' => $debtData['currency'] ?? $user->currency,
                    'note' => $debtData['note'] ?? null,
                    'date' => $debtData['date'] ?? today(),
                    'due_date' => $debtData['due_date'] ?? null,
                    'status' => $debtData['status'] ?? 'active',
                ]);
                $imported['debts']++;
            }
        }

        $user->clearState();

        $this->bot->sendMessage(
            $user->telegram_id,
            "✅ <b>Import Complete!</b>\n\n" .
            "📋 Tasks imported: {$imported['tasks']}\n" .
            "💰 Transactions imported: {$imported['transactions']}\n" .
            "💳 Debts imported: {$imported['debts']}"
        );
    }

    public function setBudgetLimit(TelegramUser $user, string $type, float $amount): void
    {
        match ($type) {
            'daily' => $user->daily_budget_limit = $amount,
            'weekly' => $user->weekly_budget_limit = $amount,
            'monthly' => $user->monthly_budget_limit = $amount,
            default => null,
        };

        $user->save();

        $this->bot->sendMessage(
            $user->telegram_id,
            "✅ " . ucfirst($type) . " budget limit set to \$" . number_format($amount, 2)
        );
    }
}

