<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class ExchangeRate extends Model
{
    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'buy_rate',
        'sell_rate',
        'market_rate',
        'effective_from',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'buy_rate' => 'decimal:6',
        'sell_rate' => 'decimal:6',
        'market_rate' => 'decimal:6',
        'effective_from' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $rate): void {
            if (! $rate->isDirty('updated_by') && ($editor = auth()->id())) {
                $rate->updated_by = $editor;
            }
        });

        // A price written today starts today, so the books can always say when a figure took effect
        // even if the row was entered late or corrected later.
        static::creating(function (self $rate): void {
            $rate->effective_from ??= now()->toDateString();
        });

        // When the entry date itself moves, an import or a back-dated fix, the price starts with it.
        // Leaving the old day on the effective date would make the row unreadable for that history.
        static::updating(function (self $rate): void {
            if ($rate->isDirty('created_at') && $rate->effective_from !== null) {
                $rate->effective_from = $rate->created_at->toDateString();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Retire the live rate for a pair. Query builder updates skip model events, so the person
     * responsible is stamped by hand here rather than by the updating hook.
     */
    public static function retirePair(string $from, string $to): void
    {
        static::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('is_active', true)
            ->update(['is_active' => false] + (auth()->id() ? ['updated_by' => auth()->id()] : []));
    }

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        if ($request->filled('search')) {
            $term = trim($request->search);
            $currency = '%'.addcslashes(mb_strtoupper($term), '%_\\').'%';
            $person = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($q) use ($currency, $person) {
                $q->where('from_currency', 'like', $currency)
                    ->orWhere('to_currency', 'like', $currency)
                    ->orWhereHas('creator', fn (Builder $c) => $c->where('name', 'like', $person));
            });
        }

        if ($request->filled('currency')) {
            $currency = $request->currency;
            $query->where(function ($q) use ($currency) {
                $q->where('from_currency', $currency)->orWhere('to_currency', $currency);
            });
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
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
     * The row that stood for a pair at a moment in the past. Saving a new rate retires the
     * old one but keeps its timestamp, so the live quote on any date is simply the newest row
     * whose start date had arrived by then. A row with no recorded start date is read at the
     * moment it was typed. Null when the pair had no rate yet.
     */
    public static function rateLiveAt(string $from, string $to, \Carbon\CarbonInterface $at): ?self
    {
        $date = $at->toDateString();

        return static::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where(fn (Builder $q) => $q->whereDate('effective_from', '<=', $date)
                ->orWhere(fn (Builder $inner) => $inner->whereNull('effective_from')->whereDate('created_at', '<=', $date)))
            ->orderByRaw('COALESCE(effective_from, created_at) desc, id desc')
            ->first();
    }

    /**
     * The street price saved against the live row of a pair, if anyone recorded one. This is the
     * figure a slip quotes as the market on the day, and it is deliberately separate from the two
     * desk prices: the buying and selling rates are what we deal at, the market rate is what the
     * money was worth outside.
     */
    public static function marketPrice(string $from, string $to): ?string
    {
        $row = static::getActiveRate($from, $to);

        return $row?->market_rate === null ? null : (string) $row->market_rate;
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

    public static function setPair(string $from, string $to, float $rate, ?float $buy = null, ?float $sell = null, ?float $market = null): self
    {
        // Read before retiring: a blank street price keeps whatever the live row carried, so a
        // market figure recorded once does not have to be retyped every time the desk prices move.
        $market ??= static::marketPrice($from, $to);

        static::retirePair($from, $to);

        return static::create([
            'from_currency' => strtoupper($from),
            'to_currency' => strtoupper($to),
            'rate' => $rate,
            'buy_rate' => $buy,
            'sell_rate' => $sell ?? $rate,
            'market_rate' => $market,
            'is_active' => true,
            'created_by' => auth()->id(),
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
