<?php

namespace App\Services\Telegram\Handlers;

use App\Models\ChatHistory;
use App\Models\TelegramUser;
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
        $message = "🤖 <b>AI Yordamchi</b>\n\n" .
            "Men sizga quyidagilarni tahlil qilishda yordam beraman:\n\n" .
            "📊 Vazifalar samaradorligini tahlil qilish\n" .
            "💰 Moliyaviy holatni baholash\n" .
            "🎯 Shaxsiy maslahatlar berish\n" .
            "💡 Yaxshilash tavsiyalari\n\n" .
            "Tanlang yoki to'g'ridan-to'g'ri savol yozing:";

        $keyboard = [
            [
                ['text' => '📊 Vazifa tahlili', 'callback_data' => 'ai_analyze:tasks'],
                ['text' => '💰 Moliya tahlili', 'callback_data' => 'ai_analyze:finance'],
            ],
            [
                ['text' => '📈 Progress ko\'rish', 'callback_data' => 'ai_analyze:progress'],
                ['text' => '💡 Maslahatlar', 'callback_data' => 'ai_analyze:tips'],
            ],
            [
                ['text' => '💬 Savol berish', 'callback_data' => 'ai_chat:start'],
            ],
            [
                ['text' => '🔙 Orqaga', 'callback_data' => 'main_menu'],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function startChat(TelegramUser $user, string $initialQuestion = ''): void
    {
        $user->setState('ai_chat');

        if ($initialQuestion) {
            $this->processChat($user, $initialQuestion);
            return;
        }

        $this->bot->sendMessage(
            $user->telegram_id,
            "💬 <b>AI Suhbat</b>\n\n" .
            "Savolingizni yozing. Men sizga yordam berishga tayyorman!\n\n" .
            "Suhbatni tugatish uchun /bekor yozing."
        );
    }

    public function analyzeMessage(TelegramUser $user, string $text): void
    {
        $this->bot->sendChatAction($user->telegram_id, 'typing');

        // Check if it's a quick expense format
        if (preg_match('/^(\d+)\s+(.+)$/u', $text, $matches)) {
            return; // This will be handled by FinanceHandler
        }

        // Simple intent detection
        $lowerText = mb_strtolower($text);
        
        $intents = [
            'greeting' => ['salom', 'hello', 'hi', 'assalomu', 'привет'],
            'help' => ['yordam', 'help', 'nima qila', 'qanday'],
            'task' => ['vazifa', 'reja', 'topshiriq', 'qilish kerak'],
            'money' => ['pul', 'moliya', 'xarajat', 'daromad', 'balans'],
            'debt' => ['qarz', 'qarzdor', 'berish', 'olish'],
            'thanks' => ['rahmat', 'tashakkur', 'thanks', 'спасибо'],
        ];

        $detectedIntent = null;
        foreach ($intents as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($lowerText, $keyword)) {
                    $detectedIntent = $intent;
                    break 2;
                }
            }
        }

        $response = match ($detectedIntent) {
            'greeting' => "Salom! 👋 Sizga qanday yordam bera olaman?\n\n" .
                "📋 Vazifalar bilan ishlash\n" .
                "💰 Moliya hisobi\n" .
                "💳 Qarzlarni boshqarish\n\n" .
                "Menyudan tanlang yoki savol yozing!",
            
            'help' => "Yordam kerakmi? Mana asosiy buyruqlar:\n\n" .
                "/vazifa - Yangi vazifa qo'shish\n" .
                "/bugun - Bugungi vazifalar\n" .
                "/balans - Moliyaviy holat\n" .
                "/qarzlar - Qarzlar ro'yxati\n" .
                "/yordam - To'liq yordam",
            
            'task' => "Vazifa bilan bog'liq savol bo'lsa:\n\n" .
                "➕ Yangi vazifa: /vazifa\n" .
                "📋 Bugungilar: /bugun\n" .
                "📅 Haftalik: /hafta\n\n" .
                "Yoki '📋 Vazifalar' tugmasini bosing!",
            
            'money' => "Moliya bilan yordam kerakmi?\n\n" .
                "💵 Daromad qo'shish: /daromad\n" .
                "💸 Xarajat qo'shish: /xarajat\n" .
                "📊 Balans ko'rish: /balans\n\n" .
                "Tez xarajat qo'shish: <code>50000 ovqat</code>",
            
            'debt' => "Qarzlar bilan ishlamoqchimisiz?\n\n" .
                "📤 Qarz berdim\n" .
                "📥 Qarz oldim\n" .
                "📋 Faol qarzlar: /qarzlar\n\n" .
                "'💳 Qarzlar' menyusini oching!",
            
            'thanks' => "Arzimaydi! 😊 Yana yordam kerak bo'lsa, yozing!",
            
            default => $this->generateSmartResponse($user, $text),
        };

        $this->bot->sendMessage($user->telegram_id, $response);

        // Save to chat history
        ChatHistory::create([
            'telegram_user_id' => $user->id,
            'role' => 'user',
            'message' => $text,
        ]);

        ChatHistory::create([
            'telegram_user_id' => $user->id,
            'role' => 'assistant',
            'message' => $response,
        ]);
    }

    public function processChat(TelegramUser $user, string $text): void
    {
        $this->bot->sendChatAction($user->telegram_id, 'typing');

        // Get recent chat history
        $history = ChatHistory::where('telegram_user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->reverse();

        $response = $this->generateSmartResponse($user, $text, $history);

        // Save to history
        ChatHistory::create([
            'telegram_user_id' => $user->id,
            'role' => 'user',
            'message' => $text,
        ]);

        ChatHistory::create([
            'telegram_user_id' => $user->id,
            'role' => 'assistant',
            'message' => $response,
        ]);

        $this->bot->sendMessage($user->telegram_id, $response);
    }

    protected function generateSmartResponse(TelegramUser $user, string $text, $history = null): string
    {
        // Try OpenAI if configured
        $apiKey = config('telegram.openai_api_key');
        
        if ($apiKey) {
            try {
                $response = $this->callOpenAI($user, $text, $history, $apiKey);
                if ($response) {
                    return $response;
                }
            } catch (\Exception $e) {
                // Fall through to basic response
            }
        }

        // Basic smart response without AI
        return $this->getBasicResponse($user, $text);
    }

    protected function callOpenAI(TelegramUser $user, string $text, $history, string $apiKey): ?string
    {
        $systemPrompt = "Sen shaxsiy yordamchi botsanva o'zbek tilida javob berasaning. " .
            "Foydalanuvchining vazifalarini boshqarish, moliyasini kuzatish va qarzlarini " .
            "nazorat qilishda yordam berasaning. Qisqa, aniq va foydali javoblar bering.";

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        if ($history) {
            foreach ($history as $msg) {
                $messages[] = [
                    'role' => $msg->role,
                    'content' => $msg->message,
                ];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $text];

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-3.5-turbo',
            'messages' => $messages,
            'max_tokens' => 500,
            'temperature' => 0.7,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            return $data['choices'][0]['message']['content'] ?? null;
        }

        return null;
    }

    protected function getBasicResponse(TelegramUser $user, string $text): string
    {
        // Gather user context
        $todayTasks = $user->tasks()->forToday()->pending()->count();
        $balance = $user->getBalance();
        $activeDebts = $user->debts()->active()->count();

        $context = [];
        
        if ($todayTasks > 0) {
            $context[] = "📋 Bugun {$todayTasks} ta bajarilmagan vazifangiz bor";
        }
        
        if ($balance < 0) {
            $context[] = "⚠️ Balans manfiy: " . number_format($balance, 0, '.', ' ') . " so'm";
        }
        
        if ($activeDebts > 0) {
            $context[] = "💳 {$activeDebts} ta faol qarzingiz bor";
        }

        $contextText = !empty($context) 
            ? "\n\n<b>Joriy holat:</b>\n" . implode("\n", $context)
            : "";

        return "🤖 Savolingizni tushundim!\n\n" .
            "Hozircha men oddiy savollarga javob beraman. " .
            "Aniqroq yordam uchun menyudan kerakli bo'limni tanlang." .
            $contextText . "\n\n" .
            "💡 Maslahat: /yordam buyrug'i orqali barcha imkoniyatlarni ko'ring!";
    }

    public function analyzeUserData(TelegramUser $user, string $type, ?int $messageId = null): void
    {
        $this->bot->sendChatAction($user->telegram_id, 'typing');

        $message = match ($type) {
            'tasks' => $this->analyzeTasksData($user),
            'finance' => $this->analyzeFinanceData($user),
            'progress' => $this->analyzeProgressData($user),
            'tips' => $this->generateTips($user),
            default => "Noma'lum tahlil turi.",
        };

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    protected function analyzeTasksData(TelegramUser $user): string
    {
        $totalTasks = $user->tasks()->count();
        $completedTasks = $user->tasks()->completed()->count();
        $pendingTasks = $user->tasks()->pending()->count();
        
        $thisWeekTasks = $user->tasks()->forWeek()->get();
        $weekCompleted = $thisWeekTasks->where('status', 'completed')->count();
        $weekTotal = $thisWeekTasks->count();

        $avgRating = $user->tasks()->whereNotNull('rating')->avg('rating') ?? 0;

        $completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

        $message = "📊 <b>Vazifa tahlili</b>\n\n";
        
        $message .= "<b>Umumiy statistika:</b>\n";
        $message .= "📋 Jami vazifalar: {$totalTasks}\n";
        $message .= "✅ Bajarilgan: {$completedTasks} ({$completionRate}%)\n";
        $message .= "⏳ Kutilayotgan: {$pendingTasks}\n";
        $message .= "⭐ O'rtacha baho: " . round($avgRating, 1) . "/5\n\n";

        $message .= "<b>Shu hafta:</b>\n";
        $weekRate = $weekTotal > 0 ? round(($weekCompleted / $weekTotal) * 100) : 0;
        $message .= "📅 Vazifalar: {$weekCompleted}/{$weekTotal} ({$weekRate}%)\n\n";

        // Category analysis
        $byCategory = $user->tasks()->completed()
            ->selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->orderByDesc('count')
            ->limit(3)
            ->get();

        if ($byCategory->isNotEmpty()) {
            $categories = config('telegram.task_categories');
            $message .= "<b>Eng ko'p bajarilgan kategoriyalar:</b>\n";
            foreach ($byCategory as $cat) {
                $label = $categories[$cat->category] ?? $cat->category;
                $message .= "• {$label}: {$cat->count} ta\n";
            }
        }

        // Recommendations
        $message .= "\n<b>💡 Tavsiyalar:</b>\n";
        
        if ($completionRate < 50) {
            $message .= "• Vazifalarni kichikroq bo'laklarga bo'ling\n";
            $message .= "• Muhimlik darajasini to'g'ri belgilang\n";
        } elseif ($completionRate < 80) {
            $message .= "• Yaxshi natija! Seriyani davom ettiring\n";
            $message .= "• Qiyinroq vazifalarni ertalab bajaring\n";
        } else {
            $message .= "• Ajoyib samaradorlik! 🎉\n";
            $message .= "• Yangi maqsadlar qo'ying\n";
        }

        return $message;
    }

    protected function analyzeFinanceData(TelegramUser $user): string
    {
        $totalIncome = $user->transactions()->income()->sum('amount');
        $totalExpense = $user->transactions()->expense()->sum('amount');
        $balance = $totalIncome - $totalExpense;

        $monthIncome = $user->transactions()->income()->forMonth()->sum('amount');
        $monthExpense = $user->transactions()->expense()->forMonth()->sum('amount');

        $savingsRate = $totalIncome > 0 
            ? round((($totalIncome - $totalExpense) / $totalIncome) * 100) 
            : 0;

        $message = "💰 <b>Moliya tahlili</b>\n\n";

        $message .= "<b>Umumiy holat:</b>\n";
        $message .= "💵 Jami daromad: " . number_format($totalIncome, 0, '.', ' ') . " so'm\n";
        $message .= "💸 Jami xarajat: " . number_format($totalExpense, 0, '.', ' ') . " so'm\n";
        $balanceEmoji = $balance >= 0 ? '💚' : '❤️';
        $message .= "{$balanceEmoji} Balans: " . number_format($balance, 0, '.', ' ') . " so'm\n";
        $message .= "📊 Tejash darajasi: {$savingsRate}%\n\n";

        $message .= "<b>Shu oy:</b>\n";
        $message .= "💵 Daromad: " . number_format($monthIncome, 0, '.', ' ') . " so'm\n";
        $message .= "💸 Xarajat: " . number_format($monthExpense, 0, '.', ' ') . " so'm\n";
        $monthDiff = $monthIncome - $monthExpense;
        $diffEmoji = $monthDiff >= 0 ? '📈' : '📉';
        $message .= "{$diffEmoji} Farq: " . number_format($monthDiff, 0, '.', ' ') . " so'm\n\n";

        // Top expense categories
        $topExpenses = $user->transactions()
            ->expense()
            ->forMonth()
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(3)
            ->get();

        if ($topExpenses->isNotEmpty()) {
            $categories = config('telegram.expense_categories');
            $message .= "<b>Eng katta xarajat kategoriyalari:</b>\n";
            foreach ($topExpenses as $exp) {
                $label = $categories[$exp->category] ?? $exp->category;
                $message .= "• {$label}: " . number_format($exp->total, 0, '.', ' ') . " so'm\n";
            }
        }

        $message .= "\n<b>💡 Tavsiyalar:</b>\n";
        
        if ($savingsRate < 10) {
            $message .= "• Xarajatlarni qisqartiring\n";
            $message .= "• Byudjet rejasi tuzing\n";
        } elseif ($savingsRate < 20) {
            $message .= "• Yaxshi natija! 20%ga yetishga harakat qiling\n";
        } else {
            $message .= "• Ajoyib tejash ko'rsatkichi! 🎉\n";
            $message .= "• Investitsiya qilishni o'ylab ko'ring\n";
        }

        return $message;
    }

    protected function analyzeProgressData(TelegramUser $user): string
    {
        $badge = $user->getBadgeInfo();
        
        $message = "📈 <b>Umumiy progress</b>\n\n";

        $message .= "<b>🎮 O'yin statistikasi:</b>\n";
        $message .= "Daraja: {$badge['name']}\n";
        $message .= "Ball: {$user->total_points}\n";
        $message .= "Seriya: {$user->streak_days} kun 🔥\n";
        $message .= "Vazifalar: {$user->tasks_completed} ta bajarildi\n\n";

        // Next badge
        $nextBadge = $user->getNextBadge();
        if ($nextBadge) {
            $pointsNeeded = $nextBadge['points'] - $user->total_points;
            $message .= "<b>Keyingi daraja:</b> {$nextBadge['name']}\n";
            $message .= "Kerak ball: {$pointsNeeded}\n\n";
        }

        // Achievements
        $achievements = $user->achievements()->count();
        $totalAchievements = count(\App\Models\UserAchievement::getAvailableAchievements());
        $message .= "<b>🏆 Yutuqlar:</b> {$achievements}/{$totalAchievements}\n\n";

        // Weekly comparison
        $thisWeekTasks = $user->tasks()
            ->whereBetween('completed_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();
        
        $lastWeekTasks = $user->tasks()
            ->whereBetween('completed_at', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()])
            ->count();

        $weekChange = $lastWeekTasks > 0 
            ? round((($thisWeekTasks - $lastWeekTasks) / $lastWeekTasks) * 100) 
            : 0;

        $changeEmoji = $weekChange >= 0 ? '📈' : '📉';
        $message .= "<b>Haftalik taqqoslash:</b>\n";
        $message .= "Shu hafta: {$thisWeekTasks} ta vazifa\n";
        $message .= "O'tgan hafta: {$lastWeekTasks} ta vazifa\n";
        $message .= "{$changeEmoji} O'zgarish: {$weekChange}%";

        return $message;
    }

    protected function generateTips(TelegramUser $user): string
    {
        $tips = [];

        // Task-based tips
        $pendingHighPriority = $user->tasks()
            ->pending()
            ->where('priority', 'high')
            ->count();

        if ($pendingHighPriority > 3) {
            $tips[] = "🔴 {$pendingHighPriority} ta muhim vazifa kutilmoqda. Avval ularni bajaring!";
        }

        // Finance-based tips
        $todayExpense = $user->getTodayExpenses();
        $avgDailyExpense = $user->transactions()
            ->expense()
            ->forMonth()
            ->avg('amount') ?? 0;

        if ($todayExpense > $avgDailyExpense * 1.5) {
            $tips[] = "💸 Bugungi xarajat o'rtachadan yuqori. Ehtiyot bo'ling!";
        }

        // Debt-based tips
        $overdueDebts = $user->debts()->overdue()->count();
        if ($overdueDebts > 0) {
            $tips[] = "⏰ {$overdueDebts} ta qarzning muddati o'tgan. Tezroq hal qiling!";
        }

        // Streak tips
        if ($user->streak_days >= 7) {
            $tips[] = "🔥 {$user->streak_days} kunlik seriya! Davom eting!";
        }

        // Budget tips
        if ($user->monthly_budget_limit) {
            $monthExpense = $user->getMonthExpenses();
            $remaining = $user->monthly_budget_limit - $monthExpense;
            $daysLeft = now()->daysInMonth - now()->day;
            
            if ($remaining > 0 && $daysLeft > 0) {
                $dailyBudget = round($remaining / $daysLeft);
                $tips[] = "💰 Kunlik byudjet: " . number_format($dailyBudget, 0, '.', ' ') . " so'm ({$daysLeft} kun qoldi)";
            }
        }

        // General tips
        $generalTips = [
            "💡 Ertalab eng qiyin vazifalarni bajaring - energiya yuqori",
            "💡 Har kuni kechqurun ertangi kunni rejalashtiring",
            "💡 Kichik vazifalarni darhol bajaring (2 daqiqa qoidasi)",
            "💡 Xarajatlarni darhol yozib boring",
            "💡 Haftalik maqsadlar qo'ying va kuzating",
        ];

        if (count($tips) < 3) {
            $tips = array_merge($tips, array_slice($generalTips, 0, 3 - count($tips)));
        }

        $message = "💡 <b>Sizga maslahatlar</b>\n\n";
        
        foreach ($tips as $tip) {
            $message .= "{$tip}\n\n";
        }

        return $message;
    }
}
