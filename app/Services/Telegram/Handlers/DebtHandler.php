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
            "📤 <b>Add Debt (Money I Gave)</b>\n\n" .
            "Who did you lend money to?\n\n" .
            "Enter the person's name:"
        );
    }

    public function startAddReceivedDebt(TelegramUser $user): void
    {
        $user->setState('adding_debt', ['type' => 'received', 'step' => 'person']);
        
        $this->bot->sendMessage(
            $user->telegram_id,
            "📥 <b>Add Debt (Money I Owe)</b>\n\n" .
            "Who did you borrow money from?\n\n" .
            "Enter the person's name:"
        );
    }

    public function showActiveDebts(TelegramUser $user): void
    {
        $debts = $user->debts()->active()->orderBy('due_date')->get();

        if ($debts->isEmpty()) {
            $this->bot->sendMessage(
                $user->telegram_id,
                "📋 <b>Active Debts</b>\n\n" .
                "No active debts! 🎉\n\n" .
                "You're debt-free!"
            );
            return;
        }

        $this->displayDebtList($user, $debts, "📋 Active Debts");
    }

    public function showDueSoon(TelegramUser $user): void
    {
        $debts = $user->debts()->dueSoon(7)->orderBy('due_date')->get();

        if ($debts->isEmpty()) {
            $this->bot->sendMessage(
                $user->telegram_id,
                "⏰ <b>Due Soon</b>\n\n" .
                "No debts due in the next 7 days."
            );
            return;
        }

        $this->displayDebtList($user, $debts, "⏰ Debts Due Soon");
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
                "✅ <b>Paid Debts</b>\n\n" .
                "No paid debts to show."
            );
            return;
        }

        $this->displayDebtList($user, $debts, "✅ Paid Debts");
    }

    public function showDebtSummary(TelegramUser $user): void
    {
        $givenActive = $user->debts()->given()->active()->get();
        $receivedActive = $user->debts()->received()->active()->get();

        $givenTotal = $givenActive->sum('amount') - $givenActive->sum('amount_paid');
        $receivedTotal = $receivedActive->sum('amount') - $receivedActive->sum('amount_paid');

        $overdueCount = $user->debts()->overdue()->count();

        $message = "📊 <b>Debt Summary</b>\n\n";

        $message .= "📤 <b>Money I Gave (Owed to me):</b>\n";
        $message .= "   Total: \$" . number_format($givenTotal, 2) . "\n";
        $message .= "   Active debts: {$givenActive->count()}\n\n";

        $message .= "📥 <b>Money I Owe:</b>\n";
        $message .= "   Total: \$" . number_format($receivedTotal, 2) . "\n";
        $message .= "   Active debts: {$receivedActive->count()}\n\n";

        $netPosition = $givenTotal - $receivedTotal;
        $netEmoji = $netPosition >= 0 ? '💚' : '❤️';
        $netText = $netPosition >= 0 
            ? "People owe you \$" . number_format($netPosition, 2)
            : "You owe \$" . number_format(abs($netPosition), 2);

        $message .= "{$netEmoji} <b>Net Position:</b> {$netText}\n\n";

        if ($overdueCount > 0) {
            $message .= "⚠️ <b>Overdue debts: {$overdueCount}</b>";
        } else {
            $message .= "✅ No overdue debts";
        }

        // Top debtors
        if ($givenActive->isNotEmpty()) {
            $byPerson = $givenActive->groupBy('person_name')
                ->map(fn($debts) => $debts->sum('amount') - $debts->sum('amount_paid'))
                ->sortDesc()
                ->take(3);

            $message .= "\n\n<b>Top People Who Owe You:</b>\n";
            foreach ($byPerson as $person => $amount) {
                $message .= "   👤 {$person}: \$" . number_format($amount, 2) . "\n";
            }
        }

        $this->bot->sendMessage($user->telegram_id, $message);
    }

    public function markDebtPaid(TelegramUser $user, string $debtId, ?int $messageId): void
    {
        $debt = $user->debts()->find($debtId);
        
        if (!$debt) {
            $this->bot->sendMessage($user->telegram_id, "❌ Debt not found.");
            return;
        }

        $debt->markAsPaid();

        $emoji = $debt->type === 'given' ? '📤' : '📥';
        $message = "✅ <b>Debt Marked as Paid!</b>\n\n" .
            "{$emoji} {$debt->person_name}\n" .
            "💰 {$debt->getFormattedAmount()}\n" .
            "📅 Paid: " . now()->format('M j, Y');

        if ($messageId) {
            $this->bot->editMessage($user->telegram_id, $messageId, $message);
        } else {
            $this->bot->sendMessage($user->telegram_id, $message);
        }

        // Check for debt-free achievement
        $activeDebtsCount = $user->debts()->active()->count();
        if ($activeDebtsCount === 0) {
            \App\Models\UserAchievement::award($user, 'debt_free');
        }
    }

    public function startPartialPayment(TelegramUser $user, string $debtId, ?int $messageId): void
    {
        $debt = $user->debts()->find($debtId);
        
        if (!$debt) {
            $this->bot->sendMessage($user->telegram_id, "❌ Debt not found.");
            return;
        }

        $user->setState('partial_payment', ['debt_id' => $debt->id]);

        $message = "💳 <b>Partial Payment</b>\n\n" .
            "Remaining: {$debt->getFormattedRemainingAmount()}\n\n" .
            "Enter the payment amount:";

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
            $this->bot->sendMessage($user->telegram_id, "❌ Debt not found.");
            return;
        }

        $message = $this->formatDebtDetails($debt);

        $keyboard = [];
        
        if ($debt->status !== 'paid') {
            $keyboard[] = [
                ['text' => '✅ Mark as Paid', 'callback_data' => "debt_pay:{$debt->id}"],
                ['text' => '💳 Partial Payment', 'callback_data' => "debt_partial:{$debt->id}"],
            ];
        }

        $keyboard[] = [
            ['text' => '🗑️ Delete', 'callback_data' => "debt_delete:{$debt->id}"],
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
                ['text' => '✅ Yes, delete', 'callback_data' => "debt_confirm_delete:{$debtId}"],
                ['text' => '❌ Cancel', 'callback_data' => "debt_view:{$debtId}"],
            ],
        ];

        $message = "🗑️ <b>Delete Debt?</b>\n\n" .
            "👤 {$debt->person_name}\n" .
            "💰 {$debt->getFormattedAmount()}\n\n" .
            "Are you sure?";

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
            $this->bot->editMessage($user->telegram_id, $messageId, "❌ Cancelled.");
            return;
        }

        // Handle confirm delete
        if (str_starts_with($value, 'delete:')) {
            $debtId = str_replace('delete:', '', $value);
            $debt = $user->debts()->find($debtId);
            
            if ($debt) {
                $debt->delete();
                $this->bot->editMessage($user->telegram_id, $messageId, "🗑️ Debt deleted.");
            }
            return;
        }

        // Handle create confirmation
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
        $typeText = $debt->type === 'given' ? 'Money Given' : 'Money Received';

        $message = "✅ <b>Debt Added!</b>\n\n" .
            "{$emoji} {$typeText}\n" .
            "👤 Person: {$debt->person_name}\n" .
            "💰 Amount: {$debt->getFormattedAmount()}\n";

        if ($debt->due_date) {
            $message .= "📅 Due: {$debt->due_date->format('M j, Y')}\n";
        }

        if ($debt->note) {
            $message .= "📝 Note: {$debt->note}";
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

        $this->displayDebtList($user, $debts->items(), "📋 Debts (Page {$page})", $messageId, [
            'current_page' => $page,
            'last_page' => $debts->lastPage(),
        ]);
    }

    protected function displayDebtList(TelegramUser $user, $debts, string $title, ?int $messageId = null, array $pagination = []): void
    {
        if (empty($debts) || (is_countable($debts) && count($debts) === 0)) {
            $this->bot->sendMessage($user->telegram_id, "{$title}\n\nNo debts found.");
            return;
        }

        $message = "<b>{$title}</b>\n\n";

        foreach ($debts as $debt) {
            $emoji = $debt->type === 'given' ? '📤' : '📥';
            $statusEmoji = $debt->getStatusEmoji();
            
            $message .= "{$emoji} {$statusEmoji} <b>{$debt->person_name}</b>\n";
            $message .= "   💰 {$debt->getFormattedAmount()}";
            
            if ($debt->amount_paid > 0) {
                $message .= " (paid: \${$debt->amount_paid})";
            }
            $message .= "\n";
            
            if ($debt->due_date) {
                $daysUntil = $debt->getDaysUntilDue();
                if ($daysUntil < 0) {
                    $message .= "   ⚠️ Overdue by " . abs($daysUntil) . " days\n";
                } elseif ($daysUntil === 0) {
                    $message .= "   ⏰ Due today!\n";
                } elseif ($daysUntil <= 3) {
                    $message .= "   ⏰ Due in {$daysUntil} days\n";
                } else {
                    $message .= "   📅 Due: {$debt->due_date->format('M j')}\n";
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

        // Pagination
        if (!empty($pagination)) {
            $navRow = [];
            if ($pagination['current_page'] > 1) {
                $navRow[] = ['text' => '◀️ Prev', 'callback_data' => 'page:debts_' . ($pagination['current_page'] - 1)];
            }
            if ($pagination['current_page'] < $pagination['last_page']) {
                $navRow[] = ['text' => 'Next ▶️', 'callback_data' => 'page:debts_' . ($pagination['current_page'] + 1)];
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
        $typeText = $debt->type === 'given' ? 'Money I Gave' : 'Money I Owe';

        $message = "{$debt->getStatusEmoji()} <b>{$typeText}</b>\n\n";
        $message .= "👤 Person: {$debt->person_name}\n";
        
        if ($debt->person_contact) {
            $message .= "📱 Contact: {$debt->person_contact}\n";
        }
        
        $message .= "💰 Amount: {$debt->getFormattedAmount()}\n";
        
        if ($debt->amount_paid > 0) {
            $message .= "✅ Paid: \${$debt->amount_paid}\n";
            $message .= "📊 Remaining: {$debt->getFormattedRemainingAmount()}\n";
        }
        
        $message .= "📅 Created: {$debt->date->format('M j, Y')}\n";
        
        if ($debt->due_date) {
            $daysUntil = $debt->getDaysUntilDue();
            $message .= "⏰ Due: {$debt->due_date->format('M j, Y')}";
            
            if ($debt->status !== 'paid') {
                if ($daysUntil < 0) {
                    $message .= " <b>(Overdue!)</b>";
                } elseif ($daysUntil === 0) {
                    $message .= " <b>(Today!)</b>";
                } elseif ($daysUntil <= 3) {
                    $message .= " ({$daysUntil} days left)";
                }
            }
            $message .= "\n";
        }
        
        if ($debt->note) {
            $message .= "📝 Note: {$debt->note}\n";
        }
        
        if ($debt->paid_at) {
            $message .= "\n✅ Paid on: {$debt->paid_at->format('M j, Y')}";
        }

        return $message;
    }
}

