<?php

namespace App\Console\Commands;

use App\Models\TelegramUser;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Console\Command;

class SendMorningNotifications extends Command
{
    protected $signature = 'telegram:morning-notifications';
    protected $description = 'Send morning notifications to users with their daily plan';

    public function handle(TelegramBotService $bot): int
    {
        $this->info('Sending morning notifications...');

        $users = TelegramUser::where('morning_notifications', true)
            ->where('is_blocked', false)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                // Check if it's morning time for the user (based on their timezone)
                $userTime = now()->setTimezone($user->timezone);
                $morningTime = \Carbon\Carbon::parse($user->morning_time, $user->timezone);

                // Allow 30-minute window
                if (abs($userTime->diffInMinutes($morningTime)) > 30) {
                    continue;
                }

                $tasks = $user->tasks()
                    ->whereDate('date', today())
                    ->where('status', 'pending')
                    ->orderByRaw("FIELD(priority, 'high', 'medium', 'low')")
                    ->orderBy('difficulty_level', 'desc')
                    ->get();

                $debtsToday = $user->debts()
                    ->active()
                    ->whereDate('due_date', today())
                    ->get();

                $debtsSoon = $user->debts()
                    ->active()
                    ->whereBetween('due_date', [today(), today()->addDays(3)])
                    ->count();

                $message = "🌅 <b>Good Morning, {$user->getDisplayName()}!</b>\n\n";
                $message .= "📅 " . now()->format('l, F j, Y') . "\n";
                $message .= "🔥 Streak: {$user->streak_days} days\n\n";

                if ($tasks->isEmpty()) {
                    $message .= "📋 No tasks scheduled for today.\n";
                    $message .= "Use /addtask to plan your day!\n";
                } else {
                    $highPriority = $tasks->where('priority', 'high');
                    $otherTasks = $tasks->where('priority', '!=', 'high');

                    if ($highPriority->isNotEmpty()) {
                        $message .= "🔴 <b>High Priority Tasks:</b>\n";
                        foreach ($highPriority as $task) {
                            $time = $task->time ? '⏰ ' . substr($task->time, 0, 5) . ' ' : '';
                            $message .= "• {$time}{$task->title}\n";
                        }
                        $message .= "\n";
                    }

                    if ($otherTasks->isNotEmpty()) {
                        $message .= "📋 <b>Other Tasks:</b> {$otherTasks->count()}\n";
                    }

                    $message .= "\n💡 <b>Tip:</b> Tackle difficult tasks in the morning!\n";
                }

                if ($debtsToday->isNotEmpty()) {
                    $message .= "\n⚠️ <b>Debts Due Today:</b>\n";
                    foreach ($debtsToday as $debt) {
                        $emoji = $debt->type === 'given' ? '📤' : '📥';
                        $message .= "{$emoji} {$debt->person_name}: {$debt->getFormattedRemainingAmount()}\n";
                    }
                }

                if ($debtsSoon > 0) {
                    $message .= "\n⏰ {$debtsSoon} debts due in the next 3 days";
                }

                $keyboard = [
                    [
                        ['text' => '📋 View Tasks', 'callback_data' => 'view_today_tasks'],
                        ['text' => '➕ Add Task', 'callback_data' => 'start_add_task'],
                    ],
                ];

                $bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
                $sent++;

                // Rate limit
                usleep(100000); // 100ms delay

            } catch (\Exception $e) {
                $this->error("Failed for user {$user->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Sent: {$sent}, Failed: {$failed}");

        return Command::SUCCESS;
    }
}

