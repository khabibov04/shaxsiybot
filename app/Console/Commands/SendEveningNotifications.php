<?php

namespace App\Console\Commands;

use App\Models\TelegramUser;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Console\Command;

class SendEveningNotifications extends Command
{
    protected $signature = 'telegram:evening-notifications';
    protected $description = 'Send evening summary notifications to users';

    public function handle(TelegramBotService $bot): int
    {
        $this->info('Sending evening notifications...');

        $users = TelegramUser::where('evening_notifications', true)
            ->where('is_blocked', false)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                // Check if it's evening time for the user
                $userTime = now()->setTimezone($user->timezone);
                $eveningTime = \Carbon\Carbon::parse($user->evening_time, $user->timezone);

                if (abs($userTime->diffInMinutes($eveningTime)) > 30) {
                    continue;
                }

                $tasks = $user->tasks()->whereDate('date', today())->get();
                $completed = $tasks->where('status', 'completed');
                $pending = $tasks->where('status', 'pending');
                
                $transactions = $user->transactions()->whereDate('date', today())->get();
                $todayIncome = $transactions->where('type', 'income')->sum('amount');
                $todayExpense = $transactions->where('type', 'expense')->sum('amount');

                $totalCount = $tasks->count();
                $completedCount = $completed->count();
                $percentage = $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0;
                $totalPoints = $completed->sum('points_earned');

                $message = "🌙 <b>Evening Summary</b>\n\n";
                $message .= "📅 " . now()->format('l, F j, Y') . "\n\n";

                // Task summary
                $message .= "📊 <b>Today's Progress:</b>\n";
                $message .= "✅ Tasks completed: {$completedCount}/{$totalCount} ({$percentage}%)\n";
                $message .= "🎯 Points earned: {$totalPoints}\n";
                $message .= "🔥 Current streak: {$user->streak_days} days\n\n";

                // Financial summary
                if ($transactions->isNotEmpty()) {
                    $message .= "💰 <b>Financial Summary:</b>\n";
                    if ($todayIncome > 0) {
                        $message .= "💵 Income: +\$" . number_format($todayIncome, 2) . "\n";
                    }
                    if ($todayExpense > 0) {
                        $message .= "💸 Expense: -\$" . number_format($todayExpense, 2) . "\n";
                    }
                    $message .= "\n";
                }

                // Pending tasks
                if ($pending->isNotEmpty()) {
                    $message .= "⏳ <b>Pending Tasks:</b>\n";
                    foreach ($pending->take(3) as $task) {
                        $message .= "• {$task->title}\n";
                    }
                    if ($pending->count() > 3) {
                        $message .= "... and " . ($pending->count() - 3) . " more\n";
                    }
                    $message .= "\n";
                }

                // Encouragement
                if ($percentage >= 100) {
                    $message .= "🎉 <b>Perfect day!</b> All tasks completed!";
                } elseif ($percentage >= 75) {
                    $message .= "👍 <b>Great job!</b> Almost there!";
                } elseif ($percentage >= 50) {
                    $message .= "💪 <b>Good progress!</b> Keep it up!";
                } else {
                    $message .= "🌱 <b>Every step counts.</b> Tomorrow is a new day!";
                }

                $keyboard = [];
                if ($pending->isNotEmpty()) {
                    $keyboard[] = [
                        ['text' => '📅 Move to Tomorrow', 'callback_data' => 'move_pending_tomorrow'],
                    ];
                }
                $keyboard[] = [
                    ['text' => '📊 Full Stats', 'callback_data' => 'stats_today'],
                ];

                $bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
                $sent++;

                usleep(100000);

            } catch (\Exception $e) {
                $this->error("Failed for user {$user->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Sent: {$sent}, Failed: {$failed}");

        return Command::SUCCESS;
    }
}

