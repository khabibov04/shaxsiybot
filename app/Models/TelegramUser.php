<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_id',
        'username',
        'first_name',
        'last_name',
        'language_code',
        'timezone',
        'currency',
        'total_points',
        'current_badge',
        'tasks_completed',
        'streak_days',
        'last_activity_date',
        'morning_notifications',
        'evening_notifications',
        'debt_reminders',
        'budget_alerts',
        'morning_time',
        'evening_time',
        'daily_budget_limit',
        'weekly_budget_limit',
        'monthly_budget_limit',
        'current_state',
        'state_data',
        'is_premium',
        'is_blocked',
    ];

    protected $casts = [
        'state_data' => 'array',
        'morning_notifications' => 'boolean',
        'evening_notifications' => 'boolean',
        'debt_reminders' => 'boolean',
        'budget_alerts' => 'boolean',
        'is_premium' => 'boolean',
        'is_blocked' => 'boolean',
        'last_activity_date' => 'date',
        'daily_budget_limit' => 'decimal:2',
        'weekly_budget_limit' => 'decimal:2',
        'monthly_budget_limit' => 'decimal:2',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function mediaFiles(): HasMany
    {
        return $this->hasMany(MediaFile::class);
    }

    public function chatHistories(): HasMany
    {
        return $this->hasMany(ChatHistory::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    public function syncQueue(): HasMany
    {
        return $this->hasMany(SyncQueue::class);
    }

    // Helper methods
    public function getDisplayName(): string
    {
        if ($this->first_name) {
            return $this->last_name 
                ? "{$this->first_name} {$this->last_name}" 
                : $this->first_name;
        }
        return $this->username ?? "User {$this->telegram_id}";
    }

    public function getBadgeInfo(): array
    {
        $badges = config('telegram.gamification.badges');
        return $badges[$this->current_badge] ?? $badges['beginner'];
    }

    public function addPoints(int $points): void
    {
        $this->total_points += $points;
        $this->updateBadge();
        $this->save();
    }

    public function updateBadge(): void
    {
        $badges = config('telegram.gamification.badges');
        $newBadge = 'beginner';
        
        foreach ($badges as $key => $badge) {
            if ($this->total_points >= $badge['points']) {
                $newBadge = $key;
            }
        }
        
        $this->current_badge = $newBadge;
    }

    public function updateStreak(): void
    {
        $today = now()->toDateString();
        $lastActivity = $this->last_activity_date?->toDateString();
        
        if ($lastActivity === $today) {
            return;
        }
        
        if ($lastActivity === now()->subDay()->toDateString()) {
            $this->streak_days++;
        } else {
            $this->streak_days = 1;
        }
        
        $this->last_activity_date = $today;
        $this->save();
    }

    public function setState(string $state, array $data = []): void
    {
        $this->current_state = $state;
        $this->state_data = $data;
        $this->save();
    }

    public function clearState(): void
    {
        $this->current_state = null;
        $this->state_data = null;
        $this->save();
    }

    public function getTodayExpenses(): float
    {
        return $this->transactions()
            ->where('type', 'expense')
            ->whereDate('date', today())
            ->sum('amount');
    }

    public function getWeekExpenses(): float
    {
        return $this->transactions()
            ->where('type', 'expense')
            ->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('amount');
    }

    public function getMonthExpenses(): float
    {
        return $this->transactions()
            ->where('type', 'expense')
            ->whereMonth('date', now()->month)
            ->whereYear('date', now()->year)
            ->sum('amount');
    }

    public function getBalance(): float
    {
        $income = $this->transactions()->where('type', 'income')->sum('amount');
        $expense = $this->transactions()->where('type', 'expense')->sum('amount');
        return $income - $expense;
    }

    public function getActiveDebtsTotal(string $type = null): float
    {
        $query = $this->debts()->whereIn('status', ['active', 'partial', 'overdue']);
        
        if ($type) {
            $query->where('type', $type);
        }
        
        return $query->sum('amount') - $query->sum('amount_paid');
    }
}

