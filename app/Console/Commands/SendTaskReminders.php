<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Console\Command;

class SendTaskReminders extends Command
{
    protected $signature = 'telegram:task-reminders';
    protected $description = 'Send task reminders based on scheduled reminder times';

    public function handle(TelegramBotService $bot): int
    {
        $this->info('Sending task reminders...');

        $now = now();

        // Get tasks with reminders due in the next 5 minutes
        $tasks = Task::with('telegramUser')
            ->where('status', 'pending')
            ->whereNotNull('reminder_time')
            ->whereDate('date', today())
            ->whereHas('telegramUser', function ($query) {
                $query->where('is_blocked', false);
            })
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($tasks as $task) {
            try {
                $user = $task->telegramUser;
                $userTime = $now->copy()->setTimezone($user->timezone);
                $reminderTime = \Carbon\Carbon::parse($task->reminder_time, $user->timezone)
                    ->setDate($userTime->year, $userTime->month, $userTime->day);

                // Check if reminder should be sent (within 5 minute window)
                if (abs($userTime->diffInMinutes($reminderTime)) > 5) {
                    continue;
                }

                // Check if already reminded today (using a simple check)
                $cacheKey = "task_reminder_{$task->id}_" . today()->format('Y-m-d');
                if (cache()->has($cacheKey)) {
                    continue;
                }

                $message = "⏰ <b>Task Reminder!</b>\n\n";
                $message .= "{$task->getPriorityEmoji()} <b>{$task->title}</b>\n";
                
                if ($task->description) {
                    $message .= "📝 {$task->description}\n";
                }
                
                $message .= "\n📅 Due: Today";
                if ($task->time) {
                    $message .= " at " . substr($task->time, 0, 5);
                }

                $keyboard = [
                    [
                        ['text' => '✅ Mark Done', 'callback_data' => "task_done:{$task->id}"],
                        ['text' => '⏰ Snooze 30m', 'callback_data' => "task_snooze:{$task->id}:30"],
                    ],
                ];

                $bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
                
                // Mark as reminded
                cache()->put($cacheKey, true, now()->endOfDay());
                $sent++;

                usleep(100000);

            } catch (\Exception $e) {
                $this->error("Failed for task {$task->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Sent: {$sent}, Failed: {$failed}");

        return Command::SUCCESS;
    }
}

