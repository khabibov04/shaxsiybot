<?php

namespace App\Services\Telegram;

use App\Models\TelegramUser;
use App\Services\Telegram\Handlers\TaskHandler;
use App\Services\Telegram\Handlers\FinanceHandler;
use App\Services\Telegram\Handlers\DebtHandler;
use App\Services\Telegram\Handlers\CalendarHandler;
use App\Services\Telegram\Handlers\SettingsHandler;
use App\Services\Telegram\Handlers\AIHandler;
use App\Services\Telegram\Handlers\StateHandler;
use Illuminate\Support\Facades\Log;

class MessageHandler
{
    protected TelegramBotService $bot;
    protected TaskHandler $taskHandler;
    protected FinanceHandler $financeHandler;
    protected DebtHandler $debtHandler;
    protected CalendarHandler $calendarHandler;
    protected SettingsHandler $settingsHandler;
    protected AIHandler $aiHandler;
    protected StateHandler $stateHandler;

    public function __construct(
        TelegramBotService $bot,
        TaskHandler $taskHandler,
        FinanceHandler $financeHandler,
        DebtHandler $debtHandler,
        CalendarHandler $calendarHandler,
        SettingsHandler $settingsHandler,
        AIHandler $aiHandler,
        StateHandler $stateHandler
    ) {
        $this->bot = $bot;
        $this->taskHandler = $taskHandler;
        $this->financeHandler = $financeHandler;
        $this->debtHandler = $debtHandler;
        $this->calendarHandler = $calendarHandler;
        $this->settingsHandler = $settingsHandler;
        $this->aiHandler = $aiHandler;
        $this->stateHandler = $stateHandler;
    }

    public function handle(array $update): void
    {
        try {
            if (isset($update['message'])) {
                $this->handleMessage($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->handleCallbackQuery($update['callback_query']);
            }
        } catch (\Exception $e) {
            Log::error('Message handler error', [
                'update' => $update,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    protected function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $from = $message['from'];
        $user = $this->bot->getOrCreateUser($from);

        if ($user->is_blocked) {
            return;
        }

        $user->updateStreak();

        // Handle voice messages
        if (isset($message['voice'])) {
            $this->handleVoiceMessage($user, $message);
            return;
        }

        // Handle documents/media
        if (isset($message['document']) || isset($message['photo'])) {
            $this->handleMediaMessage($user, $message);
            return;
        }

        $text = $message['text'] ?? '';

        // Handle commands
        if (str_starts_with($text, '/')) {
            $this->handleCommand($user, $text);
            return;
        }

        // Handle user state (if in a conversation flow)
        if ($user->current_state) {
            $this->stateHandler->handle($user, $text, $message);
            return;
        }

        // Handle menu buttons
        $this->handleMenuButton($user, $text);
    }

    protected function handleCommand(TelegramUser $user, string $command): void
    {
        $parts = explode(' ', $command);
        $cmd = strtolower($parts[0]);
        $args = array_slice($parts, 1);

        match ($cmd) {
            '/start' => $this->commandStart($user),
            '/help' => $this->commandHelp($user),
            '/today' => $this->taskHandler->showTodayTasks($user),
            '/week' => $this->taskHandler->showWeekTasks($user),
            '/month' => $this->taskHandler->showMonthTasks($user),
            '/year' => $this->taskHandler->showYearTasks($user),
            '/balance' => $this->financeHandler->showBalance($user),
            '/debts' => $this->debtHandler->showActiveDebts($user),
            '/addtask' => $this->taskHandler->startAddTask($user),
            '/income' => $this->financeHandler->startAddIncome($user),
            '/expense' => $this->financeHandler->startAddExpense($user),
            '/stats' => $this->financeHandler->showStatistics($user),
            '/export' => $this->settingsHandler->exportData($user),
            '/settings' => $this->settingsHandler->showSettings($user),
            '/ai' => $this->aiHandler->startChat($user, implode(' ', $args)),
            '/cancel' => $this->cancelCurrentAction($user),
            default => $this->commandUnknown($user),
        };
    }

    protected function handleMenuButton(TelegramUser $user, string $text): void
    {
        match ($text) {
            // Main menu
            '📋 Tasks' => $this->showTasksMenu($user),
            '💰 Finance' => $this->showFinanceMenu($user),
            '📅 Calendar' => $this->showCalendarMenu($user),
            '💳 Debts' => $this->showDebtsMenu($user),
            '📊 Statistics' => $this->financeHandler->showStatistics($user),
            '🤖 AI Assistant' => $this->aiHandler->showAIMenu($user),
            '⚙️ Settings' => $this->settingsHandler->showSettings($user),

            // Tasks menu
            '➕ Add Task' => $this->taskHandler->startAddTask($user),
            '📋 Today\'s Tasks' => $this->taskHandler->showTodayTasks($user),
            '📅 Week Tasks' => $this->taskHandler->showWeekTasks($user),
            '📆 Month Tasks' => $this->taskHandler->showMonthTasks($user),
            '🌅 Morning Plan' => $this->taskHandler->showMorningPlan($user),
            '🌙 Evening Summary' => $this->taskHandler->showEveningSummary($user),

            // Finance menu
            '💵 Add Income' => $this->financeHandler->startAddIncome($user),
            '💸 Add Expense' => $this->financeHandler->startAddExpense($user),
            '📊 Today Report' => $this->financeHandler->showTodayReport($user),
            '📈 Month Report' => $this->financeHandler->showMonthReport($user),
            '💱 Currency Rates' => $this->financeHandler->showCurrencyRates($user),
            '📉 Analysis' => $this->financeHandler->showAnalysis($user),

            // Debts menu
            '📤 I Gave Debt' => $this->debtHandler->startAddGivenDebt($user),
            '📥 I Received Debt' => $this->debtHandler->startAddReceivedDebt($user),
            '📋 Active Debts' => $this->debtHandler->showActiveDebts($user),
            '⏰ Due Soon' => $this->debtHandler->showDueSoon($user),
            '✅ Paid Debts' => $this->debtHandler->showPaidDebts($user),
            '📊 Debt Summary' => $this->debtHandler->showDebtSummary($user),

            // Calendar menu
            '📅 Today' => $this->calendarHandler->showToday($user),
            '📆 This Week' => $this->calendarHandler->showWeek($user),
            '🗓️ This Month' => $this->calendarHandler->showMonth($user),
            '📊 This Year' => $this->calendarHandler->showYear($user),
            '🔍 Custom Range' => $this->calendarHandler->startCustomRange($user),

            // Settings menu
            '🔔 Notifications' => $this->settingsHandler->showNotificationSettings($user),
            '💱 Currency' => $this->settingsHandler->showCurrencySettings($user),
            '🌐 Language' => $this->settingsHandler->showLanguageSettings($user),
            '⏰ Time Zone' => $this->settingsHandler->showTimezoneSettings($user),
            '📤 Export Data' => $this->settingsHandler->exportData($user),
            '📥 Import Data' => $this->settingsHandler->startImport($user),

            // Back button
            '🔙 Back to Menu' => $this->commandStart($user),

            default => $this->handleUnknownText($user, $text),
        };
    }

    protected function handleCallbackQuery(array $callbackQuery): void
    {
        $user = $this->bot->getOrCreateUser($callbackQuery['from']);
        $data = $callbackQuery['data'] ?? '';
        $messageId = $callbackQuery['message']['message_id'] ?? null;

        $this->bot->answerCallbackQuery($callbackQuery['id']);

        [$action, $value] = array_pad(explode(':', $data, 2), 2, null);

        match ($action) {
            // Task callbacks
            'task_done' => $this->taskHandler->markTaskDone($user, $value, $messageId),
            'task_view' => $this->taskHandler->viewTask($user, $value, $messageId),
            'task_edit' => $this->taskHandler->editTask($user, $value, $messageId),
            'task_delete' => $this->taskHandler->deleteTask($user, $value, $messageId),
            'task_rate' => $this->taskHandler->rateTask($user, $value, $messageId),
            'task_priority' => $this->taskHandler->setTaskPriority($user, $value, $messageId),
            'task_category' => $this->taskHandler->setTaskCategory($user, $value, $messageId),
            'task_confirm' => $this->taskHandler->confirmTask($user, $value, $messageId),

            // Finance callbacks
            'tx_category' => $this->financeHandler->setCategory($user, $value, $messageId),
            'tx_confirm' => $this->financeHandler->confirmTransaction($user, $value, $messageId),
            'tx_delete' => $this->financeHandler->deleteTransaction($user, $value, $messageId),

            // Debt callbacks
            'debt_pay' => $this->debtHandler->markDebtPaid($user, $value, $messageId),
            'debt_partial' => $this->debtHandler->startPartialPayment($user, $value, $messageId),
            'debt_view' => $this->debtHandler->viewDebt($user, $value, $messageId),
            'debt_delete' => $this->debtHandler->deleteDebt($user, $value, $messageId),
            'debt_confirm' => $this->debtHandler->confirmDebt($user, $value, $messageId),

            // Settings callbacks
            'set_currency' => $this->settingsHandler->setCurrency($user, $value, $messageId),
            'set_language' => $this->settingsHandler->setLanguage($user, $value, $messageId),
            'set_timezone' => $this->settingsHandler->setTimezone($user, $value, $messageId),
            'toggle_notif' => $this->settingsHandler->toggleNotification($user, $value, $messageId),

            // Calendar callbacks
            'cal_day' => $this->calendarHandler->showDay($user, $value, $messageId),
            'cal_nav' => $this->calendarHandler->navigate($user, $value, $messageId),

            // Rating callbacks
            'rating' => $this->taskHandler->submitRating($user, $value, $messageId),

            // Confirmation callbacks
            'confirm_yes', 'confirm_no' => $this->stateHandler->handleConfirmation($user, $action, $messageId),

            // Pagination
            'page' => $this->handlePagination($user, $value, $messageId),

            default => null,
        };
    }

    protected function handleVoiceMessage(TelegramUser $user, array $message): void
    {
        $voice = $message['voice'];
        $this->bot->sendChatAction($user->telegram_id, 'typing');

        // Send processing message
        $this->bot->sendMessage($user->telegram_id, '🎤 Processing voice message...');

        // TODO: Implement voice transcription using Whisper API or similar
        // For now, just acknowledge receipt
        $this->bot->sendMessage(
            $user->telegram_id,
            "🎤 Voice message received!\n\n" .
            "Duration: {$voice['duration']} seconds\n\n" .
            "Voice transcription feature coming soon. " .
            "Please type your message for now."
        );
    }

    protected function handleMediaMessage(TelegramUser $user, array $message): void
    {
        // Handle based on current state
        if ($user->current_state) {
            $this->stateHandler->handleMedia($user, $message);
            return;
        }

        $this->bot->sendMessage(
            $user->telegram_id,
            "📎 File received! To attach it to a task or transaction, " .
            "please first create or select an item, then send the file."
        );
    }

    protected function handleUnknownText(TelegramUser $user, string $text): void
    {
        // Check if it looks like a quick expense entry (e.g., "50 food lunch")
        if (preg_match('/^(\d+(?:\.\d{2})?)\s+(.+)$/i', $text, $matches)) {
            $this->financeHandler->quickExpense($user, (float)$matches[1], $matches[2]);
            return;
        }

        // Send to AI for analysis
        $this->aiHandler->analyzeMessage($user, $text);
    }

    protected function handlePagination(TelegramUser $user, string $value, int $messageId): void
    {
        [$type, $page] = explode('_', $value);
        
        match ($type) {
            'tasks' => $this->taskHandler->showTasksPage($user, (int)$page, $messageId),
            'transactions' => $this->financeHandler->showTransactionsPage($user, (int)$page, $messageId),
            'debts' => $this->debtHandler->showDebtsPage($user, (int)$page, $messageId),
            default => null,
        };
    }

    protected function commandStart(TelegramUser $user): void
    {
        $name = $user->getDisplayName();
        $badge = $user->getBadgeInfo();

        $message = "👋 Welcome back, <b>{$name}</b>!\n\n" .
            "{$badge['name']} | 🎯 {$user->total_points} points | 🔥 {$user->streak_days} day streak\n\n" .
            "What would you like to do today?";

        $this->bot->sendMessageWithKeyboard(
            $user->telegram_id,
            $message,
            $this->bot->buildMainMenuKeyboard()
        );
    }

    protected function commandHelp(TelegramUser $user): void
    {
        $helpText = "📚 <b>Available Commands</b>\n\n" .
            "<b>Shortcuts:</b>\n" .
            "/today - Today's tasks\n" .
            "/week - This week's tasks\n" .
            "/month - This month's tasks\n" .
            "/year - This year's overview\n" .
            "/balance - Current balance\n" .
            "/debts - Active debts\n\n" .
            "<b>Quick Actions:</b>\n" .
            "/addtask - Add a new task\n" .
            "/income - Add income\n" .
            "/expense - Add expense\n" .
            "/stats - View statistics\n" .
            "/export - Export your data\n\n" .
            "<b>Other:</b>\n" .
            "/ai [question] - Ask AI assistant\n" .
            "/settings - Bot settings\n" .
            "/cancel - Cancel current action\n\n" .
            "💡 <b>Tip:</b> You can quickly add expenses by typing:\n" .
            "<code>50 food lunch at cafe</code>";

        $this->bot->sendMessage($user->telegram_id, $helpText);
    }

    protected function commandUnknown(TelegramUser $user): void
    {
        $this->bot->sendMessage(
            $user->telegram_id,
            "❓ Unknown command. Type /help to see available commands."
        );
    }

    protected function cancelCurrentAction(TelegramUser $user): void
    {
        $user->clearState();
        $this->bot->sendMessage($user->telegram_id, "❌ Action cancelled.");
        $this->commandStart($user);
    }

    protected function showTasksMenu(TelegramUser $user): void
    {
        $todayCount = $user->tasks()->forToday()->pending()->count();
        $overdueCount = $user->tasks()->pending()
            ->whereDate('date', '<', today())->count();

        $message = "📋 <b>Tasks Menu</b>\n\n" .
            "📅 Today: {$todayCount} pending tasks\n" .
            ($overdueCount > 0 ? "⚠️ Overdue: {$overdueCount} tasks\n" : "") .
            "\nWhat would you like to do?";

        $this->bot->sendMessageWithKeyboard(
            $user->telegram_id,
            $message,
            $this->bot->buildTasksKeyboard()
        );
    }

    protected function showFinanceMenu(TelegramUser $user): void
    {
        $balance = $user->getBalance();
        $todayExpenses = $user->getTodayExpenses();
        $monthExpenses = $user->getMonthExpenses();

        $balanceFormatted = number_format($balance, 2);
        $todayFormatted = number_format($todayExpenses, 2);
        $monthFormatted = number_format($monthExpenses, 2);

        $message = "💰 <b>Finance Menu</b>\n\n" .
            "💵 Balance: \${$balanceFormatted}\n" .
            "📅 Today's expenses: \${$todayFormatted}\n" .
            "📆 Month expenses: \${$monthFormatted}\n" .
            "\nWhat would you like to do?";

        $this->bot->sendMessageWithKeyboard(
            $user->telegram_id,
            $message,
            $this->bot->buildFinanceKeyboard()
        );
    }

    protected function showDebtsMenu(TelegramUser $user): void
    {
        $givenTotal = $user->getActiveDebtsTotal('given');
        $receivedTotal = $user->getActiveDebtsTotal('received');
        $overdueCount = $user->debts()->overdue()->count();

        $message = "💳 <b>Debts Menu</b>\n\n" .
            "📤 Money I gave: \$" . number_format($givenTotal, 2) . "\n" .
            "📥 Money I owe: \$" . number_format($receivedTotal, 2) . "\n" .
            ($overdueCount > 0 ? "⚠️ Overdue: {$overdueCount} debts\n" : "") .
            "\nWhat would you like to do?";

        $this->bot->sendMessageWithKeyboard(
            $user->telegram_id,
            $message,
            $this->bot->buildDebtsKeyboard()
        );
    }

    protected function showCalendarMenu(TelegramUser $user): void
    {
        $message = "📅 <b>Calendar</b>\n\n" .
            "View your tasks, expenses, and debts in calendar format.\n\n" .
            "Choose a view:";

        $this->bot->sendMessageWithKeyboard(
            $user->telegram_id,
            $message,
            $this->bot->buildCalendarKeyboard()
        );
    }
}

