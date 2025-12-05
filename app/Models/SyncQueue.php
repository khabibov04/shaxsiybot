<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncQueue extends Model
{
    use HasFactory;

    protected $table = 'sync_queue';

    protected $fillable = [
        'telegram_user_id',
        'action',
        'model_type',
        'model_id',
        'data',
        'is_synced',
        'synced_at',
        'retry_count',
        'error_message',
    ];

    protected $casts = [
        'data' => 'array',
        'is_synced' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function scopePending($query)
    {
        return $query->where('is_synced', false)
            ->where('retry_count', '<', 5);
    }

    public function markAsSynced(): void
    {
        $this->is_synced = true;
        $this->synced_at = now();
        $this->save();
    }

    public function incrementRetry(string $error = null): void
    {
        $this->retry_count++;
        $this->error_message = $error;
        $this->save();
    }
}

