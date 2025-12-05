<?php

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramUser;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\Debt;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;

class StateHandler
{
    protected TelegramBotService $bot;
    protected TaskHandler $taskHandler;
    protected FinanceHandler $financeHandler;
    protected DebtHandler $debtHandler;
    protected SettingsHandler $settingsHandler;
    protected AIHandler $aiHandler;

    public function __construct(
        TelegramBotService $bot,
        TaskHandler $taskHandler,
        FinanceHandler $financeHandler,
        DebtHandler $debtHandler,
        SettingsHandler $settingsHandler,
        AIHandler $aiHandler
    ) {
        $this->bot = $bot;
        $this->taskHandler = $taskHandler;
        $this->financeHandler = $financeHandler;
        $this->debtHandler = $debtHandler;
        $this->settingsHandler = $settingsHandler;
        $this->aiHandler = $aiHandler;
    }

    public function handle(TelegramUser $user, string $text, array $message): void
    {
        $state = $user->current_state;
        $data = $user->state_data ?? [];

        match ($state) {
            'adding_task' => $this->handleAddingTask($user, $text, $data),
            'editing_task' => $this->handleEditingTask($user, $text, $data),
            'adding_transaction' => $this->handleAddingTransaction($user, $text, $data),
            'adding_debt' => $this->handleAddingDebt($user, $text, $data),
            'partial_payment' => $this->handlePartialPayment($user, $text, $data),
            'calendar_range' => $this->handleCalendarRange($user, $text, $data),
            'importing_data' => $this->handleImportingData($user, $text, $data, $message),
            'ai_chat' => $this->handleAIChat($user, $text),
            'setting_budget' => $this->handleSettingBudget($user, $text, $data),
            default => $this->handleUnknownState($user, $text),
        };
    }

    public function handleMedia(TelegramUser $user, array $message): void
    {
        $state = $user->current_state;

        if ($state === 'importing_data') {
            $this->processImportFile($user, $message);
            return;
        }

        // Handle media attachments for tasks/debts
        $this->bot->sendMessage(
            $user->telegram_id,
            "📎 File received. To attach files, first select a task or debt."
        );
    }

    public function handleConfirmation(TelegramUser $user, string $action, ?int $messageId): void
    {
        $confirmed = $action === 'confirm_yes';
        $state = $user->current_state;
        $data = $user->state_data ?? [];

        // Handle based on pending action
        if (isset($data['pending_action'])) {
            match ($data['pending_action']) {
                'delete_task' => $this->confirmDeleteTask($user, $confirmed, $data, $messageId),
                'delete_debt' => $this->confirmDeleteDebt($user, $confirmed, $data, $messageId),
                default => null,
            };
        }

        $user->clearState();
    }

    protected function handleAddingTask(TelegramUser $user, string $text, array $data): void
    {
        $step = $data['step'] ?? 'title';

        switch ($step) {
            case 'title':
                // Extract tags from title
                preg_match_all('/#(\w+)/', $text, $matches);
                $tags = array_map(fn($tag) => "#{$tag}", $matches[1] ?? []);
                $title = trim(preg_replace('/#\w+/', '', $text));

                if (empty($title)) {
                    $this->bot->sendMessage($user->telegram_id, "❌ Please enter a valid task title.");
                    return;
                }

                $data['title'] = $title;
                $data['tags'] = $tags;
                $data['step'] = 'priority';

                $user->setState('adding_task', $data);

                $keyboard = $this->bot->buildPriorityInlineKeyboard('task_priority');
                $this->bot->sendMessageWithInlineKeyboard(
                    $user->telegram_id,
                    "📌 <b>{$title}</b>\n\nSelect priority:",
                    $keyboard
                );
                break;

            case 'description':
                $data['description'] = $text;
                $data['step'] = 'priority';
                $user->setState('adding_task', $data);

                $keyboard = $this->bot->buildPriorityInlineKeyboard('task_priority');
                $this->bot->sendMessageWithInlineKeyboard(
                    $user->telegram_id,
                    "Select priority:",
                    $keyboard
                );
                break;

            case 'time':
                if (preg_match('/^(\d{1,2}):?(\d{2})?$/', $text, $matches)) {
                    $hour = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $minute = $matches[2] ?? '00';
                    $data['time'] = "{$hour}:{$minute}:00";
                }
                $data['step'] = 'confirm';
                $user->setState('adding_task', $data);
                $this->showTaskConfirmation($user, $data);
                break;
        }
    }

    protected function handleEditingTask(TelegramUser $user, string $text, array $data): void
    {
        $taskId = $data['task_id'] ?? null;
        $field = $data['field'] ?? null;

        if (!$taskId) {
            $user->clearState();
            return;
        }

        $task = $user->tasks()->find($taskId);
        if (!$task) {
            $user->clearState();
            $this->bot->sendMessage($user->telegram_id, "❌ Task not found.");
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
                $date = $this->parseDate($text);
                if ($date) {
                    $task->date = $date;
                }
                break;
            case 'time':
                if (preg_match('/^(\d{1,2}):?(\d{2})?$/', $text, $matches)) {
                    $hour = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $minute = $matches[2] ?? '00';
                    $task->time = "{$hour}:{$minute}:00";
                }
                break;
        }

        $task->save();
        $user->clearState();

        $this->bot->sendMessage($user->telegram_id, "✅ Task updated!");
        $this->taskHandler->viewTask($user, $taskId, null);
    }

    protected function handleAddingTransaction(TelegramUser $user, string $text, array $data): void
    {
        $step = $data['step'] ?? 'amount';
        $type = $data['type'];

        switch ($step) {
            case 'amount':
                $amount = $this->parseAmount($text);
                if ($amount === null || $amount <= 0) {
                    $this->bot->sendMessage(
                        $user->telegram_id,
                        "❌ Please enter a valid amount.\n\nExample: <code>100</code> or <code>100.50</code>"
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
                    "💰 Amount: \${$amount}\n\nSelect category:",
                    $keyboard
                );
                break;

            case 'note':
                $data['note'] = $text;
                $data['step'] = 'confirm';
                $user->setState('adding_transaction', $data);

                // Auto-categorize if not set
                if (empty($data['category'])) {
                    $auto = Transaction::autoCategorize($text);
                    $data['category'] = $auto['category'];
                    $user->setState('adding_transaction', $data);
                }

                $this->showTransactionConfirmation($user, $data);
                break;
        }
    }

    protected function handleAddingDebt(TelegramUser $user, string $text, array $data): void
    {
        $step = $data['step'] ?? 'person';

        switch ($step) {
            case 'person':
                $data['person'] = $text;
                $data['step'] = 'amount';
                $user->setState('adding_debt', $data);

                $this->bot->sendMessage(
                    $user->telegram_id,
                    "👤 Person: {$text}\n\nEnter the amount:"
                );
                break;

            case 'amount':
                $amount = $this->parseAmount($text);
                if ($amount === null || $amount <= 0) {
                    $this->bot->sendMessage($user->telegram_id, "❌ Please enter a valid amount.");
                    return;
                }

                $data['amount'] = $amount;
                $data['step'] = 'due_date';
                $user->setState('adding_debt', $data);

                $keyboard = [
                    [
                        ['text' => '📅 1 Week', 'callback_data' => 'debt_due:1w'],
                        ['text' => '📅 2 Weeks', 'callback_data' => 'debt_due:2w'],
                    ],
                    [
                        ['text' => '📆 1 Month', 'callback_data' => 'debt_due:1m'],
                        ['text' => '📆 3 Months', 'callback_data' => 'debt_due:3m'],
                    ],
                    [
                        ['text' => '❌ No Due Date', 'callback_data' => 'debt_due:none'],
                    ],
                ];

                $this->bot->sendMessageWithInlineKeyboard(
                    $user->telegram_id,
                    "💰 Amount: \${$amount}\n\nWhen is this due? (or type a date)",
                    $keyboard
                );
                break;

            case 'due_date':
                $date = $this->parseDate($text);
                if ($date) {
                    $data['due_date'] = $date;
                }
                $data['step'] = 'note';
                $user->setState('adding_debt', $data);

                $this->bot->sendMessage(
                    $user->telegram_id,
                    "Add a note (optional):\n\nOr type /skip to continue."
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
            $user->clearState();
            return;
        }

        $debt = $user->debts()->find($debtId);
        if (!$debt) {
            $user->clearState();
            $this->bot->sendMessage($user->telegram_id, "❌ Debt not found.");
            return;
        }

        $amount = $this->parseAmount($text);
        if ($amount === null || $amount <= 0) {
            $this->bot->sendMessage($user->telegram_id, "❌ Please enter a valid amount.");
            return;
        }

        if ($amount > $debt->getRemainingAmount()) {
            $amount = $debt->getRemainingAmount();
        }

        $debt->addPayment($amount);
        $user->clearState();

        $this->bot->sendMessage(
            $user->telegram_id,
            "✅ Payment of \${$amount} recorded!\n\n" .
            "Remaining: {$debt->getFormattedRemainingAmount()}"
        );
    }

    protected function handleCalendarRange(TelegramUser $user, string $text, array $data): void
    {
        $step = $data['step'] ?? 'start_date';

        $date = $this->parseDate($text);
        if (!$date) {
            $this->bot->sendMessage(
                $user->telegram_id,
                "❌ Invalid date format. Please use YYYY-MM-DD or DD.MM.YYYY"
            );
            return;
        }

        if ($step === 'start_date') {
            $data['start_date'] = $date->format('Y-m-d');
            $data['step'] = 'end_date';
            $user->setState('calendar_range', $data);

            $this->bot->sendMessage(
                $user->telegram_id,
                "📅 Start: {$date->format('M j, Y')}\n\nNow enter the end date:"
            );
        } else {
            $startDate = Carbon::parse($data['start_date']);
            $user->clearState();

            // Show calendar range
            // (Implementation would show data for the selected range)
            $this->bot->sendMessage(
                $user->telegram_id,
                "📅 Showing data from {$startDate->format('M j, Y')} to {$date->format('M j, Y')}"
            );
        }
    }

    protected function handleImportingData(TelegramUser $user, string $text, array $data, array $message): void
    {
        if (isset($message['document'])) {
            $this->processImportFile($user, $message);
        } else {
            $this->bot->sendMessage(
                $user->telegram_id,
                "📎 Please send a JSON export file.\n\nType /cancel to abort."
            );
        }
    }

    protected function handleAIChat(TelegramUser $user, string $text): void
    {
        $this->aiHandler->processAIQuery($user, $text);
    }

    protected function handleSettingBudget(TelegramUser $user, string $text, array $data): void
    {
        $amount = $this->parseAmount($text);
        if ($amount === null || $amount <= 0) {
            $this->bot->sendMessage($user->telegram_id, "❌ Please enter a valid amount.");
            return;
        }

        $type = $data['budget_type'] ?? 'monthly';
        $this->settingsHandler->setBudgetLimit($user, $type, $amount);
        $user->clearState();
    }

    protected function handleUnknownState(TelegramUser $user, string $text): void
    {
        $user->clearState();
        $this->bot->sendMessage(
            $user->telegram_id,
            "I'm not sure what you're trying to do. Let me take you back to the main menu."
        );
    }

    protected function processImportFile(TelegramUser $user, array $message): void
    {
        if (!isset($message['document'])) {
            return;
        }

        $document = $message['document'];
        
        // Check file type
        if (!str_ends_with($document['file_name'] ?? '', '.json')) {
            $this->bot->sendMessage($user->telegram_id, "❌ Please send a JSON file.");
            return;
        }

        $this->bot->sendChatAction($user->telegram_id, 'typing');

        // Download file
        $fileInfo = $this->bot->getFile($document['file_id']);
        
        if (!($fileInfo['ok'] ?? false)) {
            $this->bot->sendMessage($user->telegram_id, "❌ Could not download file.");
            return;
        }

        $filePath = $fileInfo['result']['file_path'];
        $fileContent = $this->bot->downloadFile($filePath);

        if (!$fileContent) {
            $this->bot->sendMessage($user->telegram_id, "❌ Could not read file.");
            return;
        }

        $data = json_decode($fileContent, true);
        
        if (!$data) {
            $this->bot->sendMessage($user->telegram_id, "❌ Invalid JSON format.");
            return;
        }

        $this->settingsHandler->processImport($user, $data);
    }

    protected function showTaskConfirmation(TelegramUser $user, array $data): void
    {
        $message = "📝 <b>Confirm Task</b>\n\n";
        $message .= "📌 Title: {$data['title']}\n";
        
        if (!empty($data['description'])) {
            $message .= "📝 Description: {$data['description']}\n";
        }
        
        $priority = $data['priority'] ?? 'medium';
        $message .= "🎯 Priority: " . ucfirst($priority) . "\n";
        
        $category = $data['category'] ?? 'other';
        $categories = config('telegram.task_categories');
        $message .= "📁 Category: {$categories[$category]}\n";
        
        if (!empty($data['tags'])) {
            $message .= "🏷️ Tags: " . implode(' ', $data['tags']) . "\n";
        }

        if (!empty($data['date'])) {
            $message .= "📅 Date: {$data['date']}\n";
        }

        if (!empty($data['time'])) {
            $message .= "⏰ Time: " . substr($data['time'], 0, 5) . "\n";
        }

        $keyboard = $this->bot->buildConfirmKeyboard('task_confirm');
        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    protected function showTransactionConfirmation(TelegramUser $user, array $data): void
    {
        $type = $data['type'];
        $emoji = $type === 'income' ? '💵' : '💸';
        
        $categories = $type === 'income' 
            ? config('telegram.income_categories')
            : config('telegram.expense_categories');

        $message = "{$emoji} <b>Confirm " . ucfirst($type) . "</b>\n\n";
        $message .= "💰 Amount: \${$data['amount']}\n";
        $message .= "📁 Category: {$categories[$data['category']]}\n";
        
        if (!empty($data['note'])) {
            $message .= "📝 Note: {$data['note']}\n";
        }

        $keyboard = $this->bot->buildConfirmKeyboard('tx_confirm');
        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    protected function showDebtConfirmation(TelegramUser $user, array $data): void
    {
        $emoji = $data['type'] === 'given' ? '📤' : '📥';
        $typeText = $data['type'] === 'given' ? 'Money I Gave' : 'Money I Owe';

        $message = "{$emoji} <b>Confirm Debt</b>\n\n";
        $message .= "📌 Type: {$typeText}\n";
        $message .= "👤 Person: {$data['person']}\n";
        $message .= "💰 Amount: \${$data['amount']}\n";
        
        if (!empty($data['due_date'])) {
            $date = Carbon::parse($data['due_date']);
            $message .= "📅 Due: {$date->format('M j, Y')}\n";
        }
        
        if (!empty($data['note'])) {
            $message .= "📝 Note: {$data['note']}\n";
        }

        $keyboard = $this->bot->buildConfirmKeyboard('debt_confirm');
        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    protected function confirmDeleteTask(TelegramUser $user, bool $confirmed, array $data, ?int $messageId): void
    {
        if ($confirmed && isset($data['task_id'])) {
            $task = $user->tasks()->find($data['task_id']);
            if ($task) {
                $task->delete();
                $this->bot->editMessage($user->telegram_id, $messageId, "🗑️ Task deleted.");
            }
        } else {
            $this->bot->editMessage($user->telegram_id, $messageId, "❌ Cancelled.");
        }
    }

    protected function confirmDeleteDebt(TelegramUser $user, bool $confirmed, array $data, ?int $messageId): void
    {
        if ($confirmed && isset($data['debt_id'])) {
            $debt = $user->debts()->find($data['debt_id']);
            if ($debt) {
                $debt->delete();
                $this->bot->editMessage($user->telegram_id, $messageId, "🗑️ Debt deleted.");
            }
        } else {
            $this->bot->editMessage($user->telegram_id, $messageId, "❌ Cancelled.");
        }
    }

    protected function parseAmount(string $text): ?float
    {
        // Remove currency symbols and spaces
        $text = preg_replace('/[^\d.,]/', '', $text);
        $text = str_replace(',', '.', $text);
        
        if (!is_numeric($text)) {
            return null;
        }
        
        return (float)$text;
    }

    protected function parseDate(string $text): ?Carbon
    {
        // Try various formats
        $formats = [
            'Y-m-d',
            'd.m.Y',
            'd/m/Y',
            'd-m-Y',
            'Y/m/d',
        ];

        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $text);
                if ($date) {
                    return $date;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Try natural language
        try {
            return Carbon::parse($text);
        } catch (\Exception $e) {
            return null;
        }
    }
}

