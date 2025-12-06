<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CurrencyRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
        'rate' => 'decimal:10',
    ];

    public static function getRate(string $from, string $to, ?string $date = null): ?float
    {
        $date = $date ?? today()->toDateString();
        
        $rate = self::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('date', '<=', $date)
            ->orderBy('date', 'desc')
            ->first();
            
        return $rate?->rate;
    }

    public static function convert(float $amount, string $from, string $to, ?string $date = null): ?float
    {
        if ($from === $to) {
            return $amount;
        }
        
        $rate = self::getRate($from, $to, $date);
        
        if (!$rate) {
            return null;
        }
        
        return $amount * $rate;
    }
}


