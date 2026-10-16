<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class ExchangeRate extends Model
{
    /**
     * The only two prices the desk works from, and the only two that may be in use at once. The
     * buying and selling figures on one row already price money travelling both ways, so a row for
     * the reverse direction either duplicates a live price or quietly replaces it with a rate nobody
     * set. Money is bought and sold against dollars here: shillings for dollars, dollars for yuan.
     */
    public const DESK_PAIRS = [
        ['USD', 'UGX'],
        ['USD', 'RMB'],
    ];

    protected $fillable = [
        'from_currency',
        'to_currency',
        'rate',
        'buy_rate',
        'sell_rate',
        'effective_from',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'buy_rate' => 'decimal:6',
        'sell_rate' => 'decimal:6',
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
        $row = static::getActiveRate('USD', 'UGX');

        return $row && (float) $row->rate > 0 ? (float) $row->rate : null;
    }

    public static function getActiveRate(string $from, string $to): ?self
    {
        return static::where('from_currency', $from)
            ->where('to_currency', $to)
            ->where('is_active', true)
            ->latest()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether a direction is one of the two prices the desk reads.
     */
    public static function isDeskPair(string $from, string $to): bool
    {
        foreach (static::DESK_PAIRS as [$start, $end]) {
            if ($start === strtoupper($from) && $end === strtoupper($to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The two desk pairs named the way the table prints them, for any message that has to say
     * which prices are allowed to be in use.
     */
    public static function deskPairsList(): string
    {
        return implode(' and ', array_map(
            fn (array $pair) => $pair[0].' → '.$pair[1],
            static::DESK_PAIRS,
        ));
    }

    /**
     * Every price in use that the rule says should not be: a direction the desk does not read, or a
     * second live row for a pair whose current price is already being read elsewhere. Nothing here
     * is deleted, and nothing is switched off by itself; these are the rows the person at the desk
     * has to set aside.
     *
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function liveRowsOutsideRule()
    {
        $outside = collect();

        foreach (static::where('is_active', true)->get()->groupBy(fn (self $r) => $r->from_currency.'-'.$r->to_currency) as $rows) {
            $first = $rows->first();

            if (! static::isDeskPair($first->from_currency, $first->to_currency)) {
                $outside = $outside->merge($rows);

                continue;
            }

            $read = static::getActiveRate($first->from_currency, $first->to_currency);
            $outside = $outside->merge($rows->reject(fn (self $r) => $read && $r->id === $read->id));
        }

        return $outside->sortBy([['from_currency', 'asc'], ['to_currency', 'asc'], ['id', 'desc']])->values();
    }

    /**
     * The desk pairs with no price in use. A booking cannot be worked out without both of them, so
     * an empty one is said out loud rather than left for the counter to discover.
     *
     * @return array<int, string>
     */
    public static function unpricedDeskPairs(): array
    {
        return array_values(array_filter(array_map(
            fn (array $pair) => static::getActiveRate($pair[0], $pair[1]) ? null : $pair[0].' → '.$pair[1],
            static::DESK_PAIRS,
        )));
    }

    /**
     * Bring this price into use. Whatever else is live for the same pair is set aside first, so a
     * pair can never hold two current prices at once.
     */
    public function putIntoUse(): self
    {
        static::retirePair($this->from_currency, $this->to_currency);
        $this->update(['is_active' => true]);

        return $this;
    }

    /**
     * Take this price out of use.
     */
    public function setAside(): self
    {
        $this->update(['is_active' => false]);

        return $this;
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
        return static::pair('USD', 'UGX');
    }

    public static function setPair(string $from, string $to, float $rate, ?float $buy = null, ?float $sell = null): self
    {
        static::retirePair($from, $to);

        return static::create([
            'from_currency' => strtoupper($from),
            'to_currency' => strtoupper($to),
            'rate' => $rate,
            'buy_rate' => $buy,
            'sell_rate' => $sell ?? $rate,
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
