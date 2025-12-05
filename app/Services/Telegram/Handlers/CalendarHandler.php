<?php

namespace App\Services\Telegram\Handlers;

use App\Models\TelegramUser;
use App\Services\Telegram\TelegramBotService;
use Carbon\Carbon;

class CalendarHandler
{
    protected TelegramBotService $bot;

    public function __construct(TelegramBotService $bot)
    {
        $this->bot = $bot;
    }

    public function showToday(TelegramUser $user): void
    {
        $date = today();
        $this->showDayView($user, $date);
    }

    public function showWeek(TelegramUser $user): void
    {
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        $tasks = $user->tasks()
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
            ->orderBy('date')
            ->get()
            ->groupBy(fn($task) => $task->date->format('Y-m-d'));

        $transactions = $user->transactions()
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
            ->get()
            ->groupBy(fn($tx) => $tx->date->format('Y-m-d'));

        $debts = $user->debts()
            ->active()
            ->whereBetween('due_date', [$startOfWeek, $endOfWeek])
            ->get()
            ->groupBy(fn($debt) => $debt->due_date->format('Y-m-d'));

        $message = "📆 <b>This Week</b>\n";
        $message .= "{$startOfWeek->format('M j')} - {$endOfWeek->format('M j, Y')}\n\n";

        for ($i = 0; $i < 7; $i++) {
            $date = $startOfWeek->copy()->addDays($i);
            $dateKey = $date->format('Y-m-d');
            $isToday = $date->isToday();

            $dayName = $date->format('D');
            $dayNum = $date->format('j');

            $message .= $isToday ? "▶️ " : "   ";
            $message .= "<b>{$dayName} {$dayNum}</b>";

            $dayTasks = $tasks->get($dateKey, collect());
            $dayTx = $transactions->get($dateKey, collect());
            $dayDebts = $debts->get($dateKey, collect());

            $indicators = [];
            if ($dayTasks->isNotEmpty()) {
                $pending = $dayTasks->where('status', 'pending')->count();
                $completed = $dayTasks->where('status', 'completed')->count();
                $indicators[] = "📋{$pending}/{$dayTasks->count()}";
            }
            if ($dayTx->isNotEmpty()) {
                $income = $dayTx->where('type', 'income')->sum('amount');
                $expense = $dayTx->where('type', 'expense')->sum('amount');
                if ($income > 0) $indicators[] = "💵+{$income}";
                if ($expense > 0) $indicators[] = "💸-{$expense}";
            }
            if ($dayDebts->isNotEmpty()) {
                $indicators[] = "⏰{$dayDebts->count()}";
            }

            if (!empty($indicators)) {
                $message .= " | " . implode(' ', $indicators);
            }

            $message .= "\n";
        }

        // Week summary
        $weekTasks = $tasks->flatten();
        $weekTx = $transactions->flatten();

        $message .= "\n<b>Week Summary:</b>\n";
        $message .= "📋 Tasks: {$weekTasks->where('status', 'completed')->count()}/{$weekTasks->count()} done\n";
        $message .= "💵 Income: \$" . number_format($weekTx->where('type', 'income')->sum('amount'), 2) . "\n";
        $message .= "💸 Expenses: \$" . number_format($weekTx->where('type', 'expense')->sum('amount'), 2);

        $keyboard = [
            [
                ['text' => '◀️ Prev Week', 'callback_data' => 'cal_nav:week_' . $startOfWeek->subWeek()->format('Y-m-d')],
                ['text' => 'Next Week ▶️', 'callback_data' => 'cal_nav:week_' . $endOfWeek->addDay()->format('Y-m-d')],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showMonth(TelegramUser $user, ?Carbon $date = null): void
    {
        $date = $date ?? now();
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        $tasks = $user->tasks()
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->get()
            ->groupBy(fn($task) => $task->date->format('j'));

        $transactions = $user->transactions()
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->get();

        $debts = $user->debts()
            ->active()
            ->whereBetween('due_date', [$startOfMonth, $endOfMonth])
            ->get()
            ->groupBy(fn($debt) => $debt->due_date->format('j'));

        $message = "🗓️ <b>{$date->format('F Y')}</b>\n\n";

        // Calendar header
        $message .= "Mo Tu We Th Fr Sa Su\n";

        // Get the day of week for the first day (0 = Monday, 6 = Sunday)
        $firstDayOfWeek = $startOfMonth->dayOfWeekIso - 1;

        // Add empty spaces for days before the first of the month
        $message .= str_repeat("   ", $firstDayOfWeek);

        $daysInMonth = $endOfMonth->day;
        $currentDay = now()->day;
        $currentMonth = now()->month;

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $hasTask = $tasks->has((string)$day);
            $hasDebt = $debts->has((string)$day);
            $isToday = $date->month === $currentMonth && $day === $currentDay;

            if ($isToday) {
                $message .= "▶️";
            } elseif ($hasDebt) {
                $message .= "⏰";
            } elseif ($hasTask) {
                $dayTasks = $tasks->get((string)$day);
                $allDone = $dayTasks->every(fn($t) => $t->status === 'completed');
                $message .= $allDone ? "✅" : "📋";
            } else {
                $message .= sprintf("%2d", $day);
            }

            // New line after Sunday
            $dayOfWeek = ($firstDayOfWeek + $day) % 7;
            if ($dayOfWeek === 0) {
                $message .= "\n";
            } else {
                $message .= " ";
            }
        }

        // Month summary
        $allTasks = $tasks->flatten();
        $message .= "\n\n<b>Month Summary:</b>\n";
        $message .= "📋 Tasks: {$allTasks->where('status', 'completed')->count()}/{$allTasks->count()}\n";
        $message .= "💵 Income: \$" . number_format($transactions->where('type', 'income')->sum('amount'), 2) . "\n";
        $message .= "💸 Expenses: \$" . number_format($transactions->where('type', 'expense')->sum('amount'), 2);

        $keyboard = [
            [
                ['text' => '◀️ Prev', 'callback_data' => 'cal_nav:month_' . $startOfMonth->subMonth()->format('Y-m')],
                ['text' => 'Today', 'callback_data' => 'cal_nav:month_' . now()->format('Y-m')],
                ['text' => 'Next ▶️', 'callback_data' => 'cal_nav:month_' . $endOfMonth->addDay()->format('Y-m')],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showYear(TelegramUser $user): void
    {
        $year = now()->year;

        $message = "📊 <b>{$year} Overview</b>\n\n";

        $totalTasks = 0;
        $completedTasks = 0;
        $totalIncome = 0;
        $totalExpense = 0;

        for ($month = 1; $month <= 12; $month++) {
            $startDate = Carbon::create($year, $month, 1);
            $endDate = $startDate->copy()->endOfMonth();

            $monthTasks = $user->tasks()
                ->whereBetween('date', [$startDate, $endDate])
                ->get();

            $monthTx = $user->transactions()
                ->whereBetween('date', [$startDate, $endDate])
                ->get();

            $taskCount = $monthTasks->count();
            $doneCount = $monthTasks->where('status', 'completed')->count();
            $income = $monthTx->where('type', 'income')->sum('amount');
            $expense = $monthTx->where('type', 'expense')->sum('amount');

            $totalTasks += $taskCount;
            $completedTasks += $doneCount;
            $totalIncome += $income;
            $totalExpense += $expense;

            $monthName = $startDate->format('M');
            $isCurrentMonth = now()->month === $month;

            $message .= $isCurrentMonth ? "▶️ " : "   ";
            $message .= "<b>{$monthName}</b>: ";

            if ($taskCount > 0) {
                $percentage = round(($doneCount / $taskCount) * 100);
                $message .= "📋{$percentage}% ";
            }

            $net = $income - $expense;
            $netEmoji = $net >= 0 ? '💚' : '❤️';
            $message .= "{$netEmoji}\$" . number_format(abs($net), 0);
            $message .= "\n";
        }

        $message .= "\n<b>Year Total:</b>\n";
        $percentage = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;
        $message .= "📋 Tasks: {$completedTasks}/{$totalTasks} ({$percentage}%)\n";
        $message .= "💵 Income: \$" . number_format($totalIncome, 2) . "\n";
        $message .= "💸 Expenses: \$" . number_format($totalExpense, 2) . "\n";
        $message .= "📊 Net: \$" . number_format($totalIncome - $totalExpense, 2);

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function startCustomRange(TelegramUser $user): void
    {
        $user->setState('calendar_range', ['step' => 'start_date']);

        $this->bot->sendMessage(
            $user->telegram_id,
            "🔍 <b>Custom Date Range</b>\n\n" .
            "Enter the start date:\n\n" .
            "Format: <code>YYYY-MM-DD</code> or <code>DD.MM.YYYY</code>\n" .
            "Example: <code>2024-01-01</code>"
        );
    }

    public function showDay(TelegramUser $user, string $dateString, ?int $messageId): void
    {
        $date = Carbon::parse($dateString);
        $this->showDayView($user, $date, $messageId);
    }

    public function navigate(TelegramUser $user, string $value, ?int $messageId): void
    {
        [$type, $dateString] = explode('_', $value, 2);

        match ($type) {
            'week' => $this->showWeekFromDate($user, Carbon::parse($dateString), $messageId),
            'month' => $this->showMonthFromDate($user, Carbon::parse($dateString . '-01'), $messageId),
            'day' => $this->showDayView($user, Carbon::parse($dateString), $messageId),
            default => null,
        };
    }

    protected function showDayView(TelegramUser $user, Carbon $date, ?int $messageId = null): void
    {
        $tasks = $user->tasks()->whereDate('date', $date)->orderBy('time')->get();
        $transactions = $user->transactions()->whereDate('date', $date)->get();
        $debts = $user->debts()->active()->whereDate('due_date', $date)->get();

        $message = "📅 <b>{$date->format('l, F j, Y')}</b>\n\n";

        // Tasks
        if ($tasks->isNotEmpty()) {
            $message .= "<b>📋 Tasks:</b>\n";
            foreach ($tasks as $task) {
                $status = $task->status === 'completed' ? '✅' : $task->getPriorityEmoji();
                $time = $task->time ? substr($task->time, 0, 5) . ' ' : '';
                $message .= "{$status} {$time}{$task->title}\n";
            }
            $message .= "\n";
        }

        // Transactions
        if ($transactions->isNotEmpty()) {
            $message .= "<b>💰 Transactions:</b>\n";
            foreach ($transactions as $tx) {
                $emoji = $tx->type === 'income' ? '💵' : '💸';
                $message .= "{$emoji} {$tx->getFormattedAmount()} - {$tx->note}\n";
            }

            $income = $transactions->where('type', 'income')->sum('amount');
            $expense = $transactions->where('type', 'expense')->sum('amount');
            $message .= "📊 Net: \$" . number_format($income - $expense, 2) . "\n\n";
        }

        // Debts due
        if ($debts->isNotEmpty()) {
            $message .= "<b>⏰ Debts Due:</b>\n";
            foreach ($debts as $debt) {
                $emoji = $debt->type === 'given' ? '📤' : '📥';
                $message .= "{$emoji} {$debt->person_name}: {$debt->getFormattedRemainingAmount()}\n";
            }
            $message .= "\n";
        }

        if ($tasks->isEmpty() && $transactions->isEmpty() && $debts->isEmpty()) {
            $message .= "No activities for this day.";
        }

        $keyboard = [
            [
                ['text' => '◀️ Prev Day', 'callback_data' => 'cal_nav:day_' . $date->copy()->subDay()->format('Y-m-d')],
                ['text' => 'Next Day ▶️', 'callback_data' => 'cal_nav:day_' . $date->copy()->addDay()->format('Y-m-d')],
            ],
            [
                ['text' => '📆 Week View', 'callback_data' => 'cal_nav:week_' . $date->copy()->startOfWeek()->format('Y-m-d')],
                ['text' => '🗓️ Month View', 'callback_data' => 'cal_nav:month_' . $date->format('Y-m')],
            ],
        ];

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    protected function showWeekFromDate(TelegramUser $user, Carbon $date, ?int $messageId): void
    {
        // For now, just call showWeek with the date context
        // In a full implementation, you'd pass the date and handle editing
        $this->showWeek($user);
    }

    protected function showMonthFromDate(TelegramUser $user, Carbon $date, ?int $messageId): void
    {
        $this->showMonth($user, $date);
    }
}

