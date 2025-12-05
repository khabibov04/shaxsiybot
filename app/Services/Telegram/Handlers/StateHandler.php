<?php

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramUser;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;

class StateHandler
{
    protected TelegramBotService $bot;
    protected TaskHandler $taskHandler;
    protected FinanceHandler $financeHandler;
    protected DebtHandler $debtHandler;
    protected CalendarHandler $calendarHandler;
    protected SettingsHandler $settingsHandler;

    public function __construct(
        TelegramBotService $bot,
        TaskHandler $taskHandler,
        FinanceHandler $financeHandler,
        DebtHandler $debtHandler,
        CalendarHandler $calendarHandler,
        SettingsHandler $settingsHandler
    ) {
        $this->bot = $bot;
        $this->taskHandler = $taskHandler;
        $this->financeHandler = $financeHandler;
        $this->debtHandler = $debtHandler;
        $this->calendarHandler = $calendarHandler;
        $this->settingsHandler = $settingsHandler;
    }

    public function handle(TelegramUser $user, string $text, array $message): void
    {
        $state = $user->current_state;
        $data = $user->state_data ?? [];

        match ($state) {
            'adding_task' => $this->handleTaskState($user, $text, $data),
            'editing_task' => $this->handleEditTaskState($user, $text, $data),
            'adding_transaction' => $this->handleTransactionState($user, $text, $data),
            'adding_debt' => $this->handleDebtState($user, $text, $data),
            'partial_payment' => $this->handlePartialPayment($user, $text, $data),
            'custom_range' => $this->handleCustomRange($user, $text, $data),
            'importing_data' => $this->handleImport($user, $message),
            'ai_chat' => $this->handleAIChat($user, $text),
            default => $this->clearAndNotify($user),
        };
    }

    public function handleMedia(TelegramUser $user, array $message): void
    {
        $state = $user->current_state;

        if ($state === 'importing_data') {
            $this->handleImportFile($user, $message);
            return;
        }

        $this->bot->sendMessage(
            $user->telegram_id,
            "📎 Media qabul qilindi, lekin hozirgi holatda ishlatib bo'lmaydi."
        );
    }

    public function handleConfirmation(TelegramUser $user, string $action, ?int $messageId): void
    {
        $state = $user->current_state;
        $confirmed = $action === 'confirm_yes';

        if (!$confirmed) {
            $user->clearState();
            $this->bot->editMessage($user->telegram_id, $messageId, "❌ Bekor qilindi.");
            return;
        }

        // Handle based on current state
        match ($state) {
            'adding_task' => $this->taskHandler->confirmTask($user, 'confirm', $messageId),
            'adding_transaction' => $this->financeHandler->confirmTransaction($user, 'confirm', $messageId),
            'adding_debt' => $this->debtHandler->confirmDebt($user, 'confirm', $messageId),
            default => $this->clearAndNotify($user),
        };
    }

    protected function handleTaskState(TelegramUser $user, string $text, array $data): void
    {
        $step = $data['step'] ?? 'title';

        switch ($step) {
            case 'title':
                // Extract tags from text
                preg_match_all('/#(\w+)/u', $text, $matches);
                $tags = $matches[1] ?? [];
                $title = trim(preg_replace('/#\w+/u', '', $text));

                if (empty($title)) {
                    $this->bot->sendMessage($user->telegram_id, "❌ Vazifa nomi bo'sh bo'lishi mumkin emas.");
                    return;
                }

                $data['title'] = $title;
                $data['tags'] = $tags;
                $data['step'] = 'priority';
                $user->setState('adding_task', $data);

                $keyboard = $this->bot->buildPriorityInlineKeyboard('task_priority');
                $this->bot->sendMessageWithInlineKeyboard(
                    $user->telegram_id,
                    "🎯 <b>Muhimlik darajasini tanlang:</b>",
                    $keyboard
                );
                break;

            case 'description':
                $data['description'] = $text;
                $data['step'] = 'time';
                $user->setState('adding_task', $data);

                $this->bot->sendMessage(
                    $user->telegram_id,
                    "⏰ <b>Vaqtni kiriting</b> (ixtiyoriy)\n\n" .
                    "Format: <code>HH:MM</code>\n" .
                    "Misol: <code>14:30</code>\n\n" .
                    "O'tkazib yuborish uchun /skip yozing"
                );
                break;

            case 'time':
                if ($text !== '/skip') {
                    if (preg_match('/^(\d{1,2}):(\d{2})$/', $text, $matches)) {
                        $data['time'] = sprintf('%02d:%02d:00', $matches[1], $matches[2]);
                    } else {
                        $this->bot->sendMessage(
                            $user->telegram_id,
                            "❌ Noto'g'ri format. HH:MM formatida kiriting (masalan, 14:30)"
                        );
                        return;
                    }
                }

                $data['step'] = 'confirm';
                $user->setState('adding_task', $data);

                $this->showTaskConfirmation($user, $data);
                break;
        }
    }

    protected function handleEditTaskState(TelegramUser $user, string $text, array $data): void
    {
        $taskId = $data['task_id'] ?? null;
        $field = $data['field'] ?? null;

        if (!$taskId || !$field) {
            $this->clearAndNotify($user);
            return;
        }

        $task = $user->tasks()->find($taskId);
        if (!$task) {
            $user->clearState();
            $this->bot->sendMessage($user->telegram_id, "❌ Vazifa topilmadi.");
            return;
        }

        switch ($field) {
            case 'title':
                $task->title = $text;
                break;
            case 'description':
                $task->description = $text;
                break;
            case 'date':
                try {
                    $task->date = Carbon::parse($text);
                } catch (\Exception $e) {
                    $this->bot->sendMessage($user->telegram_id, "❌ Noto'g'ri sana formati.");
                    return;
                }
                break;
            case 'time':
                if (preg_match('/^(\d{1,2}):(\d{2})$/', $text, $matches)) {
                    $task->time = sprintf('%02d:%02d:00', $matches[1], $matches[2]);
                } else {
                    $this->bot->sendMessage($user->telegram_id, "❌ Noto'g'ri vaqt formati.");
                    return;
                }
                break;
        }

        $task->save();
        $user->clearState();

        $this->bot->sendMessage($user->telegram_id, "✅ Vazifa yangilandi!");
        $this->taskHandler->viewTask($user, $taskId, null);
    }

    protected function handleTransactionState(TelegramUser $user, string $text, array $data): void
    {
        $step = $data['step'] ?? 'amount';
        $type = $data['type'] ?? 'expense';

        switch ($step) {
            case 'amount':
                $amount = $this->parseAmount($text);
                if ($amount === null || $amount <= 0) {
                    $this->bot->sendMessage(
                        $user->telegram_id,
                        "❌ Noto'g'ri summa. Faqat raqam kiriting."
                    );
                    return;
                }

                $data['amount'] = $amount;
                $data['step'] = 'category';
                $user->setState('adding_transaction', $data);

                $categories = $type === 'income' 
                    ? config('telegram.income_categories')
                    : config('telegram.expense_categories');

                $keyboard = $this->bot->buildCategoryInlineKeyboard($categories, 'tx_category');
                $this->bot->sendMessageWithInlineKeyboard(
                    $user->telegram_id,
                    "📁 <b>Kategoriyani tanlang:</b>",
                    $keyboard
                );
                break;

            case 'note':
                $data['note'] = $text;
                $data['step'] = 'confirm';
                $user->setState('adding_transaction', $data);

                $this->showTransactionConfirmation($user, $data);
                break;
        }
    }

    protected function handleDebtState(TelegramUser $user, string $text, array $data): void
    {
        $step = $data['step'] ?? 'person';
        $type = $data['type'] ?? 'given';

        switch ($step) {
            case 'person':
                if (strlen($text) < 2) {
                    $this->bot->sendMessage($user->telegram_id, "❌ Ism juda qisqa.");
                    return;
                }

                $data['person'] = $text;
                $data['step'] = 'amount';
                $user->setState('adding_debt', $data);

                $typeText = $type === 'given' ? 'berdingiz' : 'oldingiz';
                $this->bot->sendMessage(
                    $user->telegram_id,
                    "💰 <b>Qancha qarz {$typeText}?</b>\n\n" .
                    "Summani kiriting:"
                );
                break;

            case 'amount':
                $amount = $this->parseAmount($text);
                if ($amount === null || $amount <= 0) {
                    $this->bot->sendMessage(
                        $user->telegram_id,
                        "❌ Noto'g'ri summa. Faqat raqam kiriting."
                    );
                    return;
                }

                $data['amount'] = $amount;
                $data['step'] = 'due_date';
                $user->setState('adding_debt', $data);

                $keyboard = [
                    [
                        ['text' => '1 hafta', 'callback_data' => 'debt_due:1w'],
                        ['text' => '2 hafta', 'callback_data' => 'debt_due:2w'],
                    ],
                    [
                        ['text' => '1 oy', 'callback_data' => 'debt_due:1m'],
                        ['text' => '3 oy', 'callback_data' => 'debt_due:3m'],
                    ],
                    [
                        ['text' => 'Muddatsiz', 'callback_data' => 'debt_due:none'],
                    ],
                ];

                $this->bot->sendMessageWithInlineKeyboard(
                    $user->telegram_id,
                    "📅 <b>Qaytarish muddati</b>\n\n" .
                    "Tanlang yoki DD.MM.YYYY formatida kiriting:",
                    $keyboard
                );
                break;

            case 'due_date':
                if ($text !== '/skip') {
                    try {
                        $data['due_date'] = Carbon::createFromFormat('d.m.Y', $text);
                    } catch (\Exception $e) {
                        $this->bot->sendMessage(
                            $user->telegram_id,
                            "❌ Noto'g'ri format. DD.MM.YYYY formatida kiriting."
                        );
                        return;
                    }
                }

                $data['step'] = 'note';
                $user->setState('adding_debt', $data);

                $this->bot->sendMessage(
                    $user->telegram_id,
                    "📝 <b>Izoh qo'shing</b> (ixtiyoriy)\n\n" .
                    "O'tkazib yuborish uchun /skip yozing"
                );
                break;

            case 'note':
                if ($text !== '/skip') {
                    $data['note'] = $text;
                }

                $data['step'] = 'confirm';
                $user->setState('adding_debt', $data);

                $this->showDebtConfirmation($user, $data);
                break;
        }
    }

    protected function handlePartialPayment(TelegramUser $user, string $text, array $data): void
    {
        $debtId = $data['debt_id'] ?? null;
        
        if (!$debtId) {
            $this->clearAndNotify($user);
            return;
        }

        $debt = $user->debts()->find($debtId);
        if (!$debt) {
            $user->clearState();
            $this->bot->sendMessage($user->telegram_id, "❌ Qarz topilmadi.");
            return;
        }

        $amount = $this->parseAmount($text);
        if ($amount === null || $amount <= 0) {
            $this->bot->sendMessage($user->telegram_id, "❌ Noto'g'ri summa.");
            return;
        }

        $remaining = $debt->getRemainingAmount();
        if ($amount > $remaining) {
            $this->bot->sendMessage(
                $user->telegram_id,
                "❌ To'lov summasi qolgan summadan ({$remaining}) ko'p bo'lishi mumkin emas."
            );
            return;
        }

        $debt->addPartialPayment($amount);
        $user->clearState();

        $newRemaining = $debt->getRemainingAmount();
        $message = "✅ <b>To'lov qabul qilindi!</b>\n\n" .
            "💳 To'langan: " . number_format($amount, 0, '.', ' ') . " so'm\n" .
            "📊 Qolgan: " . number_format($newRemaining, 0, '.', ' ') . " so'm";

        if ($newRemaining === 0) {
            $message .= "\n\n🎉 Qarz to'liq to'landi!";
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    protected function handleCustomRange(TelegramUser $user, string $text, array $data): void
    {
        $step = $data['step'] ?? 'start_date';

        try {
            $date = Carbon::createFromFormat('d.m.Y', $text);
        } catch (\Exception $e) {
            $this->bot->sendMessage(
                $user->telegram_id,
                "❌ Noto'g'ri format. DD.MM.YYYY formatida kiriting."
            );
            return;
        }

        if ($step === 'start_date') {
            $data['start_date'] = $date;
            $data['step'] = 'end_date';
            $user->setState('custom_range', $data);

            $this->bot->sendMessage(
                $user->telegram_id,
                "📅 <b>Tugash sanasini kiriting:</b>\n\n" .
                "Format: DD.MM.YYYY"
            );
        } else {
            $startDate = $data['start_date'];
            
            if ($date->lt($startDate)) {
                $this->bot->sendMessage(
                    $user->telegram_id,
                    "❌ Tugash sanasi boshlanish sanasidan oldin bo'lishi mumkin emas."
                );
                return;
            }

            $user->clearState();
            $this->calendarHandler->showCustomRange($user, $startDate, $date);
        }
    }

    protected function handleImport(TelegramUser $user, array $message): void
    {
        $this->bot->sendMessage(
            $user->telegram_id,
            "📥 CSV faylni yuboring..."
        );
    }

    protected function handleImportFile(TelegramUser $user, array $message): void
    {
        if (!isset($message['document'])) {
            $this->bot->sendMessage($user->telegram_id, "❌ Iltimos, CSV fayl yuboring.");
            return;
        }

        $document = $message['document'];
        
        if (!str_ends_with(strtolower($document['file_name'] ?? ''), '.csv')) {
            $this->bot->sendMessage($user->telegram_id, "❌ Faqat CSV fayllar qabul qilinadi.");
            return;
        }

        $fileInfo = $this->bot->getFile($document['file_id']);
        if (!($fileInfo['ok'] ?? false)) {
            $this->bot->sendMessage($user->telegram_id, "❌ Faylni yuklab bo'lmadi.");
            return;
        }

        $filePath = $fileInfo['result']['file_path'];
        $content = $this->bot->downloadFile($filePath);

        if (!$content) {
            $this->bot->sendMessage($user->telegram_id, "❌ Faylni o'qib bo'lmadi.");
            return;
        }

        // Save temporarily and process
        $tempPath = storage_path('app/temp_import_' . $user->telegram_id . '.csv');
        file_put_contents($tempPath, $content);

        $this->settingsHandler->processImport($user, $tempPath);

        unlink($tempPath);
    }

    protected function handleAIChat(TelegramUser $user, string $text): void
    {
        // Delegate to AIHandler
        app(AIHandler::class)->processChat($user, $text);
    }

    protected function showTaskConfirmation(TelegramUser $user, array $data): void
    {
        $message = "📝 <b>Vazifani tasdiqlang</b>\n\n";
        $message .= "📌 Nom: {$data['title']}\n";
        
        if (!empty($data['description'])) {
            $message .= "📝 Tavsif: {$data['description']}\n";
        }
        
        $priorities = ['high' => 'Yuqori', 'medium' => 'O\'rta', 'low' => 'Past'];
        $message .= "🎯 Muhimlik: " . ($priorities[$data['priority'] ?? 'medium'] ?? 'O\'rta') . "\n";
        
        $categories = config('telegram.task_categories');
        $message .= "📁 Kategoriya: " . ($categories[$data['category'] ?? 'other'] ?? 'Boshqa') . "\n";
        
        if (!empty($data['tags'])) {
            $message .= "🏷️ Teglar: " . implode(' ', array_map(fn($t) => "#{$t}", $data['tags'])) . "\n";
        }

        if (!empty($data['time'])) {
            $message .= "⏰ Vaqt: " . substr($data['time'], 0, 5) . "\n";
        }

        $keyboard = $this->bot->buildConfirmKeyboard('task_confirm');
        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    protected function showTransactionConfirmation(TelegramUser $user, array $data): void
    {
        $type = $data['type'] === 'income' ? 'Daromad' : 'Xarajat';
        $emoji = $data['type'] === 'income' ? '💵' : '💸';
        
        $categories = $data['type'] === 'income' 
            ? config('telegram.income_categories')
            : config('telegram.expense_categories');

        $message = "{$emoji} <b>{$type}ni tasdiqlang</b>\n\n";
        $message .= "💰 Summa: " . number_format($data['amount'], 0, '.', ' ') . " so'm\n";
        $message .= "📁 Kategoriya: " . ($categories[$data['category'] ?? 'other'] ?? 'Boshqa') . "\n";
        
        if (!empty($data['note'])) {
            $message .= "📝 Izoh: {$data['note']}\n";
        }

        $keyboard = $this->bot->buildConfirmKeyboard('tx_confirm');
        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    protected function showDebtConfirmation(TelegramUser $user, array $data): void
    {
        $type = $data['type'] === 'given' ? 'Qarz berdim' : 'Qarz oldim';
        $emoji = $data['type'] === 'given' ? '📤' : '📥';

        $message = "{$emoji} <b>{$type} - tasdiqlang</b>\n\n";
        $message .= "👤 Shaxs: {$data['person']}\n";
        $message .= "💰 Summa: " . number_format($data['amount'], 0, '.', ' ') . " so'm\n";
        
        if (!empty($data['due_date'])) {
            $message .= "📅 Muddat: {$data['due_date']->format('d.m.Y')}\n";
        }
        
        if (!empty($data['note'])) {
            $message .= "📝 Izoh: {$data['note']}\n";
        }

        $keyboard = $this->bot->buildConfirmKeyboard('debt_confirm');
        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    protected function parseAmount(string $text): ?float
    {
        // Remove spaces and common separators
        $cleaned = preg_replace('/[\s,\']/', '', $text);
        
        // Replace comma with dot for decimals
        $cleaned = str_replace(',', '.', $cleaned);
        
        if (!is_numeric($cleaned)) {
            return null;
        }

        return (float)$cleaned;
    }

    protected function clearAndNotify(TelegramUser $user): void
    {
        $user->clearState();
        $this->bot->sendMessage(
            $user->telegram_id,
            "❌ Noma'lum holat. Asosiy menyuga qaytdingiz."
        );
    }
}
