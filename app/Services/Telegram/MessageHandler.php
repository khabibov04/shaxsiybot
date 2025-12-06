<?php

namespace App\Services\Telegram;

use App\Models\TelegramUser;
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
    protected FinanceHandler $financeHandler;
    protected DebtHandler $debtHandler;
    protected CalendarHandler $calendarHandler;
    protected SettingsHandler $settingsHandler;
    protected AIHandler $aiHandler;
    protected StateHandler $stateHandler;

    public function __construct(
        TelegramBotService $bot,
        FinanceHandler $financeHandler,
        DebtHandler $debtHandler,
        CalendarHandler $calendarHandler,
        SettingsHandler $settingsHandler,
        AIHandler $aiHandler,
        StateHandler $stateHandler
    ) {
        $this->bot = $bot;
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
        } catch (\Throwable $e) {
            Log::error('Xabar ishlovchisi xatolik', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'update' => $update,
            ]);

            $this->notifyUserAboutError($update);
            throw $e;
        }
    }

    protected function notifyUserAboutError(array $update): void
    {
        try {
            $chatId = $update['message']['chat']['id'] 
                ?? $update['callback_query']['message']['chat']['id'] 
                ?? null;

            if ($chatId) {
                $this->bot->sendMessage(
                    $chatId,
                    "⚠️ Xatolik yuz berdi. Iltimos, keyinroq qayta urinib ko'ring."
                );
            }
        } catch (\Exception $e) {
            Log::warning('Foydalanuvchini xatolik haqida xabardor qilishda muammo', [
                'error' => $e->getMessage(),
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

        // Ovozli xabarlarni qayta ishlash
        if (isset($message['voice'])) {
            $this->handleVoiceMessage($user, $message);
            return;
        }

        // Hujjat/media qayta ishlash
        if (isset($message['document']) || isset($message['photo'])) {
            $this->handleMediaMessage($user, $message);
            return;
        }

        $text = $message['text'] ?? '';

        // Buyruqlarni qayta ishlash
        if (str_starts_with($text, '/')) {
            $this->handleCommand($user, $text);
            return;
        }

        // Asosiy menyu tugmalari - state ni tozalaydi
        $mainMenuButtons = [
            '💰 Moliya', '💳 Qarzlar', '📅 Taqvim',
            '📊 Statistika', '🤖 AI Yordamchi', '⚙️ Sozlamalar', '🔙 Orqaga'
        ];

        if (in_array($text, $mainMenuButtons)) {
            $user->clearState();
            $this->handleMenuButton($user, $text);
            return;
        }

        // Foydalanuvchi holatini qayta ishlash
        if ($user->current_state) {
            $this->stateHandler->handle($user, $text, $message);
            return;
        }

        // Menyu tugmalarini qayta ishlash
        $this->handleMenuButton($user, $text);
    }

    protected function handleCommand(TelegramUser $user, string $command): void
    {
        $parts = explode(' ', $command);
        $cmd = strtolower($parts[0]);
        $args = array_slice($parts, 1);

        match ($cmd) {
            '/start' => $this->commandStart($user),
            '/help', '/yordam' => $this->commandHelp($user),
            '/balance', '/balans' => $this->financeHandler->showBalance($user),
            '/debts', '/qarzlar' => $this->debtHandler->showActiveDebts($user),
            '/income', '/daromad' => $this->financeHandler->startAddIncome($user),
            '/expense', '/xarajat' => $this->financeHandler->startAddExpense($user),
            '/stats', '/statistika' => $this->financeHandler->showStatistics($user),
            '/export', '/eksport' => $this->settingsHandler->exportData($user),
            '/settings', '/sozlamalar' => $this->settingsHandler->showSettings($user),
            '/ai' => $this->aiHandler->startChat($user, implode(' ', $args)),
            '/cancel', '/bekor' => $this->cancelCurrentAction($user),
            default => $this->commandUnknown($user),
        };
    }

    protected function handleMenuButton(TelegramUser $user, string $text): void
    {
        match ($text) {
            // Asosiy menyu
            '💰 Moliya' => $this->showFinanceMenu($user),
            '📅 Taqvim' => $this->showCalendarMenu($user),
            '💳 Qarzlar' => $this->showDebtsMenu($user),
            '📊 Statistika' => $this->financeHandler->showStatistics($user),
            '🤖 AI Yordamchi' => $this->aiHandler->showAIMenu($user),
            '⚙️ Sozlamalar' => $this->settingsHandler->showSettings($user),

            // Moliya menyusi
            '💰 Balans' => $this->financeHandler->showBalance($user),
            '📊 Bugungi hisobot' => $this->financeHandler->showTodayReport($user),
            '📈 Oylik hisobot' => $this->financeHandler->showMonthReport($user),
            '💱 Valyuta kursi' => $this->financeHandler->showCurrencyRates($user),
            '📉 Tahlil' => $this->financeHandler->showAnalysis($user),

            // Qarzlar menyusi
            '📤 Qarz berdim' => $this->debtHandler->startAddGivenDebt($user),
            '📥 Qarz oldim' => $this->debtHandler->startAddReceivedDebt($user),
            '📋 Faol qarzlar' => $this->debtHandler->showActiveDebts($user),
            '⏰ Muddati yaqin' => $this->debtHandler->showDueSoon($user),
            '✅ To\'langan' => $this->debtHandler->showPaidDebts($user),
            '📊 Qarz xulosasi' => $this->debtHandler->showDebtSummary($user),

            // Taqvim menyusi
            '📅 Bugun' => $this->calendarHandler->showToday($user),
            '📆 Shu hafta' => $this->calendarHandler->showWeek($user),
            '🗓️ Shu oy' => $this->calendarHandler->showMonth($user),
            '📊 Shu yil' => $this->calendarHandler->showYear($user),
            '🔍 Maxsus oraliq' => $this->calendarHandler->startCustomRange($user),

            // Sozlamalar menyusi
            '🔔 Bildirishnomalar' => $this->settingsHandler->showNotificationSettings($user),
            '💱 Valyuta' => $this->settingsHandler->showCurrencySettings($user),
            '🌐 Til' => $this->settingsHandler->showLanguageSettings($user),
            '⏰ Vaqt zonasi' => $this->settingsHandler->showTimezoneSettings($user),
            '📤 Eksport' => $this->settingsHandler->exportData($user),
            '📥 Import' => $this->settingsHandler->startImport($user),

            // Orqaga tugmasi
            '🔙 Orqaga' => $this->commandStart($user),

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
            // Moliya callback'lari
            'tx_category' => $this->financeHandler->setCategory($user, $value, $messageId),
            'tx_confirm' => $this->financeHandler->confirmTransaction($user, $value, $messageId),
            'tx_delete' => $this->financeHandler->deleteTransaction($user, $value, $messageId),

            // Qarz callback'lari
            'debt_pay' => $this->debtHandler->markDebtPaid($user, $value, $messageId),
            'debt_partial' => $this->debtHandler->startPartialPayment($user, $value, $messageId),
            'debt_view' => $this->debtHandler->viewDebt($user, $value, $messageId),
            'debt_delete' => $this->debtHandler->deleteDebt($user, $value, $messageId),
            'debt_confirm' => $this->debtHandler->confirmDebt($user, $value, $messageId),

            // Sozlamalar callback'lari
            'set_currency' => $this->settingsHandler->setCurrency($user, $value, $messageId),
            'set_language' => $this->settingsHandler->setLanguage($user, $value, $messageId),
            'set_timezone' => $this->settingsHandler->setTimezone($user, $value, $messageId),
            'toggle_notif' => $this->settingsHandler->toggleNotification($user, $value, $messageId),

            // Taqvim callback'lari
            'cal_day' => $this->calendarHandler->showDay($user, $value, $messageId),
            'cal_nav' => $this->calendarHandler->navigate($user, $value, $messageId),

            // Tasdiqlash callback'lari
            'confirm_yes', 'confirm_no' => $this->stateHandler->handleConfirmation($user, $action, $messageId),

            // Sahifalash
            'page' => $this->handlePagination($user, $value, $messageId),

            default => null,
        };
    }

    protected function handleVoiceMessage(TelegramUser $user, array $message): void
    {
        $voice = $message['voice'];
        $this->bot->sendChatAction($user->telegram_id, 'typing');

        $this->bot->sendMessage($user->telegram_id, '🎤 Ovozli xabar qabul qilindi!');

        $this->bot->sendMessage(
            $user->telegram_id,
            "🎤 Ovozli xabar qabul qilindi!\n\n" .
            "Davomiyligi: {$voice['duration']} soniya\n\n" .
            "Ovozni matnga aylantirish funksiyasi tez orada qo'shiladi. " .
            "Hozircha xabaringizni yozma shaklda yuboring."
        );
    }

    protected function handleMediaMessage(TelegramUser $user, array $message): void
    {
        if ($user->current_state) {
            $this->stateHandler->handleMedia($user, $message);
            return;
        }

        $this->bot->sendMessage(
            $user->telegram_id,
            "📎 Fayl qabul qilindi! Uni vazifa yoki tranzaksiyaga biriktirish uchun " .
            "avval element yarating yoki tanlang, keyin faylni yuboring."
        );
    }

    protected function handleUnknownText(TelegramUser $user, string $text): void
    {
        // Tez daromad kiritish: +50000 maosh
        if (preg_match('/^\+\s*(\d+(?:[\.,]\d+)?)\s*(.*)$/u', $text, $matches)) {
            $amount = (float)str_replace(',', '.', $matches[1]);
            $note = trim($matches[2]) ?: 'Daromad';
            $this->financeHandler->quickIncome($user, $amount, $note);
            return;
        }

        // Tez xarajat kiritish: 50000 ovqat yoki -50000 ovqat
        if (preg_match('/^-?\s*(\d+(?:[\.,]\d+)?)\s+(.+)$/u', $text, $matches)) {
            $amount = (float)str_replace(',', '.', $matches[1]);
            $note = trim($matches[2]);
            $this->financeHandler->quickExpense($user, $amount, $note);
            return;
        }

        // Faqat raqam + izoh (xarajat)
        if (preg_match('/^(\d+(?:[\.,]\d+)?)\s+(.+)$/u', $text, $matches)) {
            $amount = (float)str_replace(',', '.', $matches[1]);
            $note = trim($matches[2]);
            $this->financeHandler->quickExpense($user, $amount, $note);
            return;
        }

        // AI tahlil uchun yuborish
        $this->aiHandler->analyzeMessage($user, $text);
    }

    protected function handlePagination(TelegramUser $user, string $value, int $messageId): void
    {
        [$type, $page] = explode('_', $value);
        
        match ($type) {
            'transactions' => $this->financeHandler->showTransactionsPage($user, (int)$page, $messageId),
            'debts' => $this->debtHandler->showDebtsPage($user, (int)$page, $messageId),
            default => null,
        };
    }

    protected function commandStart(TelegramUser $user): void
    {
        $name = $user->getDisplayName();
        $badge = $user->getBadgeInfo();

        $message = "👋 Xush kelibsiz, <b>{$name}</b>!\n\n" .
            "{$badge['name']} | 🎯 {$user->total_points} ball | 🔥 {$user->streak_days} kunlik seriya\n\n" .
            "Bugun nima qilmoqchisiz?";

        $this->bot->sendMessageWithKeyboard(
            $user->telegram_id,
            $message,
            $this->bot->buildMainMenuKeyboard()
        );
    }

    protected function commandHelp(TelegramUser $user): void
    {
        $helpText = "📚 <b>Mavjud buyruqlar</b>\n\n" .
            "<b>Tezkor buyruqlar:</b>\n" .
            "/balans - Joriy balans\n" .
            "/qarzlar - Faol qarzlar\n\n" .
            "<b>Tezkor harakatlar:</b>\n" .
            "/daromad - Daromad qo'shish\n" .
            "/xarajat - Xarajat qo'shish\n" .
            "/statistika - Statistikani ko'rish\n" .
            "/eksport - Ma'lumotlarni eksport qilish\n\n" .
            "<b>Boshqa:</b>\n" .
            "/ai [savol] - AI yordamchi\n" .
            "/sozlamalar - Bot sozlamalari\n" .
            "/bekor - Joriy amalni bekor qilish\n\n" .
            "💡 <b>Maslahat:</b> Tez kiritish:\n" .
            "💸 Xarajat: <code>50000 ovqat</code>\n" .
            "💵 Daromad: <code>+1000000 maosh</code>";

        $this->bot->sendMessage($user->telegram_id, $helpText);
    }

    protected function commandUnknown(TelegramUser $user): void
    {
        $this->bot->sendMessage(
            $user->telegram_id,
            "❓ Noma'lum buyruq. Mavjud buyruqlarni ko'rish uchun /yordam yozing."
        );
    }

    protected function cancelCurrentAction(TelegramUser $user): void
    {
        $user->clearState();
        $this->bot->sendMessage($user->telegram_id, "❌ Amal bekor qilindi.");
        $this->commandStart($user);
    }

    protected function showFinanceMenu(TelegramUser $user): void
    {
        $balance = $user->getBalance();
        $todayExpenses = $user->getTodayExpenses();
        $monthExpenses = $user->getMonthExpenses();

        $message = "💰 <b>Moliya</b>\n\n" .
            "💵 Balans: " . number_format($balance, 0, '.', ' ') . " so'm\n" .
            "📅 Bugungi xarajat: " . number_format($todayExpenses, 0, '.', ' ') . " so'm\n" .
            "📆 Oylik xarajat: " . number_format($monthExpenses, 0, '.', ' ') . " so'm\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            "📝 <b>Tez kiritish:</b>\n" .
            "💸 Xarajat: <code>50000 ovqat</code>\n" .
            "💵 Daromad: <code>+1000000 maosh</code>";

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

        $message = "💳 <b>Qarzlar</b>\n\n" .
            "📤 Men bergan qarz: " . number_format($givenTotal, 0, '.', ' ') . " so'm\n" .
            "📥 Men olgan qarz: " . number_format($receivedTotal, 0, '.', ' ') . " so'm\n" .
            ($overdueCount > 0 ? "⚠️ Muddati o'tgan: {$overdueCount} ta qarz\n" : "") .
            "\nNima qilmoqchisiz?";

        $this->bot->sendMessageWithKeyboard(
            $user->telegram_id,
            $message,
            $this->bot->buildDebtsKeyboard()
        );
    }

    protected function showCalendarMenu(TelegramUser $user): void
    {
        $message = "📅 <b>Taqvim</b>\n\n" .
            "Xarajatlar va qarzlarni taqvim ko'rinishida ko'ring.\n\n" .
            "Ko'rinishni tanlang:";

        $this->bot->sendMessageWithKeyboard(
            $user->telegram_id,
            $message,
            $this->bot->buildCalendarKeyboard()
        );
    }
}
