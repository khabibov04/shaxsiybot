<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'telegram_user_id',
        'title',
        'description',
        'priority',
        'category',
        'tags',
        'period_type',
        'date',
        'start_date',
        'end_date',
        'time',
        'reminder_time',
        'is_recurring',
        'recurrence_type',
        'recurrence_days',
        'recurrence_interval',
        'recurrence_end_date',
        'status',
        'completed_at',
        'rating',
        'completion_note',
        'points_earned',
        'is_morning_plan',
        'evening_reviewed',
        'optimal_time_suggestion',
        'estimated_duration_minutes',
        'difficulty_level',
    ];

    protected $casts = [
        'tags' => 'array',
        'recurrence_days' => 'array',
        'date' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'recurrence_end_date' => 'date',
        'completed_at' => 'datetime',
        'is_recurring' => 'boolean',
        'is_morning_plan' => 'boolean',
        'evening_reviewed' => 'boolean',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function reminders(): MorphMany
    {
        return $this->morphMany(Reminder::class, 'remindable');
    }

    public function mediaFiles(): MorphMany
    {
        return $this->morphMany(MediaFile::class, 'attachable');
    }

    // Scopes
    public function scopeForToday($query)
    {
        return $query->whereDate('date', today())
            ->orWhere(function ($q) {
                $q->where('is_recurring', true)
                    ->where(function ($q2) {
                        $q2->whereNull('recurrence_end_date')
                            ->orWhere('recurrence_end_date', '>=', today());
                    });
            });
    }

    public function scopeForWeek($query)
    {
        return $query->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeForMonth($query)
    {
        return $query->whereMonth('date', now()->month)
            ->whereYear('date', now()->year);
    }

    public function scopeForYear($query)
    {
        return $query->whereYear('date', now()->year);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeHighPriority($query)
    {
        return $query->where('priority', 'high');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // Helper methods
    public function markAsCompleted(?int $rating = null, ?string $note = null): void
    {
        $this->status = 'completed';
        $this->completed_at = now();
        
        if ($rating !== null) {
            $this->rating = $rating;
        }
        
        if ($note !== null) {
            $this->completion_note = $note;
        }
        
        // Calculate points
        $points = config('telegram.gamification.points_per_task', 10);
        
        if ($this->priority === 'high') {
            $points += config('telegram.gamification.points_high_priority', 20);
        }
        
        // Bonus for completing on time
        if ($this->date && $this->date->isToday()) {
            $points += config('telegram.gamification.points_on_time', 5);
        }
        
        // Points for rating
        if ($this->rating) {
            $points += $this->rating * config('telegram.gamification.points_per_rating_star', 2);
        }
        
        $this->points_earned = $points;
        $this->save();
        
        // Update user stats
        $user = $this->telegramUser;
        $user->tasks_completed++;
        $user->addPoints($points);
        $user->updateStreak();
    }

    public function getPriorityEmoji(): string
    {
        return match($this->priority) {
            'high' => '🔴',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };
    }

    public function getCategoryEmoji(): string
    {
        $categories = config('telegram.task_categories');
        return $categories[$this->category] ?? '📋 Other';
    }

    public function getStatusEmoji(): string
    {
        return match($this->status) {
            'pending' => '⏳',
            'in_progress' => '🔄',
            'completed' => '✅',
            'cancelled' => '❌',
            default => '⚪',
        };
    }

    public function getFormattedTags(): string
    {
        if (empty($this->tags)) {
            return '';
        }
        return implode(' ', $this->tags);
    }

    public function isOverdue(): bool
    {
        if ($this->status === 'completed' || $this->status === 'cancelled') {
            return false;
        }
        
        if ($this->date && $this->date->isPast() && !$this->date->isToday()) {
            return true;
        }
        
        return false;
    }

    public function createNextRecurrence(): ?Task
    {
        if (!$this->is_recurring || !$this->recurrence_type) {
            return null;
        }
        
        $nextDate = match($this->recurrence_type) {
            'daily' => $this->date->addDays($this->recurrence_interval),
            'weekly' => $this->date->addWeeks($this->recurrence_interval),
            'monthly' => $this->date->addMonths($this->recurrence_interval),
            'yearly' => $this->date->addYears($this->recurrence_interval),
            default => null,
        };
        
        if (!$nextDate) {
            return null;
        }
        
        if ($this->recurrence_end_date && $nextDate->gt($this->recurrence_end_date)) {
            return null;
        }
        
        return self::create([
            'telegram_user_id' => $this->telegram_user_id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'category' => $this->category,
            'tags' => $this->tags,
            'period_type' => $this->period_type,
            'date' => $nextDate,
            'time' => $this->time,
            'reminder_time' => $this->reminder_time,
            'is_recurring' => true,
            'recurrence_type' => $this->recurrence_type,
            'recurrence_days' => $this->recurrence_days,
            'recurrence_interval' => $this->recurrence_interval,
            'recurrence_end_date' => $this->recurrence_end_date,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'difficulty_level' => $this->difficulty_level,
        ]);
    }
}

