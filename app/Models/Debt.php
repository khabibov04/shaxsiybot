<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Debt extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'telegram_user_id',
        'type',
        'person_name',
        'person_contact',
        'amount',
        'amount_paid',
        'currency',
        'note',
        'date',
        'due_date',
        'status',
        'paid_at',
        'reminder_enabled',
        'reminder_days_before',
        'last_reminder_sent',
        'reminder_count',
    ];

    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'last_reminder_sent' => 'datetime',
        'amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'reminder_enabled' => 'boolean',
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
    public function scopeGiven($query)
    {
        return $query->where('type', 'given');
    }

    public function scopeReceived($query)
    {
        return $query->where('type', 'received');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'partial', 'overdue']);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue')
            ->orWhere(function ($q) {
                $q->whereIn('status', ['active', 'partial'])
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', today());
            });
    }

    public function scopeDueSoon($query, int $days = 3)
    {
        return $query->whereIn('status', ['active', 'partial'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [today(), today()->addDays($days)]);
    }

    // Helper methods
    public function getTypeEmoji(): string
    {
        return $this->type === 'given' ? '📤' : '📥';
    }

    public function getStatusEmoji(): string
    {
        return match($this->status) {
            'active' => '🔵',
            'partial' => '🟡',
            'paid' => '✅',
            'overdue' => '🔴',
            'cancelled' => '❌',
            default => '⚪',
        };
    }

    public function getRemainingAmount(): float
    {
        return $this->amount - $this->amount_paid;
    }

    public function getFormattedAmount(): string
    {
        $symbol = match($this->currency) {
            'USD' => '$',
            'EUR' => '€',
            'RUB' => '₽',
            'UZS' => 'so\'m',
            default => $this->currency,
        };
        
        return $symbol . number_format($this->amount, 2);
    }

    public function getFormattedRemainingAmount(): string
    {
        $symbol = match($this->currency) {
            'USD' => '$',
            'EUR' => '€',
            'RUB' => '₽',
            'UZS' => 'so\'m',
            default => $this->currency,
        };
        
        return $symbol . number_format($this->getRemainingAmount(), 2);
    }

    public function addPayment(float $amount): void
    {
        $this->amount_paid += $amount;
        
        if ($this->amount_paid >= $this->amount) {
            $this->status = 'paid';
            $this->paid_at = now();
        } else {
            $this->status = 'partial';
        }
        
        $this->save();
    }

    public function markAsPaid(): void
    {
        $this->amount_paid = $this->amount;
        $this->status = 'paid';
        $this->paid_at = now();
        $this->save();
    }

    public function isOverdue(): bool
    {
        if (in_array($this->status, ['paid', 'cancelled'])) {
            return false;
        }
        
        return $this->due_date && $this->due_date->isPast();
    }

    public function updateOverdueStatus(): void
    {
        if ($this->isOverdue() && $this->status !== 'overdue') {
            $this->status = 'overdue';
            $this->save();
        }
    }

    public function getDaysUntilDue(): ?int
    {
        if (!$this->due_date) {
            return null;
        }
        
        return today()->diffInDays($this->due_date, false);
    }

    public function needsReminder(): bool
    {
        if (!$this->reminder_enabled || !$this->due_date) {
            return false;
        }
        
        if (in_array($this->status, ['paid', 'cancelled'])) {
            return false;
        }
        
        $daysUntilDue = $this->getDaysUntilDue();
        
        // Remind before due date
        if ($daysUntilDue !== null && $daysUntilDue <= $this->reminder_days_before && $daysUntilDue >= 0) {
            if (!$this->last_reminder_sent || $this->last_reminder_sent->diffInHours(now()) >= 24) {
                return true;
            }
        }
        
        // Remind if overdue
        if ($daysUntilDue !== null && $daysUntilDue < 0) {
            if (!$this->last_reminder_sent || $this->last_reminder_sent->diffInDays(now()) >= 1) {
                return true;
            }
        }
        
        return false;
    }

    public function markReminderSent(): void
    {
        $this->last_reminder_sent = now();
        $this->reminder_count++;
        $this->save();
    }
}


