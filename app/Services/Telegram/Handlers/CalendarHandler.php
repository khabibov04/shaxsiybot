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
        $this->showDay($user, today()->format('Y-m-d'));
    }

    public function showWeek(TelegramUser $user): void
    {
        $startOfWeek = now()->startOfWeek();
        $endOfWeek = now()->endOfWeek();

        $tasks = $user->tasks()
            ->whereBetween('date', [$startOfWeek, $endOfWeek])
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

        $weekdays = ['Du', 'Se', 'Cho', 'Pa', 'Ju', 'Sha', 'Ya'];
        $message = "📆 <b>Haftalik taqvim</b>\n";
        $message .= "{$startOfWeek->format('d.m')} - {$endOfWeek->format('d.m.Y')}\n\n";

        for ($i = 0; $i < 7; $i++) {
            $day = $startOfWeek->copy()->addDays($i);
            $dateKey = $day->format('Y-m-d');
            $isToday = $day->isToday();
            
            $dayName = $weekdays[$i];
            $dayNum = $day->format('d');
            
            $prefix = $isToday ? '👉 ' : '';
            $suffix = $isToday ? ' ◀️' : '';
            
            $message .= "{$prefix}<b>{$dayName}, {$dayNum}</b>{$suffix}\n";

            $dayTasks = $tasks->get($dateKey, collect());
            $dayTx = $transactions->get($dateKey, collect());
            $dayDebts = $debts->get($dateKey, collect());

            if ($dayTasks->isEmpty() && $dayTx->isEmpty() && $dayDebts->isEmpty()) {
                $message .= "   <i>Bo'sh</i>\n";
            } else {
                if ($dayTasks->isNotEmpty()) {
                    $completed = $dayTasks->where('status', 'completed')->count();
                    $total = $dayTasks->count();
                    $message .= "   📋 Vazifalar: {$completed}/{$total}\n";
                }
                
                if ($dayTx->isNotEmpty()) {
                    $income = $dayTx->where('type', 'income')->sum('amount');
                    $expense = $dayTx->where('type', 'expense')->sum('amount');
                    if ($income > 0) {
                        $message .= "   💵 +" . number_format($income, 0, '.', ' ') . "\n";
                    }
                    if ($expense > 0) {
                        $message .= "   💸 -" . number_format($expense, 0, '.', ' ') . "\n";
                    }
                }
                
                if ($dayDebts->isNotEmpty()) {
                    $message .= "   ⏰ Qarz muddati: {$dayDebts->count()} ta\n";
                }
            }
            $message .= "\n";
        }

        $keyboard = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $startOfWeek->copy()->addDays($i);
            if ($i % 4 === 0) {
                $keyboard[] = [];
            }
            $keyboard[count($keyboard) - 1][] = [
                'text' => $day->format('d'),
                'callback_data' => 'cal_day:' . $day->format('Y-m-d'),
            ];
        }

        $keyboard[] = [
            ['text' => '◀️ Oldingi hafta', 'callback_data' => 'cal_nav:week_prev'],
            ['text' => 'Keyingi hafta ▶️', 'callback_data' => 'cal_nav:week_next'],
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
            ->get();

        $transactions = $user->transactions()
            ->whereBetween('date', [$startOfMonth, $endOfMonth])
            ->get();

        $debts = $user->debts()
            ->active()
            ->whereBetween('due_date', [$startOfMonth, $endOfMonth])
            ->get();

        $months = [
            1 => 'Yanvar', 2 => 'Fevral', 3 => 'Mart', 4 => 'Aprel',
            5 => 'May', 6 => 'Iyun', 7 => 'Iyul', 8 => 'Avgust',
            9 => 'Sentabr', 10 => 'Oktabr', 11 => 'Noyabr', 12 => 'Dekabr'
        ];

        $monthName = $months[$date->month];
        $message = "🗓️ <b>{$monthName} {$date->year}</b>\n\n";

        $completedTasks = $tasks->where('status', 'completed')->count();
        $totalTasks = $tasks->count();

        $income = $transactions->where('type', 'income')->sum('amount');
        $expense = $transactions->where('type', 'expense')->sum('amount');

        $message .= "<b>📋 Vazifalar:</b> {$completedTasks}/{$totalTasks}\n";
        $message .= "<b>💵 Daromad:</b> " . number_format($income, 0, '.', ' ') . " so'm\n";
        $message .= "<b>💸 Xarajat:</b> " . number_format($expense, 0, '.', ' ') . " so'm\n";
        $message .= "<b>📊 Farq:</b> " . number_format($income - $expense, 0, '.', ' ') . " so'm\n";
        
        if ($debts->count() > 0) {
            $message .= "<b>⏰ Qarz muddatlari:</b> {$debts->count()} ta\n";
        }

        $message .= "\n<b>Taqvim:</b>\n";
        $message .= "<code>Du Se Ch Pa Ju Sh Ya</code>\n";

        $dayOfWeek = $startOfMonth->dayOfWeekIso;
        $message .= "<code>" . str_repeat("   ", $dayOfWeek - 1);

        for ($day = 1; $day <= $endOfMonth->day; $day++) {
            $currentDate = $startOfMonth->copy()->addDays($day - 1);
            $dateKey = $currentDate->format('Y-m-d');
            
            $hasTasks = $tasks->where('date', $currentDate->format('Y-m-d'))->isNotEmpty();
            $hasTx = $transactions->where('date', $currentDate->format('Y-m-d'))->isNotEmpty();
            $hasDebt = $debts->filter(fn($d) => $d->due_date->format('Y-m-d') === $dateKey)->isNotEmpty();
            
            $isToday = $currentDate->isToday();
            
            $dayStr = str_pad($day, 2, ' ', STR_PAD_LEFT);
            
            if ($isToday) {
                $dayStr = "[{$day}]";
                $dayStr = str_pad($dayStr, 4, ' ', STR_PAD_BOTH);
                $dayStr = substr($dayStr, 0, 3);
            }
            
            $message .= $dayStr;
            
            if (($dayOfWeek - 1 + $day) % 7 === 0) {
                $message .= "\n";
            } else {
                $message .= " ";
            }
        }
        $message .= "</code>";

        $keyboard = [
            [
                ['text' => '◀️ Oldingi oy', 'callback_data' => 'cal_nav:month_' . $date->copy()->subMonth()->format('Y-m')],
                ['text' => 'Keyingi oy ▶️', 'callback_data' => 'cal_nav:month_' . $date->copy()->addMonth()->format('Y-m')],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showYear(TelegramUser $user, ?int $year = null): void
    {
        $year = $year ?? now()->year;
        $startOfYear = Carbon::create($year)->startOfYear();
        $endOfYear = Carbon::create($year)->endOfYear();

        $tasks = $user->tasks()
            ->whereBetween('date', [$startOfYear, $endOfYear])
            ->get()
            ->groupBy(fn($task) => $task->date->month);

        $transactions = $user->transactions()
            ->whereBetween('date', [$startOfYear, $endOfYear])
            ->get()
            ->groupBy(fn($tx) => $tx->date->month);

        $months = [
            1 => 'Yanvar', 2 => 'Fevral', 3 => 'Mart', 4 => 'Aprel',
            5 => 'May', 6 => 'Iyun', 7 => 'Iyul', 8 => 'Avgust',
            9 => 'Sentabr', 10 => 'Oktabr', 11 => 'Noyabr', 12 => 'Dekabr'
        ];

        $message = "📊 <b>Yillik ko'rinish - {$year}</b>\n\n";

        $totalTasks = 0;
        $totalCompleted = 0;
        $totalIncome = 0;
        $totalExpense = 0;

        foreach ($months as $monthNum => $monthName) {
            $monthTasks = $tasks->get($monthNum, collect());
            $monthTx = $transactions->get($monthNum, collect());

            $completed = $monthTasks->where('status', 'completed')->count();
            $total = $monthTasks->count();
            $income = $monthTx->where('type', 'income')->sum('amount');
            $expense = $monthTx->where('type', 'expense')->sum('amount');

            $totalTasks += $total;
            $totalCompleted += $completed;
            $totalIncome += $income;
            $totalExpense += $expense;

            $isCurrentMonth = now()->year === $year && now()->month === $monthNum;
            $prefix = $isCurrentMonth ? '👉 ' : '';

            $message .= "{$prefix}<b>{$monthName}:</b>\n";
            $message .= "   📋 Vazifalar: {$completed}/{$total}";
            
            if ($income > 0 || $expense > 0) {
                $message .= " | ";
                if ($income > 0) {
                    $message .= "💵 " . number_format($income / 1000, 0) . "K";
                }
                if ($expense > 0) {
                    $message .= " 💸 " . number_format($expense / 1000, 0) . "K";
                }
            }
            $message .= "\n";
        }

        $percentage = $totalTasks > 0 ? round(($totalCompleted / $totalTasks) * 100) : 0;
        
        $message .= "\n<b>📊 Yillik xulosa:</b>\n";
        $message .= "📋 Vazifalar: {$totalCompleted}/{$totalTasks} ({$percentage}%)\n";
        $message .= "💵 Daromad: " . number_format($totalIncome, 0, '.', ' ') . "\n";
        $message .= "💸 Xarajat: " . number_format($totalExpense, 0, '.', ' ') . "\n";
        $message .= "📊 Farq: " . number_format($totalIncome - $totalExpense, 0, '.', ' ');

        $keyboard = [
            [
                ['text' => "◀️ {$year - 1}", 'callback_data' => "cal_nav:year_{$year - 1}"],
                ['text' => "{$year + 1} ▶️", 'callback_data' => "cal_nav:year_{$year + 1}"],
            ],
        ];

        $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
    }

    public function showDay(TelegramUser $user, string $dateStr, ?int $messageId = null): void
    {
        $date = Carbon::parse($dateStr);

        $tasks = $user->tasks()->whereDate('date', $date)->get();
        $transactions = $user->transactions()->whereDate('date', $date)->get();
        $debts = $user->debts()->whereDate('due_date', $date)->get();

        $weekdays = ['Yakshanba', 'Dushanba', 'Seshanba', 'Chorshanba', 'Payshanba', 'Juma', 'Shanba'];
        $dayName = $weekdays[$date->dayOfWeek];

        $message = "📅 <b>{$date->format('d.m.Y')}, {$dayName}</b>\n\n";

        if ($tasks->isEmpty() && $transactions->isEmpty() && $debts->isEmpty()) {
            $message .= "Bu kunda hech narsa yo'q.";
        } else {
            if ($tasks->isNotEmpty()) {
                $message .= "<b>📋 Vazifalar:</b>\n";
                foreach ($tasks as $task) {
                    $status = $task->status === 'completed' ? '✅' : '⏳';
                    $message .= "{$status} {$task->title}\n";
                }
                $message .= "\n";
            }

            if ($transactions->isNotEmpty()) {
                $message .= "<b>💰 Tranzaksiyalar:</b>\n";
                foreach ($transactions as $tx) {
                    $emoji = $tx->type === 'income' ? '💵' : '💸';
                    $sign = $tx->type === 'income' ? '+' : '-';
                    $message .= "{$emoji} {$sign}" . number_format($tx->amount, 0, '.', ' ') . " - {$tx->note}\n";
                }
                $message .= "\n";
            }

            if ($debts->isNotEmpty()) {
                $message .= "<b>⏰ Qarz muddatlari:</b>\n";
                foreach ($debts as $debt) {
                    $emoji = $debt->type === 'given' ? '📤' : '📥';
                    $message .= "{$emoji} {$debt->person_name} - " . number_format($debt->amount, 0, '.', ' ') . "\n";
                }
            }
        }

        $keyboard = [
            [
                ['text' => '◀️ Oldingi kun', 'callback_data' => 'cal_day:' . $date->copy()->subDay()->format('Y-m-d')],
                ['text' => 'Keyingi kun ▶️', 'callback_data' => 'cal_day:' . $date->copy()->addDay()->format('Y-m-d')],
            ],
            [
                ['text' => '📆 Hafta ko\'rinishi', 'callback_data' => 'cal_nav:week_current'],
            ],
        ];

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    public function startCustomRange(TelegramUser $user): void
    {
        $user->setState('custom_range', ['step' => 'start_date']);
        
        $this->bot->sendMessage(
            $user->telegram_id,
            "🔍 <b>Maxsus oraliq</b>\n\n" .
            "Boshlanish sanasini kiriting:\n\n" .
            "💡 Format: <code>DD.MM.YYYY</code>\n" .
            "Misol: <code>01.01.2024</code>"
        );
    }

    public function navigate(TelegramUser $user, string $value, ?int $messageId): void
    {
        [$type, $param] = explode('_', $value, 2) + [null, null];

        match ($type) {
            'week' => $this->navigateWeek($user, $param, $messageId),
            'month' => $this->navigateMonth($user, $param, $messageId),
            'year' => $this->navigateYear($user, $param, $messageId),
            default => null,
        };
    }

    protected function navigateWeek(TelegramUser $user, string $direction, ?int $messageId): void
    {
        // Navigation logic - week
        if ($direction === 'prev') {
            // Previous week logic
        } elseif ($direction === 'next') {
            // Next week logic
        }
        
        $this->showWeek($user);
    }

    protected function navigateMonth(TelegramUser $user, string $param, ?int $messageId): void
    {
        $date = Carbon::parse($param . '-01');
        $this->showMonth($user, $date);
    }

    protected function navigateYear(TelegramUser $user, string $year, ?int $messageId): void
    {
        $this->showYear($user, (int)$year);
    }
}
