<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Reminder extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_user_id',
        'remindable_type',
        'remindable_id',
        'title',
        'message',
        'remind_at',
        'type',
        'recurrence_type',
        'priority',
        'is_sent',
        'sent_at',
        'is_voice',
        'voice_file_id',
    ];

    protected $casts = [
        'remind_at' => 'datetime',
        'sent_at' => 'datetime',
        'is_sent' => 'boolean',
        'is_voice' => 'boolean',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function remindable(): MorphTo
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('is_sent', false)
            ->where('remind_at', '<=', now());
    }

    public function scopeUpcoming($query, int $minutes = 60)
    {
        return $query->where('is_sent', false)
            ->whereBetween('remind_at', [now(), now()->addMinutes($minutes)]);
    }

    // Helper methods
    public function getPriorityEmoji(): string
    {
        return match($this->priority) {
            'high' => '🔴',
            'medium' => '🟡',
            'low' => '🟢',
            default => '⚪',
        };
    }

    public function markAsSent(): void
    {
        $this->is_sent = true;
        $this->sent_at = now();
        $this->save();
        
        // Create next reminder if recurring
        if ($this->type === 'recurring' && $this->recurrence_type) {
            $this->createNextRecurrence();
        }
    }

    public function createNextRecurrence(): ?Reminder
    {
        if ($this->type !== 'recurring' || !$this->recurrence_type) {
            return null;
        }
        
        $nextDate = match($this->recurrence_type) {
            'daily' => $this->remind_at->addDay(),
            'weekly' => $this->remind_at->addWeek(),
            'monthly' => $this->remind_at->addMonth(),
            default => null,
        };
        
        if (!$nextDate) {
            return null;
        }
        
        return self::create([
            'telegram_user_id' => $this->telegram_user_id,
            'remindable_type' => $this->remindable_type,
            'remindable_id' => $this->remindable_id,
            'title' => $this->title,
            'message' => $this->message,
            'remind_at' => $nextDate,
            'type' => 'recurring',
            'recurrence_type' => $this->recurrence_type,
            'priority' => $this->priority,
            'is_voice' => $this->is_voice,
            'voice_file_id' => $this->voice_file_id,
        ]);
    }
}


