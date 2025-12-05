<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_user_id',
        'role',
        'message',
        'context_data',
        'tokens_used',
    ];

    protected $casts = [
        'context_data' => 'array',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function getRoleEmoji(): string
    {
        return match($this->role) {
            'user' => '👤',
            'assistant' => '🤖',
            'system' => '⚙️',
            default => '💬',
        };
    }
}

