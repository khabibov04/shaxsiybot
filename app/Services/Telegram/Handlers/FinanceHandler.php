<?php

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramUser;
use App\Models\Transaction;
use App\Models\CurrencyRate;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Http;

class FinanceHandler
{
    protected TelegramBotService $bot;

    public function __construct(TelegramBotService $bot)
    {
        $this->bot = $bot;
    }

    public function startAddIncome(TelegramUser $user): void
    {
        $user->setState('adding_transaction', ['type' => 'income', 'step' => 'amount']);
        
        $this->bot->sendMessage(
            $user->telegram_id,
            "💵 <b>Daromad qo'shish</b>\n\n" .
            "Summani kiriting:\n\n" .
            "💡 Misol: <code>1500000</code> yoki <code>1500000.50</code>"
        );
    }

    public function startAddExpense(TelegramUser $user): void
    {
        $user->setState('adding_transaction', ['type' => 'expense', 'step' => 'amount']);
        
        $this->bot->sendMessage(
            $user->telegram_id,
            "💸 <b>Xarajat qo'shish</b>\n\n" .
            "Summani kiriting:\n\n" .
            "💡 Misol: <code>50000</code> yoki <code>50000.99</code>"
        );
    }

    public function quickExpense(TelegramUser $user, float $amount, string $note): void
    {
        $categorization = Transaction::autoCategorize($note);

        $transaction = Transaction::create([
            'telegram_user_id' => $user->id,
            'type' => 'expense',
            'amount' => $amount,
            'currency' => $user->currency,
            'category' => $categorization['category'],
            'note' => $note,
            'date' => today(),
            'auto_categorized' => true,
            'category_confidence' => $categorization['confidence'],
        ]);

        $this->checkBudgetAlerts($user);

        $categories = config('telegram.expense_categories');
        $categoryLabel = $categories[$transaction->category] ?? '📋 Boshqa';

        $message = "💸 <b>Xarajat qo'shildi!</b>\n\n" .
            "💰 Summa: " . number_format($amount, 0, '.', ' ') . " so'm\n" .
            "📁 Kategoriya: {$categoryLabel}\n" .
            "📝 Izoh: {$note}\n" .
            "📅 Sana: Bugun\n\n" .
            "💡 Kategoriya avtomatik tanlandi. O'zgartirish uchun bosing:";

        $keyboard = $this->bot->buildCategoryInlineKeyboard(
            config('telegram.expense_categories'),
            "tx_category:{$transaction->id}"
        );

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showBalance(TelegramUser $user): void
    {
        $totalIncome = $user->transactions()->income()->sum('amount');
        $totalExpense = $user->transactions()->expense()->sum('amount');
        $balance = $totalIncome - $totalExpense;

        $todayIncome = $user->transactions()->income()->forToday()->sum('amount');
        $todayExpense = $user->transactions()->expense()->forToday()->sum('amount');

        $monthIncome = $user->transactions()->income()->forMonth()->sum('amount');
        $monthExpense = $user->transactions()->expense()->forMonth()->sum('amount');

        $message = "💰 <b>Balans</b>\n\n";
        
        $balanceEmoji = $balance >= 0 ? '💚' : '❤️';
        $message .= "{$balanceEmoji} <b>Joriy balans: " . number_format($balance, 0, '.', ' ') . " so'm</b>\n\n";

        $message .= "📅 <b>Bugun:</b>\n";
        $message .= "   💵 Daromad: " . number_format($todayIncome, 0, '.', ' ') . " so'm\n";
        $message .= "   💸 Xarajat: " . number_format($todayExpense, 0, '.', ' ') . " so'm\n\n";

        $message .= "📆 <b>Shu oy:</b>\n";
        $message .= "   💵 Daromad: " . number_format($monthIncome, 0, '.', ' ') . " so'm\n";
        $message .= "   💸 Xarajat: " . number_format($monthExpense, 0, '.', ' ') . " so'm\n";
        $message .= "   📊 Farq: " . number_format($monthIncome - $monthExpense, 0, '.', ' ') . " so'm\n\n";

        $message .= "📊 <b>Umumiy:</b>\n";
        $message .= "   💵 Jami daromad: " . number_format($totalIncome, 0, '.', ' ') . " so'm\n";
        $message .= "   💸 Jami xarajat: " . number_format($totalExpense, 0, '.', ' ') . " so'm";

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showTodayReport(TelegramUser $user): void
    {
        $transactions = $user->transactions()->forToday()->orderBy('created_at', 'desc')->get();
        
        $income = $transactions->where('type', 'income')->sum('amount');
        $expense = $transactions->where('type', 'expense')->sum('amount');

        $message = "📊 <b>Bugungi hisobot</b>\n";
        $message .= "📅 " . now()->format('d.m.Y, l') . "\n\n";

        $message .= "💵 Daromad: " . number_format($income, 0, '.', ' ') . " so'm\n";
        $message .= "💸 Xarajat: " . number_format($expense, 0, '.', ' ') . " so'm\n";
        $message .= "📊 Farq: " . number_format($income - $expense, 0, '.', ' ') . " so'm\n\n";

        if ($transactions->isEmpty()) {
            $message .= "Bugun tranzaksiyalar yo'q.";
        } else {
            $message .= "<b>Tranzaksiyalar:</b>\n";
            foreach ($transactions as $tx) {
                $emoji = $tx->type === 'income' ? '💵' : '💸';
                $sign = $tx->type === 'income' ? '+' : '-';
                $message .= "{$emoji} {$sign}" . number_format($tx->amount, 0, '.', ' ') . " - {$tx->note}\n";
            }
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showMonthReport(TelegramUser $user): void
    {
        $transactions = $user->transactions()->forMonth()->get();
        
        $income = $transactions->where('type', 'income')->sum('amount');
        $expense = $transactions->where('type', 'expense')->sum('amount');

        $expensesByCategory = $transactions
            ->where('type', 'expense')
            ->groupBy('category')
            ->map(fn($items) => $items->sum('amount'))
            ->sortDesc();

        $message = "📊 <b>Oylik hisobot</b>\n";
        $message .= "📆 " . now()->format('F Y') . "\n\n";

        $message .= "💵 Daromad: " . number_format($income, 0, '.', ' ') . " so'm\n";
        $message .= "💸 Xarajat: " . number_format($expense, 0, '.', ' ') . " so'm\n";
        $message .= "📊 Farq: " . number_format($income - $expense, 0, '.', ' ') . " so'm\n\n";

        if ($expensesByCategory->isNotEmpty()) {
            $message .= "<b>Kategoriya bo'yicha xarajatlar:</b>\n";
            $categories = config('telegram.expense_categories');
            
            foreach ($expensesByCategory as $category => $amount) {
                $label = $categories[$category] ?? '📋 Boshqa';
                $percentage = $expense > 0 ? round(($amount / $expense) * 100) : 0;
                $bar = $this->createProgressBar($percentage);
                $message .= "{$label}\n";
                $message .= "   {$bar} " . number_format($amount, 0, '.', ' ') . " ({$percentage}%)\n";
            }
        }

        if ($user->monthly_budget_limit && $expense > $user->monthly_budget_limit) {
            $over = $expense - $user->monthly_budget_limit;
            $message .= "\n⚠️ <b>Byudjetdan " . number_format($over, 0, '.', ' ') . " so'm oshib ketdi!</b>";
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showStatistics(TelegramUser $user): void
    {
        $message = "📊 <b>Statistika va tahlil</b>\n\n";

        $totalTasks = $user->tasks()->count();
        $completedTasks = $user->tasks()->completed()->count();
        $taskCompletion = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

        $message .= "<b>📋 Vazifalar:</b>\n";
        $message .= "   Jami: {$totalTasks}\n";
        $message .= "   Bajarilgan: {$completedTasks} ({$taskCompletion}%)\n";
        $message .= "   {$this->createProgressBar($taskCompletion)}\n\n";

        $totalIncome = $user->transactions()->income()->sum('amount');
        $totalExpense = $user->transactions()->expense()->sum('amount');
        $savingsRate = $totalIncome > 0 ? round((($totalIncome - $totalExpense) / $totalIncome) * 100) : 0;

        $message .= "<b>💰 Moliya:</b>\n";
        $message .= "   Jami daromad: " . number_format($totalIncome, 0, '.', ' ') . " so'm\n";
        $message .= "   Jami xarajat: " . number_format($totalExpense, 0, '.', ' ') . " so'm\n";
        $message .= "   Tejash darajasi: {$savingsRate}%\n";
        $message .= "   {$this->createProgressBar(max(0, $savingsRate))}\n\n";

        $badge = $user->getBadgeInfo();
        $message .= "<b>🎮 O'yin ko'rsatkichlari:</b>\n";
        $message .= "   Nishon: {$badge['name']}\n";
        $message .= "   Ball: {$user->total_points}\n";
        $message .= "   Seriya: {$user->streak_days} kun 🔥\n";
        $message .= "   Bajarilgan vazifalar: {$user->tasks_completed}\n\n";

        $achievements = $user->achievements()->count();
        $totalAchievements = count(\App\Models\UserAchievement::getAvailableAchievements());
        $message .= "<b>🏆 Yutuqlar:</b> {$achievements}/{$totalAchievements}";

        $keyboard = [
            [
                ['text' => '📈 Batafsil grafiklar', 'callback_data' => 'stats_charts'],
                ['text' => '🏆 Yutuqlar', 'callback_data' => 'stats_achievements'],
            ],
            [
                ['text' => '📊 Moliyaviy prognoz', 'callback_data' => 'stats_forecast'],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showCurrencyRates(TelegramUser $user): void
    {
        $this->bot->sendChatAction($user->telegram_id, 'typing');

        $rates = $this->fetchCurrencyRates();

        $message = "💱 <b>Valyuta kurslari</b>\n";
        $message .= "📅 " . now()->format('d.m.Y H:i') . "\n\n";

        if ($rates) {
            $message .= "🇺🇸 1 USD = \n";
            $message .= "   🇺🇿 " . number_format($rates['UZS'] ?? 12500, 0, '.', ' ') . " so'm\n";
            $message .= "   🇪🇺 " . number_format($rates['EUR'] ?? 0, 4) . " EUR\n";
            $message .= "   🇷🇺 " . number_format($rates['RUB'] ?? 0, 2) . " RUB\n";
        } else {
            $message .= "Kurslarni olishda xatolik.\n";
            $message .= "Keshlanган kurslar ishlatilmoqda.";
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showAnalysis(TelegramUser $user): void
    {
        $message = "📉 <b>Moliyaviy tahlil</b>\n\n";

        $lastMonth = now()->subMonth();
        $lastMonthExpense = $user->transactions()
            ->expense()
            ->whereMonth('date', $lastMonth->month)
            ->whereYear('date', $lastMonth->year)
            ->sum('amount');

        $thisMonthExpense = $user->getMonthExpenses();

        $change = $lastMonthExpense > 0 
            ? round((($thisMonthExpense - $lastMonthExpense) / $lastMonthExpense) * 100) 
            : 0;

        $changeEmoji = $change > 0 ? '📈' : ($change < 0 ? '📉' : '➡️');
        $changeText = $change > 0 ? "+{$change}%" : "{$change}%";

        $message .= "<b>📊 Xarajat tendensiyasi:</b>\n";
        $message .= "O'tgan oy: " . number_format($lastMonthExpense, 0, '.', ' ') . " so'm\n";
        $message .= "Shu oy: " . number_format($thisMonthExpense, 0, '.', ' ') . " so'm\n";
        $message .= "O'zgarish: {$changeEmoji} {$changeText}\n\n";

        $topCategories = $user->transactions()
            ->expense()
            ->forMonth()
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(3)
            ->get();

        if ($topCategories->isNotEmpty()) {
            $message .= "<b>🏆 Eng ko'p xarajat kategoriyalari:</b>\n";
            $categories = config('telegram.expense_categories');
            foreach ($topCategories as $i => $cat) {
                $rank = ['🥇', '🥈', '🥉'][$i];
                $label = $categories[$cat->category] ?? '📋 Boshqa';
                $message .= "{$rank} {$label}: " . number_format($cat->total, 0, '.', ' ') . " so'm\n";
            }
            $message .= "\n";
        }

        $avgDailyExpense = $user->transactions()
            ->expense()
            ->forMonth()
            ->avg('amount') ?? 0;

        $daysLeft = now()->daysInMonth - now()->day;
        $projected = $thisMonthExpense + ($avgDailyExpense * $daysLeft);

        $message .= "<b>🔮 Oy oxiri prognozi:</b>\n";
        $message .= "Taxminiy xarajat: " . number_format($projected, 0, '.', ' ') . " so'm\n";

        if ($user->monthly_budget_limit) {
            $diff = $user->monthly_budget_limit - $projected;
            if ($diff >= 0) {
                $message .= "✅ Byudjet ichida (+" . number_format($diff, 0, '.', ' ') . " so'm)";
            } else {
                $message .= "⚠️ Byudjetdan oshadi (-" . number_format(abs($diff), 0, '.', ' ') . " so'm)";
            }
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function setCategory(TelegramUser $user, string $value, ?int $messageId): void
    {
        [$txId, $category] = explode(':', $value, 2) + [null, null];

        if (!$txId || !$category) {
            return;
        }

        $transaction = $user->transactions()->find($txId);
        if (!$transaction) {
            return;
        }

        $transaction->category = $category;
        $transaction->auto_categorized = false;
        $transaction->save();

        $categories = $transaction->type === 'income' 
            ? config('telegram.income_categories')
            : config('telegram.expense_categories');

        $message = "✅ Kategoriya o'zgartirildi: {$categories[$category]}";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function confirmTransaction(TelegramUser $user, string $value, ?int $messageId): void
    {
        if ($value === 'cancel') {
            $user->clearState();
            $this->bot->editMessage($user->telegram_id, $messageId, "❌ Tranzaksiya bekor qilindi.");
            return;
        }

        $data = $user->state_data;

        $transaction = Transaction::create([
            'telegram_user_id' => $user->id,
            'type' => $data['type'],
            'amount' => $data['amount'],
            'currency' => $user->currency,
            'category' => $data['category'] ?? 'other',
            'note' => $data['note'] ?? null,
            'date' => $data['date'] ?? today(),
        ]);

        $user->clearState();

        if ($transaction->type === 'expense') {
            $this->checkBudgetAlerts($user);
        }

        $emoji = $transaction->type === 'income' ? '💵' : '💸';
        $typeText = $transaction->type === 'income' ? 'Daromad' : 'Xarajat';
        
        $message = "{$emoji} <b>{$typeText} qo'shildi!</b>\n\n" .
            "💰 Summa: " . number_format($transaction->amount, 0, '.', ' ') . " so'm\n" .
            "📁 Kategoriya: {$transaction->getCategoryEmoji()}\n" .
            ($transaction->note ? "📝 Izoh: {$transaction->note}\n" : "") .
            "📅 Sana: {$transaction->date->format('d.m.Y')}";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function deleteTransaction(TelegramUser $user, string $txId, ?int $messageId): void
    {
        $transaction = $user->transactions()->find($txId);
        
        if (!$transaction) {
            $this->bot->sendMessage($user->telegram_id, "❌ Tranzaksiya topilmadi.");
            return;
        }

        $transaction->delete();

        $message = "🗑️ Tranzaksiya o'chirildi.";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function showTransactionsPage(TelegramUser $user, int $page, ?int $messageId): void
    {
        $perPage = 10;
        $transactions = $user->transactions()
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        $message = "📋 <b>Tranzaksiyalar</b> ({$page}-sahifa)\n\n";

        foreach ($transactions as $tx) {
            $emoji = $tx->type === 'income' ? '💵' : '💸';
            $message .= "{$emoji} " . number_format($tx->amount, 0, '.', ' ') . " - {$tx->category}\n";
            $message .= "   📅 {$tx->date->format('d.m')} | {$tx->note}\n";
        }

        $keyboard = [];
        $navRow = [];

        if ($page > 1) {
            $navRow[] = ['text' => '◀️ Oldingi', 'callback_data' => 'page:transactions_' . ($page - 1)];
        }
        if ($page < $transactions->lastPage()) {
            $navRow[] = ['text' => 'Keyingi ▶️', 'callback_data' => 'page:transactions_' . ($page + 1)];
        }

        if (!empty($navRow)) {
            $keyboard[] = $navRow;
        }

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    protected function checkBudgetAlerts(TelegramUser $user): void
    {
        if (!$user->budget_alerts) {
            return;
        }

        $alerts = [];

        if ($user->daily_budget_limit) {
            $todayExpense = $user->getTodayExpenses();
            if ($todayExpense > $user->daily_budget_limit) {
                $over = $todayExpense - $user->daily_budget_limit;
                $alerts[] = "⚠️ Kunlik byudjet " . number_format($over, 0, '.', ' ') . " so'mga oshib ketdi";
            } elseif ($todayExpense > $user->daily_budget_limit * 0.8) {
                $alerts[] = "⚡ Kunlik byudjet limitiga yaqinlashyapsiz (80%+)";
            }
        }

        if ($user->monthly_budget_limit) {
            $monthExpense = $user->getMonthExpenses();
            if ($monthExpense > $user->monthly_budget_limit) {
                $over = $monthExpense - $user->monthly_budget_limit;
                $alerts[] = "⚠️ Oylik byudjet " . number_format($over, 0, '.', ' ') . " so'mga oshib ketdi";
            } elseif ($monthExpense > $user->monthly_budget_limit * 0.9) {
                $alerts[] = "⚡ Oylik byudjet limitiga yaqinlashyapsiz (90%+)";
            }
        }

        if (!empty($alerts)) {
            $message = "🚨 <b>Byudjet ogohlantirishi!</b>\n\n" . implode("\n", $alerts);
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    protected function fetchCurrencyRates(): ?array
    {
        try {
            $response = Http::get("https://api.exchangerate-api.com/v4/latest/USD");
            
            if ($response->successful()) {
                $data = $response->json();
                
                foreach (['EUR', 'RUB', 'UZS'] as $currency) {
                    if (isset($data['rates'][$currency])) {
                        CurrencyRate::updateOrCreate(
                            ['from_currency' => 'USD', 'to_currency' => $currency, 'date' => today()],
                            ['rate' => $data['rates'][$currency]]
                        );
                    }
                }
                
                return $data['rates'];
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        return null;
    }

    protected function createProgressBar(int $percentage, int $length = 10): string
    {
        $filled = (int)round($percentage / 100 * $length);
        $empty = $length - $filled;
        
        return str_repeat('█', $filled) . str_repeat('░', $empty);
    }
}
