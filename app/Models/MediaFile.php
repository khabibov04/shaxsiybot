<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MediaFile extends Model
{
    use HasFactory;

    protected $fillable = [
        'telegram_user_id',
        'attachable_type',
        'attachable_id',
        'type',
        'telegram_file_id',
        'file_name',
        'mime_type',
        'file_size',
        'local_path',
        'transcription',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getTypeEmoji(): string
    {
        return match($this->type) {
            'image' => '🖼️',
            'document' => '📄',
            'audio' => '🎵',
            'voice' => '🎤',
            'video' => '🎬',
            default => '📎',
        };
    }

    public function getFormattedSize(): string
    {
        if (!$this->file_size) {
            return 'Unknown';
        }
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->file_size;
        $unit = 0;
        
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        
        return round($size, 2) . ' ' . $units[$unit];
    }
}

