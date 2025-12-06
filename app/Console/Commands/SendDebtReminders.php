<?php

namespace App\Console\Commands;

use App\Models\Debt;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Console\Command;

class SendDebtReminders extends Command
{
    protected $signature = 'telegram:debt-reminders';
    protected $description = 'Send debt reminders for due and overdue debts';

    public function handle(TelegramBotService $bot): int
    {
        $this->info('Sending debt reminders...');

        // Update overdue statuses first
        Debt::query()
            ->whereIn('status', ['active', 'partial'])
            ->whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->update(['status' => 'overdue']);

        // Get all debts that need reminders
        $debts = Debt::with('telegramUser')
            ->where('reminder_enabled', true)
            ->whereIn('status', ['active', 'partial', 'overdue'])
            ->whereHas('telegramUser', function ($query) {
                $query->where('debt_reminders', true)
                    ->where('is_blocked', false);
            })
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($debts as $debt) {
            try {
                if (!$debt->needsReminder()) {
                    continue;
                }

                $user = $debt->telegramUser;
                $emoji = $debt->type === 'given' ? '📤' : '📥';
                $daysUntilDue = $debt->getDaysUntilDue();

                $message = "";

                if ($daysUntilDue < 0) {
                    // Overdue
                    $daysOverdue = abs($daysUntilDue);
                    $message = "🔴 <b>Overdue Debt Reminder!</b>\n\n";
                    $message .= "{$emoji} Debt with <b>{$debt->person_name}</b>\n";
                    $message .= "💰 Amount: {$debt->getFormattedRemainingAmount()}\n";
                    $message .= "⚠️ Overdue by {$daysOverdue} days!\n";
                    
                    if ($debt->type === 'given') {
                        $message .= "\n💡 Consider following up with {$debt->person_name}.";
                    } else {
                        $message .= "\n💡 Please settle this debt as soon as possible.";
                    }
                } elseif ($daysUntilDue === 0) {
                    // Due today
                    $message = "⚠️ <b>Debt Due Today!</b>\n\n";
                    $message .= "{$emoji} Debt with <b>{$debt->person_name}</b>\n";
                    $message .= "💰 Amount: {$debt->getFormattedRemainingAmount()}\n";
                    $message .= "📅 Due: Today!\n";
                } else {
                    // Due soon
                    $message = "⏰ <b>Debt Reminder</b>\n\n";
                    $message .= "{$emoji} Debt with <b>{$debt->person_name}</b>\n";
                    $message .= "💰 Amount: {$debt->getFormattedRemainingAmount()}\n";
                    $message .= "📅 Due in {$daysUntilDue} days ({$debt->due_date->format('M j')})\n";
                }

                if ($debt->note) {
                    $message .= "📝 Note: {$debt->note}\n";
                }

                $keyboard = [
                    [
                        ['text' => '✅ Mark as Paid', 'callback_data' => "debt_pay:{$debt->id}"],
                        ['text' => '💳 Partial Payment', 'callback_data' => "debt_partial:{$debt->id}"],
                    ],
                    [
                        ['text' => '👁️ View Details', 'callback_data' => "debt_view:{$debt->id}"],
                    ],
                ];

                $bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
                
                $debt->markReminderSent();
                $sent++;

                usleep(100000);

            } catch (\Exception $e) {
                $this->error("Failed for debt {$debt->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Sent: {$sent}, Failed: {$failed}");

        return Command::SUCCESS;
    }
}


