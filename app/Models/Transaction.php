<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'telegram_user_id',
        'type',
        'amount',
        'currency',
        'amount_usd',
        'category',
        'subcategory',
        'note',
        'date',
        'time',
        'auto_categorized',
        'category_confidence',
        'is_recurring',
        'recurrence_type',
        'recurrence_interval',
        'recurrence_end_date',
        'location',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'date' => 'date',
        'recurrence_end_date' => 'date',
        'amount' => 'decimal:2',
        'amount_usd' => 'decimal:2',
        'auto_categorized' => 'boolean',
        'is_recurring' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    public function telegramUser(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class);
    }

    public function mediaFiles(): MorphMany
    {
        return $this->morphMany(MediaFile::class, 'attachable');
    }

    // Scopes
    public function scopeIncome($query)
    {
        return $query->where('type', 'income');
    }

    public function scopeExpense($query)
    {
        return $query->where('type', 'expense');
    }

    public function scopeForToday($query)
    {
        return $query->whereDate('date', today());
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

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    // Helper methods
    public function getTypeEmoji(): string
    {
        return $this->type === 'income' ? '💰' : '💸';
    }

    public function getCategoryEmoji(): string
    {
        $categories = $this->type === 'income' 
            ? config('telegram.income_categories')
            : config('telegram.expense_categories');
            
        return $categories[$this->category] ?? '📋 Other';
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
        
        $sign = $this->type === 'income' ? '+' : '-';
        return "{$sign}{$symbol}" . number_format($this->amount, 2);
    }

    public static function autoCategorize(string $note): array
    {
        $note = mb_strtolower($note);
        
        $patterns = [
            'food' => ['food', 'lunch', 'dinner', 'breakfast', 'restaurant', 'cafe', 'coffee', 'grocery', 'supermarket', 'pizza', 'burger'],
            'transport' => ['taxi', 'uber', 'bus', 'metro', 'gas', 'fuel', 'petrol', 'parking', 'car', 'train', 'flight'],
            'entertainment' => ['movie', 'cinema', 'concert', 'game', 'netflix', 'spotify', 'subscription', 'party'],
            'health' => ['pharmacy', 'medicine', 'doctor', 'hospital', 'gym', 'fitness', 'health'],
            'utilities' => ['electricity', 'water', 'internet', 'phone', 'rent', 'bill'],
            'education' => ['book', 'course', 'school', 'university', 'training', 'lesson'],
            'equipment' => ['laptop', 'phone', 'computer', 'device', 'electronics', 'tech'],
            'work' => ['office', 'supplies', 'business', 'work'],
        ];
        
        foreach ($patterns as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($note, $keyword)) {
                    return [
                        'category' => $category,
                        'confidence' => 0.8,
                    ];
                }
            }
        }
        
        return [
            'category' => 'other',
            'confidence' => 0.5,
        ];
    }

    public function createNextRecurrence(): ?Transaction
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
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'note' => $this->note,
            'date' => $nextDate,
            'is_recurring' => true,
            'recurrence_type' => $this->recurrence_type,
            'recurrence_interval' => $this->recurrence_interval,
            'recurrence_end_date' => $this->recurrence_end_date,
        ]);
    }
}

