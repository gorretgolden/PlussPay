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
     * One booked price pair as the book shows it: what the desk pays for a dollar, then the
     * price the money goes out at. A row booked before the prices were snapshotted has nothing
     * to show, so it reads n/a instead of borrowing whatever stands on the rate sheet today.
     *
     * @return array{text: string, tone: string, title: string}
     */
    private function rateCell(?string $buy, ?string $sell, string $unit): array
    {
        if (empty($buy) || empty($sell)) {
            return ['text' => 'n/a', 'tone' => 'muted', 'title' => 'No price was recorded on this transfer'];
        }

        $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return [
            'text' => $trim($buy).' / '.$trim($sell),
            'tone' => 'plain',
            'title' => 'Bought at '.$trim($buy).' and sent at '.$trim($sell).' '.$unit.' per 1 USD',
        ];
    }

    /**
     * @return array{text: string, tone: string, title: string}
     */
    public function shillingRateCell(): array
    {
        return $this->rateCell($this->rate_buy_ugx, $this->rate_sell_ugx, 'UGX');
    }

    /**
     * @return array{text: string, tone: string, title: string}
     */
    public function yuanRateCell(): array
    {
        return $this->rateCell($this->rmb_buying_rate, $this->exchange_rate, 'RMB');
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
                    ->orWhere('receiver_contact', 'like', $search);
            });
        }

        foreach (['status', 'currency', 'created_by', 'purpose'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        // Which price a transfer was booked at, on either side of the pair. A transfer touched
        // by a price counts as using it, so the buying price of the day and the price the money
        // went out at both find their own rows. The figure is cut to the scale the column stores
        // at, which keeps the comparison on the index instead of wrapping the column in a cast.
        // Only a viewer who may see profit may narrow the book by price, because narrowing by a
        // price reveals it.
        if ($request->user()?->can('view-profit')) {
            foreach (['usd_rate' => ['rate_buy_ugx', 'rate_sell_ugx'], 'rmb_rate' => ['rmb_buying_rate', 'exchange_rate']] as $filter => $columns) {
                if ($request->filled($filter) && is_numeric($request->input($filter))) {
                    $price = number_format((float) $request->input($filter), 6, '.', '');
                    $query->where(fn ($q) => $q->where($columns[0], $price)->orWhere($columns[1], $price));
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
