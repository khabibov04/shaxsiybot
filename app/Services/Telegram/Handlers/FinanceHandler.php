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
            "💵 <b>Add Income</b>\n\n" .
            "Enter the amount:\n\n" .
            "💡 Example: <code>1500</code> or <code>1500.50</code>"
        );
    }

    public function startAddExpense(TelegramUser $user): void
    {
        $user->setState('adding_transaction', ['type' => 'expense', 'step' => 'amount']);
        
        $this->bot->sendMessage(
            $user->telegram_id,
            "💸 <b>Add Expense</b>\n\n" .
            "Enter the amount:\n\n" .
            "💡 Example: <code>50</code> or <code>50.99</code>"
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
        $categoryLabel = $categories[$transaction->category] ?? '📋 Other';

        $message = "💸 <b>Expense Added!</b>\n\n" .
            "💰 Amount: {$transaction->getFormattedAmount()}\n" .
            "📁 Category: {$categoryLabel}\n" .
            "📝 Note: {$note}\n" .
            "📅 Date: Today\n\n" .
            "💡 Category was auto-assigned. Tap to change:";

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

        $currency = $user->currency;
        $symbol = match($currency) {
            'USD' => '$',
            'EUR' => '€',
            'RUB' => '₽',
            default => $currency,
        };

        $message = "💰 <b>Balance Overview</b>\n\n";
        
        $balanceEmoji = $balance >= 0 ? '💚' : '❤️';
        $message .= "{$balanceEmoji} <b>Current Balance: {$symbol}" . number_format($balance, 2) . "</b>\n\n";

        $message .= "📅 <b>Today:</b>\n";
        $message .= "   💵 Income: {$symbol}" . number_format($todayIncome, 2) . "\n";
        $message .= "   💸 Expense: {$symbol}" . number_format($todayExpense, 2) . "\n\n";

        $message .= "📆 <b>This Month:</b>\n";
        $message .= "   💵 Income: {$symbol}" . number_format($monthIncome, 2) . "\n";
        $message .= "   💸 Expense: {$symbol}" . number_format($monthExpense, 2) . "\n";
        $message .= "   📊 Net: {$symbol}" . number_format($monthIncome - $monthExpense, 2) . "\n\n";

        $message .= "📊 <b>All Time:</b>\n";
        $message .= "   💵 Total Income: {$symbol}" . number_format($totalIncome, 2) . "\n";
        $message .= "   💸 Total Expense: {$symbol}" . number_format($totalExpense, 2);

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showTodayReport(TelegramUser $user): void
    {
        $transactions = $user->transactions()->forToday()->orderBy('created_at', 'desc')->get();
        
        $income = $transactions->where('type', 'income')->sum('amount');
        $expense = $transactions->where('type', 'expense')->sum('amount');

        $message = "📊 <b>Today's Report</b>\n";
        $message .= "📅 " . now()->format('l, F j, Y') . "\n\n";

        $message .= "💵 Income: \$" . number_format($income, 2) . "\n";
        $message .= "💸 Expense: \$" . number_format($expense, 2) . "\n";
        $message .= "📊 Net: \$" . number_format($income - $expense, 2) . "\n\n";

        if ($transactions->isEmpty()) {
            $message .= "No transactions today.";
        } else {
            $message .= "<b>Transactions:</b>\n";
            foreach ($transactions as $tx) {
                $emoji = $tx->type === 'income' ? '💵' : '💸';
                $sign = $tx->type === 'income' ? '+' : '-';
                $message .= "{$emoji} {$sign}\${$tx->amount} - {$tx->note}\n";
            }
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showMonthReport(TelegramUser $user): void
    {
        $transactions = $user->transactions()->forMonth()->get();
        
        $income = $transactions->where('type', 'income')->sum('amount');
        $expense = $transactions->where('type', 'expense')->sum('amount');

        // Group expenses by category
        $expensesByCategory = $transactions
            ->where('type', 'expense')
            ->groupBy('category')
            ->map(fn($items) => $items->sum('amount'))
            ->sortDesc();

        $message = "📊 <b>Monthly Report</b>\n";
        $message .= "📆 " . now()->format('F Y') . "\n\n";

        $message .= "💵 Income: \$" . number_format($income, 2) . "\n";
        $message .= "💸 Expense: \$" . number_format($expense, 2) . "\n";
        $message .= "📊 Net: \$" . number_format($income - $expense, 2) . "\n\n";

        if ($expensesByCategory->isNotEmpty()) {
            $message .= "<b>Expenses by Category:</b>\n";
            $categories = config('telegram.expense_categories');
            
            foreach ($expensesByCategory as $category => $amount) {
                $label = $categories[$category] ?? '📋 Other';
                $percentage = $expense > 0 ? round(($amount / $expense) * 100) : 0;
                $bar = $this->createProgressBar($percentage);
                $message .= "{$label}\n";
                $message .= "   {$bar} \${$amount} ({$percentage}%)\n";
            }
        }

        // Budget alerts
        if ($user->monthly_budget_limit && $expense > $user->monthly_budget_limit) {
            $over = $expense - $user->monthly_budget_limit;
            $message .= "\n⚠️ <b>Over budget by \$" . number_format($over, 2) . "!</b>";
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showStatistics(TelegramUser $user): void
    {
        $message = "📊 <b>Statistics & Analysis</b>\n\n";

        // Task stats
        $totalTasks = $user->tasks()->count();
        $completedTasks = $user->tasks()->completed()->count();
        $taskCompletion = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

        $message .= "<b>📋 Tasks:</b>\n";
        $message .= "   Total: {$totalTasks}\n";
        $message .= "   Completed: {$completedTasks} ({$taskCompletion}%)\n";
        $message .= "   {$this->createProgressBar($taskCompletion)}\n\n";

        // Finance stats
        $totalIncome = $user->transactions()->income()->sum('amount');
        $totalExpense = $user->transactions()->expense()->sum('amount');
        $savingsRate = $totalIncome > 0 ? round((($totalIncome - $totalExpense) / $totalIncome) * 100) : 0;

        $message .= "<b>💰 Finance:</b>\n";
        $message .= "   Total Income: \$" . number_format($totalIncome, 2) . "\n";
        $message .= "   Total Expense: \$" . number_format($totalExpense, 2) . "\n";
        $message .= "   Savings Rate: {$savingsRate}%\n";
        $message .= "   {$this->createProgressBar(max(0, $savingsRate))}\n\n";

        // Gamification stats
        $badge = $user->getBadgeInfo();
        $message .= "<b>🎮 Progress:</b>\n";
        $message .= "   Badge: {$badge['name']}\n";
        $message .= "   Points: {$user->total_points}\n";
        $message .= "   Streak: {$user->streak_days} days 🔥\n";
        $message .= "   Tasks Done: {$user->tasks_completed}\n\n";

        // Achievements
        $achievements = $user->achievements()->count();
        $totalAchievements = count(\App\Models\UserAchievement::getAvailableAchievements());
        $message .= "<b>🏆 Achievements:</b> {$achievements}/{$totalAchievements}";

        $keyboard = [
            [
                ['text' => '📈 Detailed Charts', 'callback_data' => 'stats_charts'],
                ['text' => '🏆 Achievements', 'callback_data' => 'stats_achievements'],
            ],
            [
                ['text' => '📊 Financial Forecast', 'callback_data' => 'stats_forecast'],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showCurrencyRates(TelegramUser $user): void
    {
        $this->bot->sendChatAction($user->telegram_id, 'typing');

        // Try to fetch fresh rates
        $rates = $this->fetchCurrencyRates();

        $message = "💱 <b>Currency Rates</b>\n";
        $message .= "📅 " . now()->format('M j, Y H:i') . "\n\n";

        if ($rates) {
            $message .= "🇺🇸 1 USD = \n";
            $message .= "   🇪🇺 " . number_format($rates['EUR'] ?? 0, 4) . " EUR\n";
            $message .= "   🇷🇺 " . number_format($rates['RUB'] ?? 0, 2) . " RUB\n";
            $message .= "   🇺🇿 " . number_format($rates['UZS'] ?? 0, 2) . " UZS\n";
        } else {
            // Fallback to stored rates
            $message .= "Unable to fetch live rates.\n";
            $message .= "Using cached rates (may be outdated).";
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showAnalysis(TelegramUser $user): void
    {
        $message = "📉 <b>Financial Analysis</b>\n\n";

        // Spending trends
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

        $message .= "<b>📊 Spending Trend:</b>\n";
        $message .= "Last month: \$" . number_format($lastMonthExpense, 2) . "\n";
        $message .= "This month: \$" . number_format($thisMonthExpense, 2) . "\n";
        $message .= "Change: {$changeEmoji} {$changeText}\n\n";

        // Top spending categories this month
        $topCategories = $user->transactions()
            ->expense()
            ->forMonth()
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(3)
            ->get();

        if ($topCategories->isNotEmpty()) {
            $message .= "<b>🏆 Top Spending Categories:</b>\n";
            $categories = config('telegram.expense_categories');
            foreach ($topCategories as $i => $cat) {
                $rank = ['🥇', '🥈', '🥉'][$i];
                $label = $categories[$cat->category] ?? '📋 Other';
                $message .= "{$rank} {$label}: \$" . number_format($cat->total, 2) . "\n";
            }
            $message .= "\n";
        }

        // Forecast
        $avgDailyExpense = $user->transactions()
            ->expense()
            ->forMonth()
            ->avg('amount') ?? 0;

        $daysLeft = now()->daysInMonth - now()->day;
        $projected = $thisMonthExpense + ($avgDailyExpense * $daysLeft);

        $message .= "<b>🔮 Month-End Forecast:</b>\n";
        $message .= "Projected spending: \$" . number_format($projected, 2) . "\n";

        if ($user->monthly_budget_limit) {
            $diff = $user->monthly_budget_limit - $projected;
            if ($diff >= 0) {
                $message .= "✅ Within budget (+\$" . number_format($diff, 2) . ")";
            } else {
                $message .= "⚠️ Over budget (-\$" . number_format(abs($diff), 2) . ")";
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

        $message = "✅ Category updated to: {$categories[$category]}";

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
            $this->bot->editMessage($user->telegram_id, $messageId, "❌ Transaction cancelled.");
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

        // Check budget alerts
        if ($transaction->type === 'expense') {
            $this->checkBudgetAlerts($user);
        }

        $emoji = $transaction->type === 'income' ? '💵' : '💸';
        $message = "{$emoji} <b>" . ucfirst($transaction->type) . " Added!</b>\n\n" .
            "💰 Amount: {$transaction->getFormattedAmount()}\n" .
            "📁 Category: {$transaction->getCategoryEmoji()}\n" .
            ($transaction->note ? "📝 Note: {$transaction->note}\n" : "") .
            "📅 Date: {$transaction->date->format('M j, Y')}";

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
            $this->bot->sendMessage($user->telegram_id, "❌ Transaction not found.");
            return;
        }

        $transaction->delete();

        $message = "🗑️ Transaction deleted.";

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

        $message = "📋 <b>Transactions</b> (Page {$page})\n\n";

        foreach ($transactions as $tx) {
            $emoji = $tx->type === 'income' ? '💵' : '💸';
            $message .= "{$emoji} {$tx->getFormattedAmount()} - {$tx->category}\n";
            $message .= "   📅 {$tx->date->format('M j')} | {$tx->note}\n";
        }

        $keyboard = [];
        $navRow = [];

        if ($page > 1) {
            $navRow[] = ['text' => '◀️ Prev', 'callback_data' => 'page:transactions_' . ($page - 1)];
        }
        if ($page < $transactions->lastPage()) {
            $navRow[] = ['text' => 'Next ▶️', 'callback_data' => 'page:transactions_' . ($page + 1)];
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

        // Daily budget
        if ($user->daily_budget_limit) {
            $todayExpense = $user->getTodayExpenses();
            if ($todayExpense > $user->daily_budget_limit) {
                $over = $todayExpense - $user->daily_budget_limit;
                $alerts[] = "⚠️ Daily budget exceeded by \$" . number_format($over, 2);
            } elseif ($todayExpense > $user->daily_budget_limit * 0.8) {
                $alerts[] = "⚡ Approaching daily budget limit (80%+)";
            }
        }

        // Monthly budget
        if ($user->monthly_budget_limit) {
            $monthExpense = $user->getMonthExpenses();
            if ($monthExpense > $user->monthly_budget_limit) {
                $over = $monthExpense - $user->monthly_budget_limit;
                $alerts[] = "⚠️ Monthly budget exceeded by \$" . number_format($over, 2);
            } elseif ($monthExpense > $user->monthly_budget_limit * 0.9) {
                $alerts[] = "⚡ Approaching monthly budget limit (90%+)";
            }
        }

        if (!empty($alerts)) {
            $message = "🚨 <b>Budget Alert!</b>\n\n" . implode("\n", $alerts);
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    protected function fetchCurrencyRates(): ?array
    {
        try {
            $apiKey = config('telegram.currency_api_key');
            
            if ($apiKey) {
                $response = Http::get("https://api.exchangerate-api.com/v4/latest/USD");
                
                if ($response->successful()) {
                    $data = $response->json();
                    
                    // Store rates
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
            }
            
            // Fallback to free API
            $response = Http::get("https://api.exchangerate-api.com/v4/latest/USD");
            if ($response->successful()) {
                return $response->json()['rates'] ?? null;
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

