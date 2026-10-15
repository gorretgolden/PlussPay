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
        'rate',
        'margin_ugx',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'exchange_date' => 'date',
        'in_amount' => 'decimal:2',
        'out_amount' => 'decimal:2',
        'rate' => 'decimal:6',
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
        $in = (float) $this->in_amount;
        $out = (float) $this->out_amount;

        if ($in <= 0 || $out <= 0) {
            return null;
        }

        [$figure, $label] = $out >= $in
            ? [$out / $in, $this->out_currency.' per 1 '.$this->in_currency]
            : [$in / $out, $this->in_currency.' per 1 '.$this->out_currency];

        return rtrim(rtrim(number_format($figure, 6, '.', ''), '0'), '.').' '.$label;
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
