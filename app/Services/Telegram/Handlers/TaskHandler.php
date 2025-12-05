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
            "📝 <b>Yangi vazifa qo'shish</b>\n\n" .
            "Vazifa nomini kiriting:\n\n" .
            "💡 Teglar qo'shish uchun # ishlating (masalan, #ish #muhim)"
        );
    }

    public function showTodayTasks(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->whereDate('date', today())
            ->orWhere(function ($q) {
                $q->where('is_recurring', true)
                    ->where('status', 'pending');
            })
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
            ->forWeek()
            ->orderBy('date')
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->get();

        $this->displayTaskList($user, $tasks, "📅 Shu hafta vazifalari");
    }

    public function showMonthTasks(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->forMonth()
            ->orderBy('date')
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->get();

        $this->displayTaskList($user, $tasks, "📆 Shu oy vazifalari");
    }

    public function showYearTasks(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->forYear()
            ->orderBy('date')
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
            ->orderBy('difficulty_level', 'desc')
            ->get();

        $message = "🌅 <b>Ertalabki reja</b>\n\n";
        $message .= "📅 " . now()->format('d.m.Y, l') . "\n\n";

        if ($tasks->isEmpty()) {
            $message .= "Bugun uchun rejalar yo'q.\n\n" .
                "🎯 Maslahat: Kunni kechqurun rejalashtirib oling!";
        } else {
            $morningTasks = $tasks->where('difficulty_level', '>=', 4);
            $otherTasks = $tasks->where('difficulty_level', '<', 4);

            if ($morningTasks->isNotEmpty()) {
                $message .= "🔥 <b>Avval bajaring (Yuqori energiya):</b>\n";
                foreach ($morningTasks as $task) {
                    $message .= "{$task->getPriorityEmoji()} {$task->title}\n";
                }
                $message .= "\n";
            }

            if ($otherTasks->isNotEmpty()) {
                $message .= "📋 <b>Boshqa vazifalar:</b>\n";
                foreach ($otherTasks as $task) {
                    $message .= "{$task->getPriorityEmoji()} {$task->title}\n";
                }
            }

            $message .= "\n💡 <b>AI maslahati:</b> Qiyin vazifalarni ertalab bajaring - energiya yuqori bo'ladi!";
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showEveningSummary(TelegramUser $user): void
    {
        $tasks = $user->tasks()->whereDate('date', today())->get();
        
        $completed = $tasks->where('status', 'completed');
        $pending = $tasks->where('status', 'pending');
        $totalPoints = $completed->sum('points_earned');

        $message = "🌙 <b>Kechki xulosa</b>\n\n";
        $message .= "📅 " . now()->format('d.m.Y, l') . "\n\n";

        $completedCount = $completed->count();
        $totalCount = $tasks->count();
        $percentage = $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0;

        $message .= "📊 <b>Bugungi statistika:</b>\n";
        $message .= "✅ Bajarildi: {$completedCount}/{$totalCount} ({$percentage}%)\n";
        $message .= "🎯 Yig'ilgan ball: {$totalPoints}\n";
        $message .= "🔥 Joriy seriya: {$user->streak_days} kun\n\n";

        if ($completed->isNotEmpty()) {
            $message .= "✅ <b>Bajarilgan:</b>\n";
            foreach ($completed as $task) {
                $rating = $task->rating ? str_repeat('⭐', $task->rating) : '';
                $message .= "• {$task->title} {$rating}\n";
            }
            $message .= "\n";
        }

        if ($pending->isNotEmpty()) {
            $message .= "⏳ <b>Kutilmoqda (ertaga o'tadi):</b>\n";
            foreach ($pending as $task) {
                $message .= "• {$task->title}\n";
            }
            $message .= "\n";
        }

        if ($percentage >= 100) {
            $message .= "🎉 Mukammal kun! Barcha vazifalar bajarildi!";
        } elseif ($percentage >= 75) {
            $message .= "👍 Ajoyib ish! Deyarli tamom!";
        } elseif ($percentage >= 50) {
            $message .= "💪 Yaxshi natija! Davom eting!";
        } else {
            $message .= "🌱 Har bir qadam muhim. Ertaga yangi kun!";
        }

        $keyboard = [];
        if ($pending->isNotEmpty()) {
            $keyboard[] = [['text' => '📅 Ertaga o\'tkazish', 'callback_data' => 'task_move_pending']];
        }

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function markTaskDone(TelegramUser $user, string $taskId, ?int $messageId): void
    {
        $task = $user->tasks()->find($taskId);
        
        if (!$task) {
            $this->bot->sendMessage($user->telegram_id, "❌ Vazifa topilmadi.");
            return;
        }

        $task->markAsCompleted();
        
        $this->checkTaskAchievements($user);

        $message = "✅ <b>Vazifa bajarildi!</b>\n\n" .
            "📝 {$task->title}\n" .
            "🎯 Yig'ilgan ball: +{$task->points_earned}\n\n" .
            "Bu vazifani baholaysizmi?";

        $keyboard = $this->bot->buildRatingKeyboard("task_rate:{$task->id}");
        $keyboard[] = [['text' => 'O\'tkazib yuborish', 'callback_data' => "task_rate:{$task->id}:skip"]];

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    public function viewTask(TelegramUser $user, string $taskId, ?int $messageId): void
    {
        $task = $user->tasks()->find($taskId);
        
        if (!$task) {
            $this->bot->sendMessage($user->telegram_id, "❌ Vazifa topilmadi.");
            return;
        }

        $message = $this->formatTaskDetails($task);

        $keyboard = [
            [
                ['text' => '✅ Bajarildi', 'callback_data' => "task_done:{$task->id}"],
                ['text' => '✏️ Tahrirlash', 'callback_data' => "task_edit:{$task->id}"],
            ],
            [
                ['text' => '🗑️ O\'chirish', 'callback_data' => "task_delete:{$task->id}"],
            ],
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

        $user->setState('editing_task', ['task_id' => $task->id, 'step' => 'choose_field']);

        $keyboard = [
            [
                ['text' => '📝 Nom', 'callback_data' => "task_edit_field:{$task->id}:title"],
                ['text' => '📋 Tavsif', 'callback_data' => "task_edit_field:{$task->id}:description"],
            ],
            [
                ['text' => '🎯 Muhimlik', 'callback_data' => "task_edit_field:{$task->id}:priority"],
                ['text' => '📁 Kategoriya', 'callback_data' => "task_edit_field:{$task->id}:category"],
            ],
            [
                ['text' => '📅 Sana', 'callback_data' => "task_edit_field:{$task->id}:date"],
                ['text' => '⏰ Vaqt', 'callback_data' => "task_edit_field:{$task->id}:time"],
            ],
            [
                ['text' => '❌ Bekor qilish', 'callback_data' => 'cancel_edit'],
            ],
        ];

        $message = "✏️ <b>Vazifani tahrirlash</b>\n\n" .
            "📝 {$task->title}\n\n" .
            "Nimani o'zgartirmoqchisiz?";

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
                ['text' => '❌ Bekor qilish', 'callback_data' => "task_view:{$task->id}"],
            ],
        ];

        $message = "🗑️ <b>Vazifani o'chirish?</b>\n\n" .
            "📝 {$task->title}\n\n" .
            "Rostdan ham o'chirmoqchimisiz?";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    public function rateTask(TelegramUser $user, string $value, ?int $messageId): void
    {
        $parts = explode(':', $value);
        $taskId = $parts[0];
        $rating = $parts[1] ?? null;

        $task = $user->tasks()->find($taskId);
        
        if (!$task) {
            return;
        }

        if ($rating && $rating !== 'skip') {
            $task->rating = (int)$rating;
            $task->save();
        }

        $message = "✅ <b>Vazifa bajarildi!</b>\n\n" .
            "📝 {$task->title}\n" .
            "🎯 Ball: +{$task->points_earned}";

        if ($task->rating) {
            $message .= "\n⭐ Baho: " . str_repeat('⭐', $task->rating);
        }

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function setTaskPriority(TelegramUser $user, string $value, ?int $messageId): void
    {
        $stateData = $user->state_data;
        $stateData['priority'] = $value;
        $user->setState($user->current_state, $stateData);

        $this->continueTaskCreation($user, $messageId);
    }

    public function setTaskCategory(TelegramUser $user, string $value, ?int $messageId): void
    {
        $stateData = $user->state_data;
        $stateData['category'] = $value;
        $user->setState($user->current_state, $stateData);

        $this->continueTaskCreation($user, $messageId);
    }

    public function confirmTask(TelegramUser $user, string $value, ?int $messageId): void
    {
        if ($value === 'cancel') {
            $user->clearState();
            $this->bot->editMessage($user->telegram_id, $messageId, "❌ Vazifa yaratish bekor qilindi.");
            return;
        }

        $data = $user->state_data;

        $task = Task::create([
            'telegram_user_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'category' => $data['category'] ?? 'other',
            'tags' => $data['tags'] ?? [],
            'date' => $data['date'] ?? today(),
            'time' => $data['time'] ?? null,
            'is_recurring' => $data['is_recurring'] ?? false,
            'recurrence_type' => $data['recurrence_type'] ?? null,
            'difficulty_level' => $data['difficulty_level'] ?? 3,
        ]);

        $user->clearState();

        $message = "✅ <b>Vazifa yaratildi!</b>\n\n" .
            $this->formatTaskDetails($task);

        $keyboard = [
            [
                ['text' => '➕ Yana qo\'shish', 'callback_data' => 'start_add_task'],
                ['text' => '📋 Vazifalarni ko\'rish', 'callback_data' => 'view_today_tasks'],
            ],
        ];

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    public function submitRating(TelegramUser $user, string $value, ?int $messageId): void
    {
        $this->rateTask($user, $value, $messageId);
    }

    public function showTasksPage(TelegramUser $user, int $page, ?int $messageId): void
    {
        $perPage = 5;
        $tasks = $user->tasks()
            ->pending()
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->paginate($perPage, ['*'], 'page', $page);

        $this->displayTaskList($user, $tasks->items(), "📋 Vazifalar ({$page}-sahifa)", $messageId, [
            'current_page' => $page,
            'last_page' => $tasks->lastPage(),
            'type' => 'tasks',
        ]);
    }

    protected function displayTaskList(TelegramUser $user, $tasks, string $title, ?int $messageId = null, array $pagination = []): void
    {
        if (empty($tasks) || (is_countable($tasks) && count($tasks) === 0)) {
            $this->bot->sendMessage($user->telegram_id, "{$title}\n\nVazifa topilmadi.");
            return;
        }

        $message = "<b>{$title}</b>\n\n";

        foreach ($tasks as $task) {
            $status = $task->status === 'completed' ? '✅' : $task->getPriorityEmoji();
            $time = $task->time ? " ⏰ " . substr($task->time, 0, 5) : '';
            $tags = $task->getFormattedTags();
            
            $message .= "{$status} <b>{$task->title}</b>{$time}\n";
            if ($task->description) {
                $message .= "   📝 " . mb_substr($task->description, 0, 50) . "...\n";
            }
            if ($tags) {
                $message .= "   {$tags}\n";
            }
            $message .= "\n";
        }

        $keyboard = [];
        foreach ($tasks as $task) {
            if ($task->status !== 'completed') {
                $keyboard[] = [
                    ['text' => "✅ {$task->title}", 'callback_data' => "task_done:{$task->id}"],
                    ['text' => '👁️', 'callback_data' => "task_view:{$task->id}"],
                ];
            }
        }

        if (!empty($pagination)) {
            $navRow = [];
            if ($pagination['current_page'] > 1) {
                $navRow[] = ['text' => '◀️ Oldingi', 'callback_data' => "page:{$pagination['type']}_" . ($pagination['current_page'] - 1)];
            }
            if ($pagination['current_page'] < $pagination['last_page']) {
                $navRow[] = ['text' => 'Keyingi ▶️', 'callback_data' => "page:{$pagination['type']}_" . ($pagination['current_page'] + 1)];
            }
            if (!empty($navRow)) {
                $keyboard[] = $navRow;
            }
        }

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    protected function formatTaskDetails(Task $task): string
    {
        $message = "{$task->getStatusEmoji()} <b>{$task->title}</b>\n\n";
        
        if ($task->description) {
            $message .= "📝 {$task->description}\n\n";
        }

        $priorities = ['high' => 'Yuqori', 'medium' => 'O\'rta', 'low' => 'Past'];
        $message .= "{$task->getPriorityEmoji()} Muhimlik: " . ($priorities[$task->priority] ?? $task->priority) . "\n";
        $message .= "{$task->getCategoryEmoji()}\n";
        
        if ($task->date) {
            $message .= "📅 Sana: {$task->date->format('d.m.Y')}\n";
        }
        
        if ($task->time) {
            $message .= "⏰ Vaqt: " . substr($task->time, 0, 5) . "\n";
        }

        if ($task->tags) {
            $message .= "🏷️ Teglar: {$task->getFormattedTags()}\n";
        }

        if ($task->is_recurring) {
            $recurrenceTypes = ['daily' => 'Kunlik', 'weekly' => 'Haftalik', 'monthly' => 'Oylik', 'yearly' => 'Yillik'];
            $message .= "🔄 Takroriy: " . ($recurrenceTypes[$task->recurrence_type] ?? $task->recurrence_type) . "\n";
        }

        if ($task->status === 'completed') {
            $message .= "\n✅ Bajarildi: {$task->completed_at->format('d.m.Y H:i')}\n";
            $message .= "🎯 Yig'ilgan ball: {$task->points_earned}\n";
            if ($task->rating) {
                $message .= "⭐ Baho: " . str_repeat('⭐', $task->rating) . "\n";
            }
        }

        return $message;
    }

    protected function continueTaskCreation(TelegramUser $user, ?int $messageId): void
    {
        $data = $user->state_data;
        $step = $data['step'] ?? '';

        $nextStep = match ($step) {
            'priority' => 'category',
            'category' => 'date',
            'date' => 'confirm',
            default => 'confirm',
        };

        $data['step'] = $nextStep;
        $user->setState($user->current_state, $data);

        match ($nextStep) {
            'category' => $this->askCategory($user, $messageId),
            'date' => $this->askDate($user, $messageId),
            'confirm' => $this->showTaskConfirmation($user, $messageId),
            default => null,
        };
    }

    protected function askCategory(TelegramUser $user, ?int $messageId): void
    {
        $categories = config('telegram.task_categories');
        $keyboard = $this->bot->buildCategoryInlineKeyboard($categories, 'task_category');

        $message = "📁 <b>Kategoriyani tanlang</b>";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    protected function askDate(TelegramUser $user, ?int $messageId): void
    {
        $keyboard = [
            [
                ['text' => '📅 Bugun', 'callback_data' => 'task_date:today'],
                ['text' => '📆 Ertaga', 'callback_data' => 'task_date:tomorrow'],
            ],
            [
                ['text' => '📅 Shu hafta', 'callback_data' => 'task_date:week'],
                ['text' => '📆 Keyingi hafta', 'callback_data' => 'task_date:next_week'],
            ],
        ];

        $message = "📅 <b>Bu vazifa qachon bajarilishi kerak?</b>";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    protected function showTaskConfirmation(TelegramUser $user, ?int $messageId): void
    {
        $data = $user->state_data;

        $message = "📝 <b>Vazifani tasdiqlash</b>\n\n";
        $message .= "📌 Nom: {$data['title']}\n";
        
        if (!empty($data['description'])) {
            $message .= "📝 Tavsif: {$data['description']}\n";
        }
        
        $priority = $data['priority'] ?? 'medium';
        $priorities = ['high' => 'Yuqori', 'medium' => 'O\'rta', 'low' => 'Past'];
        $message .= "🎯 Muhimlik: " . ($priorities[$priority] ?? $priority) . "\n";
        
        $category = $data['category'] ?? 'other';
        $categories = config('telegram.task_categories');
        $message .= "📁 Kategoriya: {$categories[$category]}\n";
        
        if (!empty($data['tags'])) {
            $message .= "🏷️ Teglar: " . implode(' ', $data['tags']) . "\n";
        }

        $keyboard = $this->bot->buildConfirmKeyboard('task_confirm');

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    protected function checkTaskAchievements(TelegramUser $user): void
    {
        $completedCount = $user->tasks_completed;

        if ($completedCount === 1) {
            $achievement = UserAchievement::award($user, 'first_task');
            if ($achievement) {
                $this->notifyAchievement($user, $achievement);
            }
        }

        if ($completedCount === 10) {
            $achievement = UserAchievement::award($user, 'tasks_10');
            if ($achievement) {
                $this->notifyAchievement($user, $achievement);
            }
        }

        if ($completedCount === 50) {
            $achievement = UserAchievement::award($user, 'tasks_50');
            if ($achievement) {
                $this->notifyAchievement($user, $achievement);
            }
        }

        if ($completedCount === 100) {
            $achievement = UserAchievement::award($user, 'tasks_100');
            if ($achievement) {
                $this->notifyAchievement($user, $achievement);
            }
        }

        if ($user->streak_days === 7) {
            $achievement = UserAchievement::award($user, 'task_streak_7');
            if ($achievement) {
                $this->notifyAchievement($user, $achievement);
            }
        }

        if ($user->streak_days === 30) {
            $achievement = UserAchievement::award($user, 'task_streak_30');
            if ($achievement) {
                $this->notifyAchievement($user, $achievement);
            }
        }
    }

    protected function notifyAchievement(TelegramUser $user, UserAchievement $achievement): void
    {
        $message = "🎉 <b>Yutuq ochildi!</b>\n\n" .
            "{$achievement->achievement_icon} <b>{$achievement->achievement_name}</b>\n" .
            "📝 {$achievement->description}\n" .
            "🎯 +{$achievement->points_awarded} ball!";

        $this->bot->sendMessage($user->telegram_id, $message);
    }
}
