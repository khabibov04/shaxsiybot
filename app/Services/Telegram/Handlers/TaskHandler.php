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
            "📝 <b>Add New Task</b>\n\n" .
            "Please enter the task title:\n\n" .
            "💡 You can also add tags using # (e.g., #work #urgent)"
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
                "📅 <b>Today's Tasks</b>\n\n" .
                "No tasks for today! 🎉\n\n" .
                "Use ➕ Add Task to create one."
            );
            return;
        }

        $this->displayTaskList($user, $tasks, "📅 Today's Tasks");
    }

    public function showWeekTasks(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->forWeek()
            ->orderBy('date')
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->get();

        $this->displayTaskList($user, $tasks, "📅 This Week's Tasks");
    }

    public function showMonthTasks(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->forMonth()
            ->orderBy('date')
            ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
            ->get();

        $this->displayTaskList($user, $tasks, "📆 This Month's Tasks");
    }

    public function showYearTasks(TelegramUser $user): void
    {
        $tasks = $user->tasks()
            ->forYear()
            ->orderBy('date')
            ->get();

        // Group by month
        $grouped = $tasks->groupBy(fn($task) => $task->date->format('F Y'));
        
        $message = "📊 <b>This Year's Tasks</b>\n\n";
        
        foreach ($grouped as $month => $monthTasks) {
            $completed = $monthTasks->where('status', 'completed')->count();
            $total = $monthTasks->count();
            $message .= "📅 <b>{$month}</b>: {$completed}/{$total} completed\n";
        }
        
        $totalCompleted = $tasks->where('status', 'completed')->count();
        $totalTasks = $tasks->count();
        $percentage = $totalTasks > 0 ? round(($totalCompleted / $totalTasks) * 100) : 0;
        
        $message .= "\n📈 Overall: {$totalCompleted}/{$totalTasks} ({$percentage}%)";
        
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

        $message = "🌅 <b>Morning Plan</b>\n\n";
        $message .= "📅 " . now()->format('l, F j, Y') . "\n\n";

        if ($tasks->isEmpty()) {
            $message .= "No tasks planned for today.\n\n" .
                "🎯 Tip: Plan your day the night before!";
        } else {
            // Prioritize difficult tasks for morning
            $morningTasks = $tasks->where('difficulty_level', '>=', 4);
            $otherTasks = $tasks->where('difficulty_level', '<', 4);

            if ($morningTasks->isNotEmpty()) {
                $message .= "🔥 <b>Tackle First (High Energy):</b>\n";
                foreach ($morningTasks as $task) {
                    $message .= "{$task->getPriorityEmoji()} {$task->title}\n";
                }
                $message .= "\n";
            }

            if ($otherTasks->isNotEmpty()) {
                $message .= "📋 <b>Other Tasks:</b>\n";
                foreach ($otherTasks as $task) {
                    $message .= "{$task->getPriorityEmoji()} {$task->title}\n";
                }
            }

            $message .= "\n💡 <b>AI Tip:</b> Complete difficult tasks in the morning when energy is highest!";
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function showEveningSummary(TelegramUser $user): void
    {
        $tasks = $user->tasks()->whereDate('date', today())->get();
        
        $completed = $tasks->where('status', 'completed');
        $pending = $tasks->where('status', 'pending');
        $totalPoints = $completed->sum('points_earned');

        $message = "🌙 <b>Evening Summary</b>\n\n";
        $message .= "📅 " . now()->format('l, F j, Y') . "\n\n";

        // Stats
        $completedCount = $completed->count();
        $totalCount = $tasks->count();
        $percentage = $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0;

        $message .= "📊 <b>Today's Stats:</b>\n";
        $message .= "✅ Completed: {$completedCount}/{$totalCount} ({$percentage}%)\n";
        $message .= "🎯 Points earned: {$totalPoints}\n";
        $message .= "🔥 Current streak: {$user->streak_days} days\n\n";

        // Completed tasks
        if ($completed->isNotEmpty()) {
            $message .= "✅ <b>Completed:</b>\n";
            foreach ($completed as $task) {
                $rating = $task->rating ? str_repeat('⭐', $task->rating) : '';
                $message .= "• {$task->title} {$rating}\n";
            }
            $message .= "\n";
        }

        // Pending tasks
        if ($pending->isNotEmpty()) {
            $message .= "⏳ <b>Pending (moving to tomorrow):</b>\n";
            foreach ($pending as $task) {
                $message .= "• {$task->title}\n";
            }
            $message .= "\n";
        }

        // Encouragement
        if ($percentage >= 100) {
            $message .= "🎉 Perfect day! All tasks completed!";
        } elseif ($percentage >= 75) {
            $message .= "👍 Great job! Almost there!";
        } elseif ($percentage >= 50) {
            $message .= "💪 Good progress! Keep going!";
        } else {
            $message .= "🌱 Every step counts. Tomorrow is a new day!";
        }

        $keyboard = [];
        if ($pending->isNotEmpty()) {
            $keyboard[] = [['text' => '📅 Move pending to tomorrow', 'callback_data' => 'task_move_pending']];
        }

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function markTaskDone(TelegramUser $user, string $taskId, ?int $messageId): void
    {
        $task = $user->tasks()->find($taskId);
        
        if (!$task) {
            $this->bot->sendMessage($user->telegram_id, "❌ Task not found.");
            return;
        }

        $task->markAsCompleted();
        
        // Check for achievements
        $this->checkTaskAchievements($user);

        $message = "✅ <b>Task Completed!</b>\n\n" .
            "📝 {$task->title}\n" .
            "🎯 Points earned: +{$task->points_earned}\n\n" .
            "Would you like to rate this task?";

        $keyboard = $this->bot->buildRatingKeyboard("task_rate:{$task->id}");
        $keyboard[] = [['text' => 'Skip rating', 'callback_data' => "task_rate:{$task->id}:skip"]];

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
            $this->bot->sendMessage($user->telegram_id, "❌ Task not found.");
            return;
        }

        $message = $this->formatTaskDetails($task);

        $keyboard = [
            [
                ['text' => '✅ Done', 'callback_data' => "task_done:{$task->id}"],
                ['text' => '✏️ Edit', 'callback_data' => "task_edit:{$task->id}"],
            ],
            [
                ['text' => '🗑️ Delete', 'callback_data' => "task_delete:{$task->id}"],
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
            $this->bot->sendMessage($user->telegram_id, "❌ Task not found.");
            return;
        }

        $user->setState('editing_task', ['task_id' => $task->id, 'step' => 'choose_field']);

        $keyboard = [
            [
                ['text' => '📝 Title', 'callback_data' => "task_edit_field:{$task->id}:title"],
                ['text' => '📋 Description', 'callback_data' => "task_edit_field:{$task->id}:description"],
            ],
            [
                ['text' => '🎯 Priority', 'callback_data' => "task_edit_field:{$task->id}:priority"],
                ['text' => '📁 Category', 'callback_data' => "task_edit_field:{$task->id}:category"],
            ],
            [
                ['text' => '📅 Date', 'callback_data' => "task_edit_field:{$task->id}:date"],
                ['text' => '⏰ Time', 'callback_data' => "task_edit_field:{$task->id}:time"],
            ],
            [
                ['text' => '❌ Cancel', 'callback_data' => 'cancel_edit'],
            ],
        ];

        $message = "✏️ <b>Edit Task</b>\n\n" .
            "📝 {$task->title}\n\n" .
            "What would you like to edit?";

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
            $this->bot->sendMessage($user->telegram_id, "❌ Task not found.");
            return;
        }

        $keyboard = [
            [
                ['text' => '✅ Yes, delete', 'callback_data' => "task_confirm_delete:{$task->id}"],
                ['text' => '❌ Cancel', 'callback_data' => "task_view:{$task->id}"],
            ],
        ];

        $message = "🗑️ <b>Delete Task?</b>\n\n" .
            "📝 {$task->title}\n\n" .
            "Are you sure you want to delete this task?";

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

        $message = "✅ <b>Task Complete!</b>\n\n" .
            "📝 {$task->title}\n" .
            "🎯 Points: +{$task->points_earned}";

        if ($task->rating) {
            $message .= "\n⭐ Rating: " . str_repeat('⭐', $task->rating);
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

        // Continue to next step
        $this->continueTaskCreation($user, $messageId);
    }

    public function setTaskCategory(TelegramUser $user, string $value, ?int $messageId): void
    {
        $stateData = $user->state_data;
        $stateData['category'] = $value;
        $user->setState($user->current_state, $stateData);

        // Continue to next step
        $this->continueTaskCreation($user, $messageId);
    }

    public function confirmTask(TelegramUser $user, string $value, ?int $messageId): void
    {
        if ($value === 'cancel') {
            $user->clearState();
            $this->bot->editMessage($user->telegram_id, $messageId, "❌ Task creation cancelled.");
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

        $message = "✅ <b>Task Created!</b>\n\n" .
            $this->formatTaskDetails($task);

        $keyboard = [
            [
                ['text' => '➕ Add Another', 'callback_data' => 'start_add_task'],
                ['text' => '📋 View Tasks', 'callback_data' => 'view_today_tasks'],
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

        $this->displayTaskList($user, $tasks->items(), "📋 Tasks (Page {$page})", $messageId, [
            'current_page' => $page,
            'last_page' => $tasks->lastPage(),
            'type' => 'tasks',
        ]);
    }

    protected function displayTaskList(TelegramUser $user, $tasks, string $title, ?int $messageId = null, array $pagination = []): void
    {
        if (empty($tasks) || (is_countable($tasks) && count($tasks) === 0)) {
            $this->bot->sendMessage($user->telegram_id, "{$title}\n\nNo tasks found.");
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

        // Pagination
        if (!empty($pagination)) {
            $navRow = [];
            if ($pagination['current_page'] > 1) {
                $navRow[] = ['text' => '◀️ Prev', 'callback_data' => "page:{$pagination['type']}_" . ($pagination['current_page'] - 1)];
            }
            if ($pagination['current_page'] < $pagination['last_page']) {
                $navRow[] = ['text' => 'Next ▶️', 'callback_data' => "page:{$pagination['type']}_" . ($pagination['current_page'] + 1)];
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

        $message .= "{$task->getPriorityEmoji()} Priority: " . ucfirst($task->priority) . "\n";
        $message .= "{$task->getCategoryEmoji()}\n";
        
        if ($task->date) {
            $message .= "📅 Date: {$task->date->format('M j, Y')}\n";
        }
        
        if ($task->time) {
            $message .= "⏰ Time: " . substr($task->time, 0, 5) . "\n";
        }

        if ($task->tags) {
            $message .= "🏷️ Tags: {$task->getFormattedTags()}\n";
        }

        if ($task->is_recurring) {
            $message .= "🔄 Recurring: " . ucfirst($task->recurrence_type) . "\n";
        }

        if ($task->status === 'completed') {
            $message .= "\n✅ Completed: {$task->completed_at->format('M j, Y H:i')}\n";
            $message .= "🎯 Points earned: {$task->points_earned}\n";
            if ($task->rating) {
                $message .= "⭐ Rating: " . str_repeat('⭐', $task->rating) . "\n";
            }
        }

        return $message;
    }

    protected function continueTaskCreation(TelegramUser $user, ?int $messageId): void
    {
        $data = $user->state_data;
        $step = $data['step'] ?? '';

        // Determine next step
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

        $message = "📁 <b>Select Category</b>";

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
                ['text' => '📅 Today', 'callback_data' => 'task_date:today'],
                ['text' => '📆 Tomorrow', 'callback_data' => 'task_date:tomorrow'],
            ],
            [
                ['text' => '📅 This Week', 'callback_data' => 'task_date:week'],
                ['text' => '📆 Next Week', 'callback_data' => 'task_date:next_week'],
            ],
        ];

        $message = "📅 <b>When is this task due?</b>";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    protected function showTaskConfirmation(TelegramUser $user, ?int $messageId): void
    {
        $data = $user->state_data;

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

        // First task
        if ($completedCount === 1) {
            $achievement = UserAchievement::award($user, 'first_task');
            if ($achievement) {
                $this->notifyAchievement($user, $achievement);
            }
        }

        // Task milestones
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

        // Streak achievements
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
        $message = "🎉 <b>Achievement Unlocked!</b>\n\n" .
            "{$achievement->achievement_icon} <b>{$achievement->achievement_name}</b>\n" .
            "📝 {$achievement->description}\n" .
            "🎯 +{$achievement->points_awarded} points!";

        $this->bot->sendMessage($user->telegram_id, $message);
    }
}

