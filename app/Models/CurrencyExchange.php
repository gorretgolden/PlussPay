<?php

namespace App\Models;

use App\Services\AccountLedger;
use App\Support\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CurrencyExchange extends Model
{
    use Auditable;

    public const ACTIVE = 'active';
    public const VOID = 'void';

    /** How the money physically moved across the counter. */
    public const PAYMENT_METHODS = ['Cash', 'Mobile money', 'Bank transfer', 'Cheque'];

    protected $fillable = [
        'reference',
        'exchange_date',
        'customer_id',
        'person_name',
        'in_currency',
        'in_amount',
        'in_account_id',
        'out_currency',
        'out_amount',
        'out_account_id',
        'fee',
        'payment_method',
        'rate',
        'market_rate',
        'rate_reason',
        'margin_ugx',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'exchange_date' => 'date',
        'in_amount' => 'decimal:2',
        'out_amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'rate' => 'decimal:6',
        'market_rate' => 'decimal:6',
        'margin_ugx' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    protected function personName(): Attribute
    {
        return Attribute::set(fn (?string $value) => $value === null
            ? null
            : mb_convert_case(trim($value), MB_CASE_TITLE, 'UTF-8'));
    }

    public function inAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'in_account_id');
    }

    public function outAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'out_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The cash-book lines this deal wrote, which is what answers the question of which account
     * went up and which came back down.
     */
    public function legs(): Collection
    {
        return AccountEntry::with('account')
            ->where('reference', $this->reference)
            ->orderBy('direction')
            ->orderBy('account_id')
            ->get();
    }

    public function isVoid(): bool
    {
        return $this->status === self::VOID;
    }

    /**
     * The price the deal was done at, always stated as however many units of the bigger side one
     * unit of the other bought. A shilling-to-dollar counter deal is 3,760 shillings to the dollar,
     * not 0.000265 dollars to the shilling, and both figures are read back off the two amounts
     * rather than the stored ratio so a rounded rate column can never change what the page claims.
     */
    public function quotedRate(): ?string
    {
        // Only what the commission left behind is priced, so the slip quotes the price the customer
        // actually got rather than a figure dragged down by what the desk kept.
        $in = (float) $this->in_amount - (float) ($this->fee ?? 0);
        $out = (float) $this->out_amount;

        if ($in <= 0 || $out <= 0) {
            return null;
        }

        [$figure, $label] = $out >= $in
            ? [$out / $in, $this->out_currency.' per 1 '.$this->in_currency]
            : [$in / $out, $this->in_currency.' per 1 '.$this->out_currency];

        return rtrim(rtrim(number_format($figure, 6, '.', ''), '0'), '.').' '.$label;
    }

    /**
     * The street price saved on the deal on the day it was done, said the same way round as the
     * deal's own price. It is read off the stored figure rather than the current card on purpose:
     * a slip printed later must not move when someone updates the rates.
     */
    public function quotedMarketRate(): ?string
    {
        $in = (float) $this->in_amount - (float) ($this->fee ?? 0);

        if ($this->market_rate === null || $in <= 0 || (float) $this->out_amount <= 0) {
            return null;
        }

        $label = (float) $this->out_amount >= $in
            ? $this->out_currency.' per 1 '.$this->in_currency
            : $this->in_currency.' per 1 '.$this->out_currency;

        return rtrim(rtrim(number_format((float) $this->market_rate, 6, '.', ''), '0'), '.').' '.$label;
    }

    /**
     * What the commission left behind after being taken out of what the customer handed over, which
     * is the part of the money the deal was actually priced on.
     */
    public function pricedAmount(): string
    {
        $fee = $this->fee === null ? '0.00' : (string) $this->fee;

        return bccomp($fee, (string) $this->in_amount, 2) >= 0
            ? (string) $this->in_amount
            : bcsub((string) $this->in_amount, $fee, 2);
    }

    /**
     * Which desk the deal was done at, read off the account that took the money in.
     */
    public function deskLabel(): ?string
    {
        $country = $this->inAccount?->country;

        return $country === null ? null : (User::COUNTRIES[$country] ?? $country);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('view-all-exchanges') ? $query : $query->where('created_by', $user->id);
    }

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        if ($request->filled('search')) {
            $term = '%'.addcslashes($request->search, '%_\\').'%';
            $query->where(function ($q) use ($term) {
                $q->where('person_name', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('notes', 'like', $term);
            });
        }

        if ($request->filled('currency')) {
            $currency = strtoupper($request->currency);
            $query->where(fn ($q) => $q->where('in_currency', $currency)->orWhere('out_currency', $currency));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->customer_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('exchange_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('exchange_date', '<=', $request->date_to);
        }

        return $query;
    }

    /**
     * Save the deal and write both legs in one go. Two desks typing in the same second can read
     * the same sequence, so a reference collision is retried instead of throwing a 500.
     */
    public static function record(array $attributes, int $attempts = 5): self
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes) {
                    $exchange = static::create(array_merge(
                        $attributes,
                        ['reference' => static::generateReference(), 'status' => self::ACTIVE]
                    ));

                    AccountLedger::postCurrencyExchange($exchange);

                    return $exchange;
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt >= $attempts || ! str_contains($e->getMessage(), 'reference')) {
                    throw $e;
                }

                usleep(60000 * $attempt);
            }
        }
    }

    /**
     * The deal is undone by posting the opposite legs rather than deleting anything, so the cash
     * books still show both what happened and that it was called back.
     */
    public function void(?string $reason = null): void
    {
        if ($this->isVoid()) {
            return;
        }

        DB::transaction(function () use ($reason) {
            $this->update(['status' => self::VOID]);
            AccountLedger::reverseCurrencyExchange($this, $reason);
        });
    }

    public static function generateReference(): string
    {
        $date = now()->format('Ymd');

        $issued = static::where('reference', 'like', "EX-{$date}-%")->pluck('reference');

        $next = 0;
        foreach ($issued as $number) {
            if (preg_match('/-(\d+)$/', $number, $matches)) {
                $next = max($next, (int) $matches[1]);
            }
        }

        return sprintf('EX-%s-%04d', $date, $next + 1);
    }
}
