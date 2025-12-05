<?php

namespace App\Services\Telegram\Handlers;

use App\Models\Debt;
use App\Models\TelegramUser;
use App\Services\Telegram\TelegramBotService;

class DebtHandler
{
    protected TelegramBotService $bot;

    public function __construct(TelegramBotService $bot)
    {
        $this->bot = $bot;
    }

    public function startAddGivenDebt(TelegramUser $user): void
    {
        $user->setState('adding_debt', ['type' => 'given', 'step' => 'person']);
        
        $this->bot->sendMessage(
            $user->telegram_id,
            "📤 <b>Qarz berdim</b>\n\n" .
            "Kimga qarz berdingiz?\n\n" .
            "Ism yoki nom kiriting:"
        );
    }

    public function startAddReceivedDebt(TelegramUser $user): void
    {
        $user->setState('adding_debt', ['type' => 'received', 'step' => 'person']);
        
        $this->bot->sendMessage(
            $user->telegram_id,
            "📥 <b>Qarz oldim</b>\n\n" .
            "Kimdan qarz oldingiz?\n\n" .
            "Ism yoki nom kiriting:"
        );
    }

    public function showActiveDebts(TelegramUser $user): void
    {
        $debts = $user->debts()->active()->orderBy('due_date')->get();

        if ($debts->isEmpty()) {
            $this->bot->sendMessage(
                $user->telegram_id,
                "📋 <b>Faol qarzlar</b>\n\n" .
                "Faol qarzlar yo'q! 🎉\n\n" .
                "Siz qarzdan xolisiz!"
            );
            return;
        }

        $this->displayDebtList($user, $debts, "📋 Faol qarzlar");
    }

    public function showDueSoon(TelegramUser $user): void
    {
        $debts = $user->debts()->dueSoon(7)->orderBy('due_date')->get();

        if ($debts->isEmpty()) {
            $this->bot->sendMessage(
                $user->telegram_id,
                "⏰ <b>Muddati yaqin</b>\n\n" .
                "Keyingi 7 kunda muddati tugaydigan qarz yo'q."
            );
            return;
        }

        $this->displayDebtList($user, $debts, "⏰ Muddati yaqin qarzlar");
    }

    public function showPaidDebts(TelegramUser $user): void
    {
        $debts = $user->debts()
            ->where('status', 'paid')
            ->orderBy('paid_at', 'desc')
            ->limit(20)
            ->get();

        if ($debts->isEmpty()) {
            $this->bot->sendMessage(
                $user->telegram_id,
                "✅ <b>To'langan qarzlar</b>\n\n" .
                "To'langan qarzlar yo'q."
            );
            return;
        }

        $this->displayDebtList($user, $debts, "✅ To'langan qarzlar");
    }

    public function showDebtSummary(TelegramUser $user): void
    {
        $givenActive = $user->debts()->given()->active()->get();
        $receivedActive = $user->debts()->received()->active()->get();

        $givenTotal = $givenActive->sum('amount') - $givenActive->sum('amount_paid');
        $receivedTotal = $receivedActive->sum('amount') - $receivedActive->sum('amount_paid');

        $overdueCount = $user->debts()->overdue()->count();

        $message = "📊 <b>Qarz xulosasi</b>\n\n";

        $message .= "📤 <b>Men bergan qarz (menga qarzdor):</b>\n";
        $message .= "   Jami: " . number_format($givenTotal, 0, '.', ' ') . " so'm\n";
        $message .= "   Faol qarzlar: {$givenActive->count()} ta\n\n";

        $message .= "📥 <b>Men olgan qarz (men qarzdorman):</b>\n";
        $message .= "   Jami: " . number_format($receivedTotal, 0, '.', ' ') . " so'm\n";
        $message .= "   Faol qarzlar: {$receivedActive->count()} ta\n\n";

        $netPosition = $givenTotal - $receivedTotal;
        $netEmoji = $netPosition >= 0 ? '💚' : '❤️';
        $netText = $netPosition >= 0 
            ? "Sizga " . number_format($netPosition, 0, '.', ' ') . " so'm qarzdor"
            : "Siz " . number_format(abs($netPosition), 0, '.', ' ') . " so'm qarzdorsiz";

        $message .= "{$netEmoji} <b>Holat:</b> {$netText}\n\n";

        if ($overdueCount > 0) {
            $message .= "⚠️ <b>Muddati o'tgan qarzlar: {$overdueCount} ta</b>";
        } else {
            $message .= "✅ Muddati o'tgan qarzlar yo'q";
        }

        if ($givenActive->isNotEmpty()) {
            $byPerson = $givenActive->groupBy('person_name')
                ->map(fn($debts) => $debts->sum('amount') - $debts->sum('amount_paid'))
                ->sortDesc()
                ->take(3);

            $message .= "\n\n<b>Eng ko'p qarzdorlar:</b>\n";
            foreach ($byPerson as $person => $amount) {
                $message .= "   👤 {$person}: " . number_format($amount, 0, '.', ' ') . " so'm\n";
            }
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function markDebtPaid(TelegramUser $user, string $debtId, ?int $messageId): void
    {
        $debt = $user->debts()->find($debtId);
        
        if (!$debt) {
            $this->bot->sendMessage($user->telegram_id, "❌ Qarz topilmadi.");
            return;
        }

        $debt->markAsPaid();

        $emoji = $debt->type === 'given' ? '📤' : '📥';
        $message = "✅ <b>Qarz to'landi!</b>\n\n" .
            "{$emoji} {$debt->person_name}\n" .
            "💰 " . number_format($debt->amount, 0, '.', ' ') . " so'm\n" .
            "📅 To'langan: " . now()->format('d.m.Y');

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }

        $activeDebtsCount = $user->debts()->active()->count();
        if ($activeDebtsCount === 0) {
            \App\Models\UserAchievement::award($user, 'debt_free');
        }
    }

    public function startPartialPayment(TelegramUser $user, string $debtId, ?int $messageId): void
    {
        $debt = $user->debts()->find($debtId);
        
        if (!$debt) {
            $this->bot->sendMessage($user->telegram_id, "❌ Qarz topilmadi.");
            return;
        }

        $user->setState('partial_payment', ['debt_id' => $debt->id]);

        $message = "💳 <b>Qisman to'lov</b>\n\n" .
            "Qolgan summa: " . number_format($debt->getRemainingAmount(), 0, '.', ' ') . " so'm\n\n" .
            "To'lov summasini kiriting:";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function viewDebt(TelegramUser $user, string $debtId, ?int $messageId): void
    {
        $debt = $user->debts()->find($debtId);
        
        if (!$debt) {
            $this->bot->sendMessage($user->telegram_id, "❌ Qarz topilmadi.");
            return;
        }

        $message = $this->formatDebtDetails($debt);

        $keyboard = [];
        
        if ($debt->status !== 'paid') {
            $keyboard[] = [
                ['text' => '✅ To\'landi', 'callback_data' => "debt_pay:{$debt->id}"],
                ['text' => '💳 Qisman to\'lov', 'callback_data' => "debt_partial:{$debt->id}"],
            ];
        }

        $keyboard[] = [
            ['text' => '🗑️ O\'chirish', 'callback_data' => "debt_delete:{$debt->id}"],
        ];

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    public function deleteDebt(TelegramUser $user, string $debtId, ?int $messageId): void
    {
        $debt = $user->debts()->find($debtId);
        
        if (!$debt) {
            return;
        }

        $keyboard = [
            [
                ['text' => '✅ Ha, o\'chirish', 'callback_data' => "debt_confirm_delete:{$debtId}"],
                ['text' => '❌ Bekor qilish', 'callback_data' => "debt_view:{$debtId}"],
            ],
        ];

        $message = "🗑️ <b>Qarzni o'chirish?</b>\n\n" .
            "👤 {$debt->person_name}\n" .
            "💰 " . number_format($debt->amount, 0, '.', ' ') . " so'm\n\n" .
            "Rostdan ham o'chirmoqchimisiz?";

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message, $keyboard);
        } else {
            $this->bot->sendMessageWithInlineKeyboard($user->telegram_id, $message, $keyboard);
        }
    }

    public function confirmDebt(TelegramUser $user, string $value, ?int $messageId): void
    {
        if ($value === 'cancel') {
            $user->clearState();
            $this->bot->editMessage($user->telegram_id, $messageId, "❌ Bekor qilindi.");
            return;
        }

        if (str_starts_with($value, 'delete:')) {
            $debtId = str_replace('delete:', '', $value);
            $debt = $user->debts()->find($debtId);
            
            if ($debt) {
                $debt->delete();
                $this->bot->editMessage($user->telegram_id, $messageId, "🗑️ Qarz o'chirildi.");
            }
            return;
        }

        $data = $user->state_data;

        $debt = Debt::create([
            'telegram_user_id' => $user->id,
            'type' => $data['type'],
            'person_name' => $data['person'],
            'person_contact' => $data['contact'] ?? null,
            'amount' => $data['amount'],
            'currency' => $user->currency,
            'note' => $data['note'] ?? null,
            'date' => today(),
            'due_date' => $data['due_date'] ?? null,
        ]);

        $user->clearState();

        $emoji = $debt->type === 'given' ? '📤' : '📥';
        $typeText = $debt->type === 'given' ? 'Qarz berdim' : 'Qarz oldim';

        $message = "✅ <b>Qarz qo'shildi!</b>\n\n" .
            "{$emoji} {$typeText}\n" .
            "👤 Shaxs: {$debt->person_name}\n" .
            "💰 Summa: " . number_format($debt->amount, 0, '.', ' ') . " so'm\n";

        if ($debt->due_date) {
            $message .= "📅 Muddat: {$debt->due_date->format('d.m.Y')}\n";
        }

        if ($debt->note) {
            $message .= "📝 Izoh: {$debt->note}";
        }

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }
    }

    public function showDebtsPage(TelegramUser $user, int $page, ?int $messageId): void
    {
        $perPage = 5;
        $debts = $user->debts()
            ->active()
            ->orderBy('due_date')
            ->paginate($perPage, ['*'], 'page', $page);

        $this->displayDebtList($user, $debts->items(), "📋 Qarzlar ({$page}-sahifa)", $messageId, [
            'current_page' => $page,
            'last_page' => $debts->lastPage(),
        ]);
    }

    protected function displayDebtList(TelegramUser $user, $debts, string $title, ?int $messageId = null, array $pagination = []): void
    {
        if (empty($debts) || (is_countable($debts) && count($debts) === 0)) {
            $this->bot->sendMessage($user->telegram_id, "{$title}\n\nQarz topilmadi.");
            return;
        }

        $message = "<b>{$title}</b>\n\n";

        foreach ($debts as $debt) {
            $emoji = $debt->type === 'given' ? '📤' : '📥';
            $statusEmoji = $debt->getStatusEmoji();
            
            $message .= "{$emoji} {$statusEmoji} <b>{$debt->person_name}</b>\n";
            $message .= "   💰 " . number_format($debt->amount, 0, '.', ' ') . " so'm";
            
            if ($debt->amount_paid > 0) {
                $message .= " (to'langan: " . number_format($debt->amount_paid, 0, '.', ' ') . ")";
            }
            $message .= "\n";
            
            if ($debt->due_date) {
                $daysUntil = $debt->getDaysUntilDue();
                if ($daysUntil < 0) {
                    $message .= "   ⚠️ " . abs($daysUntil) . " kun o'tib ketdi\n";
                } elseif ($daysUntil === 0) {
                    $message .= "   ⏰ Bugun!\n";
                } elseif ($daysUntil <= 3) {
                    $message .= "   ⏰ {$daysUntil} kun qoldi\n";
                } else {
                    $message .= "   📅 Muddat: {$debt->due_date->format('d.m')}\n";
                }
            }
            $message .= "\n";
        }

        $keyboard = [];
        foreach ($debts as $debt) {
            if ($debt->status !== 'paid') {
                $keyboard[] = [
                    ['text' => "👁️ {$debt->person_name}", 'callback_data' => "debt_view:{$debt->id}"],
                    ['text' => '✅', 'callback_data' => "debt_pay:{$debt->id}"],
                ];
            }
        }

        if (!empty($pagination)) {
            $navRow = [];
            if ($pagination['current_page'] > 1) {
                $navRow[] = ['text' => '◀️ Oldingi', 'callback_data' => 'page:debts_' . ($pagination['current_page'] - 1)];
            }
            if ($pagination['current_page'] < $pagination['last_page']) {
                $navRow[] = ['text' => 'Keyingi ▶️', 'callback_data' => 'page:debts_' . ($pagination['current_page'] + 1)];
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

    protected function formatDebtDetails(Debt $debt): string
    {
        $emoji = $debt->type === 'given' ? '📤' : '📥';
        $typeText = $debt->type === 'given' ? 'Men bergan qarz' : 'Men olgan qarz';

        $message = "{$debt->getStatusEmoji()} <b>{$typeText}</b>\n\n";
        $message .= "👤 Shaxs: {$debt->person_name}\n";
        
        if ($debt->person_contact) {
            $message .= "📱 Aloqa: {$debt->person_contact}\n";
        }
        
        $message .= "💰 Summa: " . number_format($debt->amount, 0, '.', ' ') . " so'm\n";
        
        if ($debt->amount_paid > 0) {
            $message .= "✅ To'langan: " . number_format($debt->amount_paid, 0, '.', ' ') . " so'm\n";
            $message .= "📊 Qolgan: " . number_format($debt->getRemainingAmount(), 0, '.', ' ') . " so'm\n";
        }
        
        $message .= "📅 Yaratilgan: {$debt->date->format('d.m.Y')}\n";
        
        if ($debt->due_date) {
            $daysUntil = $debt->getDaysUntilDue();
            $message .= "⏰ Muddat: {$debt->due_date->format('d.m.Y')}";
            
            if ($debt->status !== 'paid') {
                if ($daysUntil < 0) {
                    $message .= " <b>(Muddati o'tgan!)</b>";
                } elseif ($daysUntil === 0) {
                    $message .= " <b>(Bugun!)</b>";
                } elseif ($daysUntil <= 3) {
                    $message .= " ({$daysUntil} kun qoldi)";
                }
            }
            $message .= "\n";
        }
        
        if ($debt->note) {
            $message .= "📝 Izoh: {$debt->note}\n";
        }
        
        if ($debt->paid_at) {
            $message .= "\n✅ To'langan sana: {$debt->paid_at->format('d.m.Y')}";
        }

        return $message;
    }
}
