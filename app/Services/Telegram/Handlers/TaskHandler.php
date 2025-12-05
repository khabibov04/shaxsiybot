<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Task;
use App\Models\TelegramUser;
use App\Models\UserAchievement;
use App\Services\Telegram\TelegramBotService;

class TaskHandler
{
    protected TelegramBotService $bot;

    public function __construct(TelegramBotService $bot)
    {
        $this->bot = $bot;
    }

    public function startAddTask(TelegramUser $user): void
    {
        $user->setState('adding_task', ['step' => 'title']);
        
        $this->bot->sendMessage(
            $user->telegram_id,
            "📝 <b>Yangi vazifa</b>\n\n" .
            "Vazifa nomini yozing:\n\n" .
            "💡 Misol: <code>Ish uchun hisobot tayyorlash</code>"
        );
    }

    /**
     * Avtomatik kategoriya aniqlash
     */
    public function autoCategorize(string $text): array
    {
        $text = mb_strtolower($text);
        
        $categoryKeywords = [
            'work' => ['ish', 'loyiha', 'hisobot', 'yig\'ilish', 'mijoz', 'ofis', 'shartnoma', 'prezentatsiya', 'meeting', 'work', 'project'],
            'home' => ['uy', 'xona', 'tozalash', 'yig\'ishtirish', 'tamirlash', 'mebel', 'remont', 'hovli'],
            'personal' => ['shaxsiy', 'do\'st', 'oila', 'bayram', 'tug\'ilgan', 'uchrashish', 'dam olish'],
            'finance' => ['pul', 'to\'lov', 'bank', 'soliq', 'qarz', 'kredit', 'hisob', 'moliya', 'byudjet'],
            'health' => ['vrach', 'doktor', 'shifoxona', 'dori', 'sport', 'yugurish', 'mashq', 'salomatlik', 'kasalxona'],
            'education' => ['o\'qish', 'kurs', 'kitob', 'dars', 'imtihon', 'talim', 'seminar', 'trening'],
            'shopping' => ['sotib', 'xarid', 'bozor', 'do\'kon', 'buyurtma', 'olish', 'market'],
        ];

        $priorityKeywords = [
            'high' => ['muhim', 'shoshilinch', 'tezda', 'zudlik', 'bugun', 'hozir', 'urgent', 'asap'],
            'low' => ['keyinroq', 'shoshmasdan', 'vaqt', 'bo\'lsa'],
        ];

        // Kategoriya aniqlash
        $category = 'other';
        foreach ($categoryKeywords as $cat => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $category = $cat;
                    break 2;
                }
            }
        }

        // Muhimlik aniqlash
        $priority = 'medium';
        foreach ($priorityKeywords as $prio => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $priority = $prio;
                    break 2;
                }
            }
        }

        return [
            'category' => $category,
            'priority' => $priority,
        ];
    }

    /**
     * Tezkor vazifa qo'shish - kategoriya va muhimlik avtomatik
     */
    public function quickAddTask(TelegramUser $user, string $title): void
    {
        // Teglarni ajratib olish
        preg_match_all('/#(\w+)/u', $title, $matches);
        $tags = $matches[1] ?? [];
        $cleanTitle = trim(preg_replace('/#\w+/u', '', $title));

        if (empty($cleanTitle)) {
            $this->bot->sendMessage($user->telegram_id, "❌ Vazifa nomi bo'sh bo'lishi mumkin emas.");
            return;
        }

        // Avtomatik kategoriya va muhimlik
        $auto = $this->autoCategorize($cleanTitle);

        // Vazifani yaratish
        $task = Task::create([
            'telegram_user_id' => $user->id,
            'title' => $cleanTitle,
            'priority' => $auto['priority'],
            'category' => $auto['category'],
            'tags' => $tags,
            'date' => today(),
            'status' => 'pending',
        ]);

        $user->clearState();

        $categories = config('telegram.task_categories');
        $priorities = ['high' => '🔴 Yuqori', 'medium' => '🟡 O\'rta', 'low' => '🟢 Past'];

        $message = "✅ <b>Vazifa qo'shildi!</b>\n\n" .
            "📝 {$task->title}\n" .
            "📁 {$categories[$task->category]}\n" .
            "{$priorities[$task->priority]}\n" .
            "📅 Bugun";

        if (!empty($tags)) {
            $message .= "\n🏷️ " . implode(' ', array_map(fn($t) => "#{$t}", $tags));
        }

        $keyboard = [
            [
                ['text' => '➕ Yana qo\'shish', 'callback_data' => 'quick_add_task'],
                ['text' => '📋 Vazifalar', 'callback_data' => 'view_today_tasks'],
            ],
            [
                ['text' => '✏️ Tahrirlash', 'callback_data' => "task_edit:{$task->id}"],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showTodayTasks(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->whereDate('date', today())
            ->where('status', 'pending')
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->orderBy('time')
            ->get();

        if ($tasks->isEmpty()) {
            $this->bot->sendMessage(
                $user->telegram_id,
                "📅 <b>Bugungi vazifalar</b>\n\n" .
                "Bugun uchun vazifa yo'q! 🎉\n\n" .
                "Yangi vazifa qo'shish uchun ➕ Vazifa qo'shish tugmasini bosing."
            );
            return;
        }

        $this->displayTaskList($user, $tasks, "📅 Bugungi vazifalar");
    }

    public function showWeekTasks(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])
            ->where('status', 'pending')
            ->orderBy('date')
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->get();

        if ($tasks->isEmpty()) {
            $this->bot->sendMessage($user->telegram_id, "📅 <b>Shu hafta vazifalari</b>\n\nVazifa yo'q.");
            return;
        }

        $this->displayTaskList($user, $tasks, "📅 Shu hafta vazifalari");
    }

    public function showMonthTasks(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->where('status', 'pending')
            ->orderBy('date')
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->get();

        if ($tasks->isEmpty()) {
            $this->bot->sendMessage($user->telegram_id, "📆 <b>Shu oy vazifalari</b>\n\nVazifa yo'q.");
            return;
        }

        $this->displayTaskList($user, $tasks, "📆 Shu oy vazifalari");
    }

    public function showYearTasks(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->whereYear('date', now()->year)
            ->get();

        $grouped = $tasks->groupBy(fn($task) => $task->date->format('F Y'));
        
        $message = "📊 <b>Yillik ko'rinish</b>\n\n";
        
        $months = [
            'January' => 'Yanvar', 'February' => 'Fevral', 'March' => 'Mart',
            'April' => 'Aprel', 'May' => 'May', 'June' => 'Iyun',
            'July' => 'Iyul', 'August' => 'Avgust', 'September' => 'Sentabr',
            'October' => 'Oktabr', 'November' => 'Noyabr', 'December' => 'Dekabr'
        ];
        
        foreach ($grouped as $month => $monthTasks) {
            $completed = $monthTasks->where('status', 'completed')->count();
            $total = $monthTasks->count();
            $monthName = str_replace(array_keys($months), array_values($months), $month);
            $message .= "📅 <b>{$monthName}</b>: {$completed}/{$total} bajarildi\n";
        }
        
        $totalCompleted = $tasks->where('status', 'completed')->count();
        $totalTasks = $tasks->count();
        $percentage = $totalTasks > 0 ? round(($totalCompleted / $totalTasks) * 100) : 0;
        
        $message .= "\n📈 Umumiy: {$totalCompleted}/{$totalTasks} ({$percentage}%)";
        
        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showMorningPlan(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->whereDate('date', today())
            ->where('status', 'pending')
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->get();

        $message = "🌅 <b>Ertalabki reja</b>\n";
        $message .= "📅 " . now()->format('d.m.Y') . "\n\n";

        if ($tasks->isEmpty()) {
            $message .= "Bugun uchun rejalar yo'q.\n\n🎯 Maslahat: Kunni rejalashtiring!";
        } else {
            $highPriority = $tasks->where('priority', 'high');
            $otherTasks = $tasks->where('priority', '!=', 'high');

            if ($highPriority->isNotEmpty()) {
                $message .= "🔴 <b>Muhim vazifalar:</b>\n";
                foreach ($highPriority as $task) {
                    $message .= "• {$task->title}\n";
                }
                $message .= "\n";
            }

            if ($otherTasks->isNotEmpty()) {
                $message .= "📋 <b>Boshqa vazifalar:</b>\n";
                foreach ($otherTasks as $task) {
                    $emoji = $task->priority === 'medium' ? '🟡' : '🟢';
                    $message .= "{$emoji} {$task->title}\n";
                }
            }

            $message .= "\n💡 Muhim vazifalarni ertalab bajaring!";
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showEveningSummary(TelegramUser $user): void
    {
        $tasks = $user->tasks()->whereDate('date', today())->get();
        
        $completed = $tasks->where('status', 'completed');
        $pending = $tasks->where('status', 'pending');

        $message = "🌙 <b>Kechki xulosa</b>\n";
        $message .= "📅 " . now()->format('d.m.Y') . "\n\n";

        $completedCount = $completed->count();
        $totalCount = $tasks->count();
        $percentage = $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0;

        $message .= "📊 <b>Bugungi natija:</b>\n";
        $message .= "✅ Bajarildi: {$completedCount}/{$totalCount} ({$percentage}%)\n";
        $message .= "🔥 Seriya: {$user->streak_days} kun\n\n";

        if ($completed->isNotEmpty()) {
            $message .= "✅ <b>Bajarilgan:</b>\n";
            foreach ($completed->take(5) as $task) {
                $message .= "• {$task->title}\n";
            }
            $message .= "\n";
        }

        if ($pending->isNotEmpty()) {
            $message .= "⏳ <b>Ertaga o'tadi:</b>\n";
            foreach ($pending->take(5) as $task) {
                $message .= "• {$task->title}\n";
            }
        }

        if ($percentage >= 80) {
            $message .= "\n🎉 Ajoyib kun!";
        } elseif ($percentage >= 50) {
            $message .= "\n💪 Yaxshi natija!";
        } else {
            $message .= "\n🌱 Ertaga yangi kun!";
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function markTaskDone(TelegramUser $user, string $taskId, ?int $messageId): void
    {
        $task = $user->tasks()->find($taskId);
        
        if (!$task) {
            $this->bot->sendMessage($user->telegram_id, "❌ Vazifa topilmadi.");
            return;
        }

        $task->status = 'completed';
        $task->completed_at = now();
        $task->save();

        // Ball qo'shish
        $points = 10;
        if ($task->priority === 'high') $points = 20;
        $user->increment('total_points', $points);
        $user->increment('tasks_completed');

        $message = "✅ <b>Bajarildi!</b>\n\n" .
            "📝 {$task->title}\n" .
            "🎯 +{$points} ball";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function viewTask(TelegramUser $user, string $taskId, ?int $messageId): void
    {
        $task = $user->tasks()->find($taskId);
        
        if (!$task) {
            $this->bot->sendMessage($user->telegram_id, "❌ Vazifa topilmadi.");
            return;
        }

        $categories = config('telegram.task_categories');
        $priorities = ['high' => '🔴 Yuqori', 'medium' => '🟡 O\'rta', 'low' => '🟢 Past'];
        
        $categoryLabel = isset($categories[$task->category]) ? $categories[$task->category] : 'Boshqa';
        $priorityLabel = isset($priorities[$task->priority]) ? $priorities[$task->priority] : '🟡 O\'rta';

        $message = "📝 <b>{$task->title}</b>\n\n";
        $message .= "📁 {$categoryLabel}\n";
        $message .= "{$priorityLabel}\n";
        $message .= "📅 {$task->date->format('d.m.Y')}\n";
        $message .= "📊 Holat: " . ($task->status === 'completed' ? '✅ Bajarildi' : '⏳ Kutilmoqda');

        $keyboard = [];
        
        if ($task->status !== 'completed') {
            $keyboard[] = [
                ['text' => '✅ Bajarildi', 'callback_data' => "task_done:{$task->id}"],
            ];
        }
        
        $keyboard[] = [
            ['text' => '🗑️ O\'chirish', 'callback_data' => "task_delete:{$task->id}"],
        ];

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    public function editTask(TelegramUser $user, string $taskId, ?int $messageId): void
    {
        $task = $user->tasks()->find($taskId);
        
        if (!$task) {
            $this->bot->sendMessage($user->telegram_id, "❌ Vazifa topilmadi.");
            return;
        }

        $keyboard = [
            [
                ['text' => '🔴 Yuqori', 'callback_data' => "task_priority:{$task->id}:high"],
                ['text' => '🟡 O\'rta', 'callback_data' => "task_priority:{$task->id}:medium"],
                ['text' => '🟢 Past', 'callback_data' => "task_priority:{$task->id}:low"],
            ],
            [
                ['text' => '📅 Ertaga', 'callback_data' => "task_date:{$task->id}:tomorrow"],
                ['text' => '📆 Keyingi hafta', 'callback_data' => "task_date:{$task->id}:next_week"],
            ],
            [
                ['text' => '🗑️ O\'chirish', 'callback_data' => "task_delete:{$task->id}"],
                ['text' => '🔙 Orqaga', 'callback_data' => "task_view:{$task->id}"],
            ],
        ];

        $message = "✏️ <b>Tahrirlash</b>\n\n📝 {$task->title}\n\nNimani o'zgartirmoqchisiz?";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    public function deleteTask(TelegramUser $user, string $taskId, ?int $messageId): void
    {
        $task = $user->tasks()->find($taskId);
        
        if (!$task) {
            $this->bot->sendMessage($user->telegram_id, "❌ Vazifa topilmadi.");
            return;
        }

        $keyboard = [
            [
                ['text' => '✅ Ha, o\'chirish', 'callback_data' => "task_confirm_delete:{$task->id}"],
                ['text' => '❌ Bekor', 'callback_data' => "task_view:{$task->id}"],
            ],
        ];

        $message = "🗑️ <b>O'chirish?</b>\n\n📝 {$task->title}";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    public function confirmDeleteTask(TelegramUser $user, string $taskId, ?int $messageId): void
    {
        $task = $user->tasks()->find($taskId);
        
        if ($task) {
            $task->delete();
        }

        $message = "🗑️ Vazifa o'chirildi.";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function setTaskPriority(TelegramUser $user, string $value, ?int $messageId): void
    {
        $parts = explode(':', $value);
        $taskId = $parts[0] ?? null;
        $priority = $parts[1] ?? null;

        if (!$taskId || !$priority) return;

        $task = $user->tasks()->find($taskId);
        if (!$task) return;

        $task->priority = $priority;
        $task->save();

        $priorities = ['high' => '🔴 Yuqori', 'medium' => '🟡 O\'rta', 'low' => '🟢 Past'];
        $message = "✅ Muhimlik o'zgartirildi: {$priorities[$priority]}";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function setTaskDate(TelegramUser $user, string $value, ?int $messageId): void
    {
        $parts = explode(':', $value);
        $taskId = $parts[0] ?? null;
        $dateOption = $parts[1] ?? null;

        if (!$taskId || !$dateOption) return;

        $task = $user->tasks()->find($taskId);
        if (!$task) return;

        $newDate = match($dateOption) {
            'tomorrow' => now()->addDay(),
            'next_week' => now()->addWeek(),
            default => now(),
        };

        $task->date = $newDate;
        $task->save();

        $message = "✅ Sana o'zgartirildi: {$newDate->format('d.m.Y')}";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function setTaskCategory(TelegramUser $user, string $value, ?int $messageId): void
    {
        // Not used anymore - auto-categorization
    }

    public function confirmTask(TelegramUser $user, string $value, ?int $messageId): void
    {
        // Not used anymore - instant creation
    }

    public function rateTask(TelegramUser $user, string $value, ?int $messageId): void
    {
        // Rating disabled for simplicity
    }

    public function submitRating(TelegramUser $user, string $value, ?int $messageId): void
    {
        // Rating disabled for simplicity
    }

    public function showTasksPage(TelegramUser $user, int $page, ?int $messageId): void
    {
        $perPage = 5;
        $tasks = $user->tasks()
            ->where('status', 'pending')
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $this->displayTaskList($user, $tasks, "📋 Vazifalar", $messageId);
    }

    protected function displayTaskList(TelegramUser $user, $tasks, string $title, ?int $messageId = null): void
    {
        if ($tasks->isEmpty()) {
            $this->bot->sendMessage($user->telegram_id, "{$title}\n\nVazifa yo'q.");
            return;
        }

        $message = "<b>{$title}</b>\n\n";

        foreach ($tasks as $task) {
            $priorityEmoji = match($task->priority) {
                'high' => '🔴',
                'medium' => '🟡',
                'low' => '🟢',
                default => '⚪',
            };
            
            $message .= "{$priorityEmoji} {$task->title}\n";
        }

        $keyboard = [];
        foreach ($tasks as $task) {
            $keyboard[] = [
                ['text' => "✅ {$task->title}", 'callback_data' => "task_done:{$task->id}"],
            ];
        }

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }
}
