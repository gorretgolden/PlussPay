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
        'buy_rate',
        'sell_rate',
        'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'buy_rate' => 'decimal:6',
        'sell_rate' => 'decimal:6',
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

    /**
     * The two prices for a pair: what one unit costs us to obtain (buy) and what we
     * hand over in exchange for it (sell). A pair saved before the split only has one
     * figure, so both sides report that figure and the margin comes out as zero rather
     * than breaking the conversion.
     *
     * @return array{buy: float, sell: float}|null
     */
    public static function pair(string $from, string $to): ?array
    {
        $row = static::getActiveRate($from, $to);
        if (! $row) {
            return null;
        }

        $sell = (float) ($row->sell_rate ?: $row->rate);
        $buy = (float) ($row->buy_rate ?: $sell);

        return ['buy' => $buy, 'sell' => $sell];
    }

    /**
     * @return array{buy: float, sell: float}|null
     */
    public static function usdUgxPair(): ?array
    {
        $direct = static::pair('USD', 'UGX');
        if ($direct) {
            return $direct;
        }

        // Only the inverse quote is stored, so there is no spread to recover from it.
        $inverse = static::getActiveRate('UGX', 'USD');
        if ($inverse && (float) $inverse->rate > 0) {
            $rate = 1 / (float) $inverse->rate;

            return ['buy' => $rate, 'sell' => $rate];
        }

        return null;
    }

    public static function setPair(string $from, string $to, float $rate, ?float $buy = null, ?float $sell = null): self
    {
        static::where('from_currency', $from)->where('to_currency', $to)->update(['is_active' => false]);

        return static::create([
            'from_currency' => strtoupper($from),
            'to_currency' => strtoupper($to),
            'rate' => $rate,
            'buy_rate' => $buy,
            'sell_rate' => $sell ?? $rate,
            'is_active' => true,
        ]);
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
