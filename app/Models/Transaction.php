<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class Transaction extends Model
{
    use SoftDeletes;

    /**
     * How the customer handed this desk the money. Cash needs no proof because the desk counted
     * it; every other way arrives with a confirmation number the cashier writes down.
     */
    public const PAYMENT_METHODS = ['Cash', 'MTN Mobile Money', 'Airtel Money', 'Bank transfer', 'Other'];

    public const CASH_PAYMENT = 'Cash';

    protected $fillable = [
        'receipt_number',
        'sender_name',
        'sender_contact',
        'receiver_name',
        'receiver_contact',
        'currency',
        'amount',
        'exchange_rate',
        'rmb_buying_rate',
        'rate_buy_ugx',
        'rate_sell_ugx',
        'rmb_amount',
        'charge_rate',
        'charge_amount',
        'charge_ugx',
        'fx_profit_ugx',
        'fx_profit_usd',
        'usd_rmb_profit_cny',
        'usd_rmb_profit_ugx',
        'total_profit_ugx',
        'total_usd_equivalent',
        'status',
        'created_by',
        'updated_by',
        'notes',
        'purpose',
        'payment_method',
        'payment_reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'rmb_buying_rate' => 'decimal:6',
        'rate_buy_ugx' => 'decimal:6',
        'rate_sell_ugx' => 'decimal:6',
        'rmb_amount' => 'decimal:2',
        'charge_rate' => 'decimal:4',
        'charge_amount' => 'decimal:2',
        'charge_ugx' => 'decimal:2',
        'fx_profit_ugx' => 'decimal:2',
        'fx_profit_usd' => 'decimal:2',
        'usd_rmb_profit_cny' => 'decimal:6',
        'usd_rmb_profit_ugx' => 'decimal:6',
        'total_profit_ugx' => 'decimal:6',
        'total_usd_equivalent' => 'decimal:2',
        'settled_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $transaction) {
            foreach (['sender_name', 'receiver_name'] as $field) {
                if ($transaction->isDirty($field)) {
                    $transaction->{$field} = mb_convert_case(trim($transaction->{$field}), MB_CASE_TITLE, 'UTF-8');
                }
            }
        });

        static::updating(function (self $transaction) {
            if (auth()->check()) {
                $transaction->updated_by = auth()->id();
            }
        });

        // Both sides of a transfer are people the business will deal with again, so
        // the directory fills itself instead of waiting for someone to type it in.
        // Filing is a convenience, so a rejected row is reported and skipped rather
        // than taking the booking down with it.
        static::created(function (self $transaction) {
            try {
                Customer::syncFromTransaction($transaction);
            } catch (\Throwable $e) {
                Log::warning("Customer directory sync skipped for {$transaction->receipt_number}: {$e->getMessage()}");
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
     * The desk in China the dollars for this transfer were sent to. Their own dollar and yuan
     * wallets are found from this person, so the book always knows whose hand the money is in.
     */
    public function settledDesk(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_to');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * The cash the customer handed over. A shilling booking has the charge taken out of it, so
     * the two add back together. A dollar booking forwards the dollars whole, so the charge is
     * extra money that arrives alongside them rather than part of the figure handed over.
     */
    public function cashReceived(): string
    {
        return $this->currency === 'USD'
            ? (string) $this->amount
            : bcadd((string) $this->amount, (string) $this->charge_ugx, 2);
    }

    /**
     * What the till takes in when this transfer is approved, and the same figure hands back when
     * it is cancelled, so the same text serves both sides of the books.
     */
    public function collectedAmount(): string
    {
        return $this->currency === 'USD'
            ? 'USD ' . number_format((float) $this->amount, 1) . ' plus ' . $this->feeLabel()
            : 'UGX ' . number_format((float) $this->cashReceived());
    }

    /**
     * The charge is always quoted in shillings, whichever currency the transfer was booked in,
     * so it reads the same way on every screen. It is typed by hand as a whole shilling figure,
     * so it is printed without decimals while the money that travels keeps one.
     */
    public function feeLabel(): string
    {
        return 'UGX ' . number_format((float) $this->charge_ugx);
    }

    /**
     * Shillings are handed over in notes, so a UGX figure is a flat whole number. Dollars and
     * yuan are cut by a rate, so they keep the one decimal that fraction lands in.
     */
    public static function amountDecimals(string $currency): int
    {
        return strtoupper($currency) === 'UGX' ? 0 : 1;
    }

    public function principalLabel(): string
    {
        return $this->currency . ' ' . number_format((float) $this->amount, static::amountDecimals($this->currency));
    }

    /**
     * How many yuan one dollar of this transfer bought. Read from the transfer itself when
     * it carries both figures; rows booked before those were stored fall back to whatever
     * USD/RMB rate stands on the rate sheet today.
     */
    public function yuanRatePerUsd(): float
    {
        if ((float) $this->total_usd_equivalent > 0 && (float) $this->rmb_amount > 0) {
            return (float) $this->rmb_amount / (float) $this->total_usd_equivalent;
        }

        return (float) (ExchangeRate::getActiveRate('USD', 'RMB')->rate ?? 0);
    }

    /**
     * What the yuan leg is worth in dollars, read at the rate this transfer delivered at.
     * Null means the leg was never priced.
     */
    public function yuanLegUsd(): ?float
    {
        if ($this->usd_rmb_profit_cny === null) {
            return null;
        }

        $perUsd = $this->yuanRatePerUsd();

        return $perUsd > 0 ? (float) $this->usd_rmb_profit_cny / $perUsd : null;
    }

    /**
     * The same rule as yuanRatePerUsd(), written for aggregate SQL so grouped queries price
     * each row at its own booking rate. The multiply by 1.0 is not decoration: both columns
     * hold whole numbers whenever a transfer delivered an exact number of yuan, and sqlite
     * then divides them as integers, turning a 7.4 rate into 7.
     */
    public static function yuanRateSql(): array
    {
        $fallback = (float) (ExchangeRate::getActiveRate('USD', 'RMB')->rate ?? 0);

        return [
            '(CASE WHEN total_usd_equivalent > 0 AND rmb_amount > 0 THEN rmb_amount * 1.0 / total_usd_equivalent ELSE ? END)',
            [$fallback],
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('view-all-transactions')) {
            return $query;
        }

        return $query->where('created_by', $user->id);
    }

    /**
     * The rule that puts a transfer's yuan in one of the three piles, as a SQL fragment per pile.
     * Kept in one place because the money total and the count of transfers beside it have to be
     * cut on the same rule, and a board that sums one way and counts the other reads as a fault
     * in the books rather than a fault on the page.
     *
     * A transfer with no yuan in it belongs to no pile: there is nothing on its way to anybody, so
     * counting it as zero in the paid pile would hide how many receivers are still owed money.
     *
     * @return array<string, string> pile key => WHERE fragment
     */
    private static function yuanPileConditions(): array
    {
        $live = "status = 'completed' AND rmb_amount > 0";

        return [
            'in_uganda' => "{$live} AND settled_at IS NULL AND delivered_at IS NULL",
            'in_china' => "{$live} AND settled_at IS NOT NULL AND delivered_at IS NULL",
            'paid' => "{$live} AND delivered_at IS NOT NULL",
        ];
    }

    /**
     * The three piles the yuan sits in, as SQL, so a board and a report cut the book on exactly
     * the same rule and can never disagree about what has actually reached China. The three are
     * exclusive and add back to every yuan a completed transfer ever promised.
     *
     * @return array<string, string> pile key => select expression
     */
    public static function yuanPileSql(): array
    {
        return array_map(
            fn (string $where, string $key) => "COALESCE(SUM(CASE WHEN {$where} THEN rmb_amount ELSE 0 END), 0) as rmb_{$key}",
            array_values(static::yuanPileConditions()),
            array_keys(static::yuanPileConditions()),
        );
    }

    /**
     * The same three piles counted, for the line under a yuan total that says how many transfers
     * make it up.
     *
     * @return array<string, string> pile key => select expression
     */
    public static function yuanPileCountSql(): array
    {
        return array_map(
            fn (string $where, string $key) => "COALESCE(SUM(CASE WHEN {$where} THEN 1 ELSE 0 END), 0) as transfers_{$key}",
            array_values(static::yuanPileConditions()),
            array_keys(static::yuanPileConditions()),
        );
    }

    /**
     * Yuan whose dollars have not left Uganda yet: the money has been collected and priced, and it
     * is still sitting with the desk that took it.
     */
    public function scopeAwaitingSendToChina(Builder $query): Builder
    {
        return $query->where('status', 'completed')
            ->where('rmb_amount', '>', 0)
            ->whereNull('settled_at')
            ->whereNull('delivered_at');
    }

    /**
     * Yuan the desk in China holds dollars for, but has not yet handed over to the receiver.
     */
    public function scopeAwaitingPayoutInChina(Builder $query): Builder
    {
        return $query->where('status', 'completed')
            ->where('rmb_amount', '>', 0)
            ->whereNotNull('settled_at')
            ->whereNull('delivered_at');
    }

    /**
     * Whether this transfer's money has reached China at all, which is what decides if it can be
     * cancelled from the Uganda side. Once the dollars have moved, cancelling the booking here
     * would leave the cash in two places and the books telling two stories.
     */
    public function hasLeftUganda(): bool
    {
        return $this->settled_at !== null || $this->delivered_at !== null;
    }

    /**
     * Where the money of one transfer actually is, in the words the desk uses for it. Null means
     * there is no China leg to track: the booking is not finished, or it carries no yuan.
     *
     * @return array{key: string, text: string, tone: string}|null
     */
    public function chinaStage(): ?array
    {
        if ($this->status !== 'completed' || (float) $this->rmb_amount <= 0) {
            return null;
        }

        if ($this->delivered_at !== null) {
            return ['key' => 'paid', 'text' => 'Paid to '.$this->receiver_name.' in China', 'tone' => 'done'];
        }

        if ($this->settled_at !== null) {
            return [
                'key' => 'in_china',
                'text' => 'Dollars with '.($this->settledDesk?->name ?? 'the China desk').', waiting to be paid out',
                'tone' => 'waiting',
            ];
        }

        return ['key' => 'in_uganda', 'text' => 'Held in Uganda, not yet sent to China', 'tone' => 'pending'];
    }

    /**
     * The three profit figures for this transfer as display text: what the exchange came to,
     * what was charged, and the two added. The legs are stored separately so the trail behind
     * the exchange figure survives, but the office reports one profit per transfer, so the
     * screen only ever shows the sum.
     *
     * A transfer that has not finished earned nothing yet, so it reports zeros rather than the
     * money priced at booking time. A finished transfer booked before the rates were stored
     * reports n/a, because a zero there would read as break-even.
     *
     * @return array<string, array{text: string, tone: string}>
     */
    public function profitLabels(): array
    {
        if ($this->status !== 'completed') {
            return [
                'exchange' => ['text' => 'UGX 0.0', 'tone' => 'muted'],
                'charge' => ['text' => 'UGX 0', 'tone' => 'muted'],
                'total' => ['text' => 'UGX 0.0', 'tone' => 'muted'],
            ];
        }

        $exchange = $this->fx_profit_ugx === null || $this->usd_rmb_profit_ugx === null
            ? null
            : bcadd((string) $this->fx_profit_ugx, (string) $this->usd_rmb_profit_ugx, 6);

        return [
            'exchange' => $this->shillingProfitCell($exchange),
            'charge' => $this->shillingProfitCell($this->charge_ugx, 0),
            'total' => $this->shillingProfitCell($this->total_profit_ugx),
        ];
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function shillingProfitCell(?string $ugx, int $decimals = 1): array
    {
        if ($ugx === null || $ugx === '') {
            return ['text' => 'n/a', 'tone' => 'muted'];
        }

        $value = (float) $ugx;

        return $value < 0
            ? ['text' => 'Loss UGX '.number_format(abs($value), $decimals), 'tone' => 'loss']
            : ['text' => 'UGX '.number_format($value, $decimals), 'tone' => 'plain'];
    }

    /**
     * The price a transfer actually went out at, which is the only rate the book shows. The buying
     * price stays behind the scenes: it is what the profit was worked out from, but the number a
     * desk quotes a customer and the number a receiver is paid on are both the selling one.
     * A row booked before the price was snapshotted has nothing to show, so it reads n/a instead
     * of borrowing whatever stands on the rate sheet today. A price of nothing reads the same way:
     * it would otherwise look like a dollar bought for no shillings at all.
     *
     * @return array{text: string, tone: string, title: string}
     */
    private function rateCell(?string $sell, string $unit): array
    {
        if ($sell === null || (float) $sell === 0.0) {
            return ['text' => 'n/a', 'tone' => 'muted', 'title' => 'No price was recorded on this transfer'];
        }

        $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return [
            'text' => $trim($sell),
            'tone' => 'plain',
            'title' => 'Sent at '.$trim($sell).' '.$unit.' per 1 USD',
        ];
    }

    /**
     * @return array{text: string, tone: string, title: string}
     */
    public function shillingRateCell(): array
    {
        return $this->rateCell($this->rate_sell_ugx, 'UGX');
    }

    /**
     * @return array{text: string, tone: string, title: string}
     */
    public function yuanRateCell(): array
    {
        return $this->rateCell($this->exchange_rate, 'RMB');
    }

    public function yuanLabel(): string
    {
        return '¥'.number_format((float) $this->rmb_amount, 1);
    }

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        if ($request->filled('search')) {
            $search = '%'.addcslashes($request->search, '%_\\').'%';
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', $search)
                    ->orWhere('sender_name', 'like', $search)
                    ->orWhere('receiver_name', 'like', $search)
                    ->orWhere('sender_contact', 'like', $search)
                    ->orWhere('receiver_contact', 'like', $search)
                    ->orWhere('payment_reference', 'like', $search);
            });
        }

        foreach (['status', 'currency', 'created_by', 'purpose', 'payment_method'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        // Which selling price a transfer went out at. The buying price is not a filter any more
        // because it is not a figure the book shows; it still sits in the row and still feeds the
        // profit. Only a viewer who may see profit may narrow the book by price, because narrowing
        // by a price reveals it.
        if ($request->user()?->can('view-profit')) {
            foreach (['usd_rate' => 'rate_sell_ugx', 'rmb_rate' => 'exchange_rate'] as $filter => $column) {
                if ($request->filled($filter) && is_numeric($request->input($filter))) {
                    $query->where($column, number_format((float) $request->input($filter), 6, '.', ''));
                }
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query;
    }

    /**
     * The distinct prices recorded against the transfers a viewer may see, on either side of
     * one pair, newest price first. Trimmed to the shortest form that reads the same figure,
     * because the columns store six decimal places and 3760.000000 is not worth showing.
     *
     * @param  array<int, string>  $columns
     * @return array<int, string>
     */
    public static function bookedPrices(User $viewer, array $columns): array
    {
        $prices = collect();

        foreach ($columns as $column) {
            $prices = $prices->merge(
                static::visibleTo($viewer)
                    ->whereNotNull($column)
                    ->where($column, '>', 0)
                    ->distinct()
                    ->limit(50)
                    ->pluck($column)
            );
        }

        return $prices
            ->map(fn ($price) => rtrim(rtrim(number_format((float) $price, 6, '.', ''), '0'), '.'))
            ->unique()
            ->sortByDesc(fn ($price) => (float) $price)
            ->values()
            ->all();
    }

    public static function generateReceiptNumber(): string
    {
        $prefix = trim((string) Setting::get('receipt_prefix', 'PS'), " \t\n\r\0\x0B-") ?: 'PS';
        $date = now()->format('Ymd');

        $issued = static::withTrashed()
            ->where('receipt_number', 'like', "{$prefix}-{$date}-%")
            ->pluck('receipt_number');

        $next = 0;
        foreach ($issued as $number) {
            if (preg_match('/-(\d+)$/', $number, $matches)) {
                $next = max($next, (int) $matches[1]);
            }
        }

        return sprintf('%s-%s-%04d', $prefix, $date, $next + 1);
    }

    /**
     * Two cashiers submitting in the same second can both read the same sequence,
     * so retry on the unique receipt_number collision instead of throwing a 500.
     */
    public static function createWithReceiptNumber(array $attributes, int $attempts = 5): self
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return static::create(array_merge($attributes, ['receipt_number' => static::generateReceiptNumber()]));
            } catch (QueryException $e) {
                if ($attempt >= $attempts || ! self::isDuplicateReceiptNumber($e)) {
                    throw $e;
                }

                usleep(60000 * $attempt);
            }
        }
    }

    private static function isDuplicateReceiptNumber(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'receipt_number');
    }
}
