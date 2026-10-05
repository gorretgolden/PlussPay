<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class ExchangeRate extends Model
{
    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'is_active' => 'boolean',
    ];

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        if ($request->filled('currency')) {
            $currency = $request->currency;
            $query->where(function ($q) use ($currency) {
                $q->where('from_currency', $currency)->orWhere('to_currency', $currency);
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        return $query;
    }

    public static function usdToUgx(): ?float
    {
        $direct = static::getActiveRate('USD', 'UGX');
        if ($direct && (float) $direct->rate > 0) {
            return (float) $direct->rate;
        }

        $inverse = static::getActiveRate('UGX', 'USD');
        if ($inverse && (float) $inverse->rate > 0) {
            return 1 / (float) $inverse->rate;
        }

        return null;
    }

    public static function getActiveRate(string $from, string $to): ?self
    {
        return static::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('is_active', true)
            ->latest()
            ->first();
    }

    public static function convert(decimal|int|float $amount, string $from, string $to): ?string
    {
        $rate = static::getActiveRate($from, $to);
        if (!$rate) {
            return null;
        }
        return bcmul((string) $amount, (string) $rate->rate, 2);
    }
}
