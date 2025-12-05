<?php

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramUser;
use App\Models\ChatHistory;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Support\Facades\Http;

class AIHandler
{
    protected TelegramBotService $bot;

    public function __construct(TelegramBotService $bot)
    {
        $this->bot = $bot;
    }

    public function showAIMenu(TelegramUser $user): void
    {
        $message = "🤖 <b>AI Assistant</b>\n\n" .
            "I can help you with:\n\n" .
            "📋 <b>Tasks:</b> \"What's my most important task today?\"\n" .
            "💰 <b>Finance:</b> \"How much did I spend on food this month?\"\n" .
            "📊 <b>Analysis:</b> \"Why is my spending increasing?\"\n" .
            "💡 <b>Advice:</b> \"How can I save more money?\"\n" .
            "📅 <b>Planning:</b> \"Help me plan my week\"\n\n" .
            "Just type your question or choose an option:";

        $keyboard = [
            [
                ['text' => '📋 Task Analysis', 'callback_data' => 'ai_task_analysis'],
                ['text' => '💰 Finance Analysis', 'callback_data' => 'ai_finance_analysis'],
            ],
            [
                ['text' => '📊 Weekly Report', 'callback_data' => 'ai_weekly_report'],
                ['text' => '💡 Get Recommendations', 'callback_data' => 'ai_recommendations'],
            ],
            [
                ['text' => '🔙 Back to Menu', 'callback_data' => 'main_menu'],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function startChat(TelegramUser $user, string $question = ''): void
    {
        if (empty($question)) {
            $user->setState('ai_chat', []);
            $this->bot->sendMessage(
                $user->telegram_id,
                "🤖 <b>AI Assistant</b>\n\n" .
                "What would you like to know? Ask me anything about your tasks, finances, or goals.\n\n" .
                "Type /cancel to exit chat mode."
            );
            return;
        }

        $this->processAIQuery($user, $question);
    }

    public function analyzeMessage(TelegramUser $user, string $message): void
    {
        // Analyze intent
        $intent = $this->detectIntent($message);

        match ($intent) {
            'task_query' => $this->handleTaskQuery($user, $message),
            'finance_query' => $this->handleFinanceQuery($user, $message),
            'debt_query' => $this->handleDebtQuery($user, $message),
            'advice' => $this->handleAdviceRequest($user, $message),
            default => $this->processAIQuery($user, $message),
        };
    }

    public function processAIQuery(TelegramUser $user, string $query): void
    {
        $this->bot->sendChatAction($user->telegram_id, 'typing');

        // Build context from user data
        $context = $this->buildUserContext($user);

        // Store user message
        ChatHistory::create([
            'telegram_user_id' => $user->id,
            'role' => 'user',
            'message' => $query,
        ]);

        // Generate response
        $response = $this->generateAIResponse($user, $query, $context);

        // Store AI response
        ChatHistory::create([
            'telegram_user_id' => $user->id,
            'role' => 'assistant',
            'message' => $response,
        ]);

        $this->bot->sendMessage($user->telegram_id, $response);
    }

    protected function detectIntent(string $message): string
    {
        $message = mb_strtolower($message);

        $taskKeywords = ['task', 'todo', 'do today', 'plan', 'important', 'priority', 'deadline'];
        $financeKeywords = ['spend', 'expense', 'income', 'money', 'budget', 'balance', 'save', 'cost'];
        $debtKeywords = ['debt', 'owe', 'lend', 'borrow', 'pay back'];
        $adviceKeywords = ['advice', 'recommend', 'suggest', 'help', 'how can i', 'should i'];

        foreach ($taskKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return 'task_query';
            }
        }

        foreach ($financeKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return 'finance_query';
            }
        }

        foreach ($debtKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return 'debt_query';
            }
        }

        foreach ($adviceKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return 'advice';
            }
        }

        return 'general';
    }

    protected function handleTaskQuery(TelegramUser $user, string $query): void
    {
        $tasks = $user->tasks()->pending()->orderBy('date')->get();
        $todayTasks = $tasks->where('date', today());
        $highPriority = $tasks->where('priority', 'high');
        $overdue = $tasks->filter(fn($t) => $t->isOverdue());

        $response = "📋 <b>Task Analysis</b>\n\n";

        if ($highPriority->isNotEmpty()) {
            $response .= "🔴 <b>High Priority Tasks:</b>\n";
            foreach ($highPriority->take(3) as $task) {
                $response .= "• {$task->title}";
                if ($task->date) {
                    $response .= " (due: {$task->date->format('M j')})";
                }
                $response .= "\n";
            }
            $response .= "\n";
        }

        if ($todayTasks->isNotEmpty()) {
            $response .= "📅 <b>Today's Tasks:</b> {$todayTasks->count()}\n";
            foreach ($todayTasks->take(3) as $task) {
                $response .= "• {$task->getPriorityEmoji()} {$task->title}\n";
            }
            $response .= "\n";
        }

        if ($overdue->isNotEmpty()) {
            $response .= "⚠️ <b>Overdue:</b> {$overdue->count()} tasks\n\n";
        }

        // AI recommendation
        $response .= "💡 <b>Recommendation:</b>\n";
        if ($highPriority->isNotEmpty()) {
            $mostImportant = $highPriority->first();
            $response .= "Focus on \"{$mostImportant->title}\" first - it's high priority";
            if ($mostImportant->date?->isToday()) {
                $response .= " and due today";
            }
            $response .= ".";
        } elseif ($todayTasks->isNotEmpty()) {
            $response .= "You have {$todayTasks->count()} tasks for today. Start with the morning to tackle difficult ones when your energy is highest.";
        } else {
            $response .= "No urgent tasks! Consider planning ahead or reviewing your goals.";
        }

        $this->bot->sendMessage($user->telegram_id, $response);
    }

    protected function handleFinanceQuery(TelegramUser $user, string $query): void
    {
        $todayExpense = $user->getTodayExpenses();
        $monthExpense = $user->getMonthExpenses();
        $balance = $user->getBalance();

        // Category breakdown
        $categories = $user->transactions()
            ->expense()
            ->forMonth()
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $response = "💰 <b>Financial Analysis</b>\n\n";

        $response .= "📊 <b>Overview:</b>\n";
        $response .= "• Balance: \$" . number_format($balance, 2) . "\n";
        $response .= "• Today's spending: \$" . number_format($todayExpense, 2) . "\n";
        $response .= "• Month's spending: \$" . number_format($monthExpense, 2) . "\n\n";

        if ($categories->isNotEmpty()) {
            $response .= "📁 <b>Top Categories:</b>\n";
            $expenseCategories = config('telegram.expense_categories');
            foreach ($categories->take(3) as $cat) {
                $label = $expenseCategories[$cat->category] ?? $cat->category;
                $response .= "• {$label}: \$" . number_format($cat->total, 2) . "\n";
            }
            $response .= "\n";
        }

        // Budget check
        if ($user->monthly_budget_limit) {
            $remaining = $user->monthly_budget_limit - $monthExpense;
            $percentage = round(($monthExpense / $user->monthly_budget_limit) * 100);
            
            $response .= "📈 <b>Budget Status:</b>\n";
            $response .= "• Used: {$percentage}% of monthly budget\n";
            
            if ($remaining > 0) {
                $daysLeft = now()->daysInMonth - now()->day;
                $dailyBudget = $daysLeft > 0 ? $remaining / $daysLeft : 0;
                $response .= "• Remaining: \$" . number_format($remaining, 2) . "\n";
                $response .= "• Daily budget: \$" . number_format($dailyBudget, 2) . "/day\n";
            } else {
                $response .= "• ⚠️ Over budget by \$" . number_format(abs($remaining), 2) . "\n";
            }
        }

        $response .= "\n💡 <b>Tip:</b> ";
        if ($categories->isNotEmpty()) {
            $topCategory = $categories->first();
            $response .= "Your highest spending is on " . ($expenseCategories[$topCategory->category] ?? $topCategory->category) . 
                ". Consider setting a specific limit for this category.";
        } else {
            $response .= "Start tracking your expenses to get personalized insights!";
        }

        $this->bot->sendMessage($user->telegram_id, $response);
    }

    protected function handleDebtQuery(TelegramUser $user, string $query): void
    {
        $givenDebts = $user->debts()->given()->active()->get();
        $receivedDebts = $user->debts()->received()->active()->get();
        $overdueDebts = $user->debts()->overdue()->get();

        $givenTotal = $givenDebts->sum('amount') - $givenDebts->sum('amount_paid');
        $receivedTotal = $receivedDebts->sum('amount') - $receivedDebts->sum('amount_paid');

        $response = "💳 <b>Debt Analysis</b>\n\n";

        $response .= "📤 <b>Money owed to you:</b> \$" . number_format($givenTotal, 2) . "\n";
        if ($givenDebts->isNotEmpty()) {
            foreach ($givenDebts->take(3) as $debt) {
                $response .= "   • {$debt->person_name}: {$debt->getFormattedRemainingAmount()}\n";
            }
        }

        $response .= "\n📥 <b>Money you owe:</b> \$" . number_format($receivedTotal, 2) . "\n";
        if ($receivedDebts->isNotEmpty()) {
            foreach ($receivedDebts->take(3) as $debt) {
                $response .= "   • {$debt->person_name}: {$debt->getFormattedRemainingAmount()}\n";
            }
        }

        if ($overdueDebts->isNotEmpty()) {
            $response .= "\n⚠️ <b>Overdue:</b> {$overdueDebts->count()} debts\n";
        }

        $response .= "\n💡 <b>Recommendation:</b> ";
        if ($overdueDebts->isNotEmpty()) {
            $response .= "You have overdue debts. Consider sending reminders or making payments to avoid complications.";
        } elseif ($receivedTotal > $givenTotal) {
            $response .= "Focus on paying off your debts to improve your financial position.";
        } else {
            $response .= "Your debt situation looks good! Keep tracking and follow up on money owed to you.";
        }

        $this->bot->sendMessage($user->telegram_id, $response);
    }

    protected function handleAdviceRequest(TelegramUser $user, string $query): void
    {
        $context = $this->buildUserContext($user);
        
        $response = "💡 <b>Personalized Recommendations</b>\n\n";

        // Task advice
        $pendingTasks = $user->tasks()->pending()->count();
        $completionRate = $user->tasks_completed > 0 
            ? round(($user->tasks()->completed()->count() / $user->tasks()->count()) * 100) 
            : 0;

        $response .= "<b>📋 Tasks:</b>\n";
        if ($completionRate < 50) {
            $response .= "• Your completion rate is {$completionRate}%. Try breaking tasks into smaller subtasks.\n";
        } elseif ($completionRate > 80) {
            $response .= "• Excellent {$completionRate}% completion rate! Keep up the great work.\n";
        }
        
        if ($pendingTasks > 10) {
            $response .= "• You have {$pendingTasks} pending tasks. Consider prioritizing and delegating.\n";
        }

        $response .= "\n<b>💰 Finance:</b>\n";
        $monthExpense = $user->getMonthExpenses();
        $lastMonthExpense = $user->transactions()
            ->expense()
            ->whereMonth('date', now()->subMonth()->month)
            ->sum('amount');

        if ($lastMonthExpense > 0 && $monthExpense > $lastMonthExpense * 1.2) {
            $increase = round((($monthExpense - $lastMonthExpense) / $lastMonthExpense) * 100);
            $response .= "• Spending increased by {$increase}% this month. Review your expenses.\n";
        }

        if (!$user->monthly_budget_limit) {
            $response .= "• Set a monthly budget to better control your spending.\n";
        }

        $response .= "\n<b>🎯 Goals:</b>\n";
        $response .= "• Maintain your {$user->streak_days}-day streak to build strong habits.\n";
        
        $nextBadge = $this->getNextBadgeProgress($user);
        if ($nextBadge) {
            $response .= "• {$nextBadge}\n";
        }

        $this->bot->sendMessage($user->telegram_id, $response);
    }

    protected function buildUserContext(TelegramUser $user): array
    {
        return [
            'user_name' => $user->getDisplayName(),
            'tasks_pending' => $user->tasks()->pending()->count(),
            'tasks_today' => $user->tasks()->forToday()->count(),
            'tasks_completed_total' => $user->tasks_completed,
            'streak_days' => $user->streak_days,
            'total_points' => $user->total_points,
            'balance' => $user->getBalance(),
            'month_expenses' => $user->getMonthExpenses(),
            'active_debts_given' => $user->getActiveDebtsTotal('given'),
            'active_debts_received' => $user->getActiveDebtsTotal('received'),
            'budget_limit' => $user->monthly_budget_limit,
        ];
    }

    protected function generateAIResponse(TelegramUser $user, string $query, array $context): string
    {
        $apiKey = config('telegram.openai_api_key');

        if (!$apiKey) {
            // Fallback to rule-based responses
            return $this->generateFallbackResponse($query, $context);
        }

        try {
            $systemPrompt = "You are a helpful personal assistant for task and finance management. " .
                "Be concise, friendly, and actionable. Use emojis sparingly. " .
                "Here's the user's context: " . json_encode($context);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $query],
                ],
                'max_tokens' => 500,
                'temperature' => 0.7,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['choices'][0]['message']['content'] ?? $this->generateFallbackResponse($query, $context);
            }
        } catch (\Exception $e) {
            // Log error and fall back
        }

        return $this->generateFallbackResponse($query, $context);
    }

    protected function generateFallbackResponse(string $query, array $context): string
    {
        $query = mb_strtolower($query);

        if (str_contains($query, 'important') || str_contains($query, 'priority')) {
            return "📋 Based on your data:\n\n" .
                "You have {$context['tasks_today']} tasks for today.\n" .
                "Focus on high-priority tasks first, especially in the morning when your energy is highest.\n\n" .
                "💡 Tip: Use the /today command to see all today's tasks.";
        }

        if (str_contains($query, 'spend') || str_contains($query, 'expense')) {
            $spent = number_format($context['month_expenses'], 2);
            return "💰 This month you've spent \${$spent}.\n\n" .
                ($context['budget_limit'] 
                    ? "Your budget limit is \${$context['budget_limit']}."
                    : "Consider setting a monthly budget to track your spending better.");
        }

        if (str_contains($query, 'balance')) {
            return "💵 Your current balance is \$" . number_format($context['balance'], 2) . ".\n\n" .
                "Use /balance for a detailed breakdown.";
        }

        return "🤖 I understand you're asking about \"{$query}\".\n\n" .
            "Here's what I can help with:\n" .
            "• Task management and planning\n" .
            "• Expense and income tracking\n" .
            "• Debt management\n" .
            "• Personalized recommendations\n\n" .
            "Try asking something like:\n" .
            "• \"What should I focus on today?\"\n" .
            "• \"How much did I spend this month?\"\n" .
            "• \"Give me financial advice\"";
    }

    protected function getNextBadgeProgress(TelegramUser $user): ?string
    {
        $badges = config('telegram.gamification.badges');
        $currentPoints = $user->total_points;

        foreach ($badges as $key => $badge) {
            if ($badge['points'] > $currentPoints) {
                $needed = $badge['points'] - $currentPoints;
                return "Earn {$needed} more points to unlock {$badge['name']}!";
            }
        }

        return null;
    }
}

