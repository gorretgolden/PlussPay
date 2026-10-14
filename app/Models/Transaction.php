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
     * What the till takes in when this transfer is approved: the principal plus the
     * service charge, in the currency the transfer was booked in. Cancelling hands the
     * same figure back, so the same text serves both sides of the books.
     */
    public function collectedAmount(): string
    {
        return $this->currency === 'USD'
            ? 'USD ' . number_format((float) $this->amount + (float) $this->charge_amount, 2)
            : 'UGX ' . number_format((float) $this->amount + (float) $this->charge_ugx, 2);
    }

    /**
     * The charge is always quoted in shillings, whichever currency the transfer was
     * booked in, so it reads the same way on every screen.
     */
    public function feeLabel(): string
    {
        return 'UGX ' . number_format((float) $this->charge_ugx, 2);
    }

    public function principalLabel(): string
    {
        return $this->currency . ' ' . number_format((float) $this->amount, 2);
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
        if ($user->hasRole(['superadmin', 'admin', 'accountant'])) {
            return $query;
        }

        return $query->where('created_by', $user->id);
    }

    /**
     * The four profit figures for this transfer as display text, each with a tone so the screen
     * can put a loss in red and a figure that was never priced in gray.
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
            return array_fill_keys(
                ['fee', 'ugx_leg', 'rmb_leg', 'total'],
                ['text' => 'UGX 0.00', 'tone' => 'muted']
            );
        }

        return [
            'fee' => $this->shillingProfitCell($this->charge_ugx),
            'ugx_leg' => $this->marginCell(),
            'rmb_leg' => $this->yuanLegCell(),
            'total' => $this->shillingProfitCell($this->total_profit_ugx),
        ];
    }

    /**
     * Every step behind those figures, in the order the formulas run, so the breakdown dialog
     * can show how a number was arrived at instead of only the number.
     *
     * @return array<int, array{label: string, value: string, tone: string}>
     */
    public function profitBreakdown(): array
    {
        if ($this->status !== 'completed') {
            return [[
                'label' => 'Status',
                'value' => ucfirst($this->status).'. Only a completed transfer counts toward profit.',
                'tone' => 'muted',
            ]];
        }

        $dollars = (float) $this->total_usd_equivalent;
        $feeBlockUsd = (float) Setting::get('service_fee_usd', 100);
        $feeUgx = (float) Setting::get('service_fee_ugx', 2000);
        $step = fn (string $label, string $value, string $tone = 'plain') => ['label' => $label, 'value' => $value, 'tone' => $tone];

        $steps = [$step('The customer handed over', $this->principalLabel())];

        if ((float) $this->rate_sell_ugx > 0) {
            $steps[] = $step(
                'Dollars sold to the customer at',
                'UGX '.number_format((float) $this->rate_sell_ugx, 2).' per USD = USD '.number_format($dollars, 2)
            );
        }

        if ($this->charge_ugx !== null) {
            $fee = $this->shillingProfitCell($this->charge_ugx);
            $steps[] = $step(
                'Service fee',
                'UGX '.number_format($feeUgx, 0).' per '.number_format($feeBlockUsd, 0).' USD x '.number_format($dollars, 2).' USD = '.$fee['text'],
                $fee['tone']
            );
        }

        if ($this->currency === 'USD') {
            $steps[] = $step('Margin on the shillings', 'None. The customer handed over dollars, so no shillings were bought at a spread.', 'muted');
        } elseif ((float) $this->rate_buy_ugx > 0) {
            $steps[] = $step('Shillings bought back at', 'UGX '.number_format((float) $this->rate_buy_ugx, 2).' per USD');

            $margin = $this->marginCell();
            $steps[] = $step(
                'Margin on the shillings',
                '('.number_format((float) $this->rate_sell_ugx, 2).' - '.number_format((float) $this->rate_buy_ugx, 2).') x '.number_format($dollars, 2).' USD = '.$margin['text'],
                $margin['tone']
            );
        } else {
            $steps[] = $step('Margin on the shillings', 'n/a - no buying rate was stored on this transfer', 'muted');
        }

        if ((float) $this->exchange_rate > 0) {
            $steps[] = $step(
                'Yuan priced out to the receiver at',
                number_format((float) $this->exchange_rate, 4).' per USD = '.$this->yuanLabel()
            );
        }

        if ($this->rmb_buying_rate !== null) {
            $steps[] = $step('Yuan bought from China at', number_format((float) $this->rmb_buying_rate, 4).' per USD');
        }

        if ($this->usd_rmb_profit_cny !== null) {
            $yuanLeg = $this->yuanLegCell();
            $cny = (float) $this->usd_rmb_profit_cny;

            $steps[] = $step(
                'Yuan leg',
                '('.number_format((float) $this->rmb_buying_rate, 4).' - '.number_format((float) $this->exchange_rate, 4).') x '
                    .number_format($dollars, 2).' USD = CNY '.number_format($cny, 4)
                    .($this->usd_rmb_profit_ugx !== null ? ' = '.$yuanLeg['text'] : ''),
                $cny < 0 ? 'loss' : $yuanLeg['tone']
            );
        } else {
            $steps[] = $step('Yuan leg', 'n/a - the yuan buying rate was not stored on this transfer', 'muted');
        }

        $total = $this->shillingProfitCell($this->total_profit_ugx);
        $steps[] = $step(
            'Total profit',
            $total['text'].($this->total_profit_ugx === null ? ' - some rates were not stored on this transfer' : ' = fee + margin + yuan leg'),
            $total['tone']
        );

        return $steps;
    }

    /**
     * The shilling margin only exists when the transfer was priced against a buying rate. A
     * dollar payer has one stored and priced at zero, because no shillings went through the
     * till, so zero is the honest figure there and only a missing rate reads as n/a.
     *
     * @return array{text: string, tone: string}
     */
    private function marginCell(): array
    {
        if ($this->currency === 'USD') {
            return ['text' => 'UGX 0.00', 'tone' => 'plain'];
        }

        return (float) $this->rate_buy_ugx > 0
            ? $this->shillingProfitCell($this->fx_profit_ugx)
            : ['text' => 'n/a', 'tone' => 'muted'];
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function yuanLegCell(): array
    {
        return $this->usd_rmb_profit_ugx !== null
            ? $this->shillingProfitCell($this->usd_rmb_profit_ugx)
            : ['text' => 'n/a', 'tone' => 'muted'];
    }

    /**
     * @return array{text: string, tone: string}
     */
    private function shillingProfitCell(?string $ugx): array
    {
        if ($ugx === null || $ugx === '') {
            return ['text' => 'n/a', 'tone' => 'muted'];
        }

        $value = (float) $ugx;

        return $value < 0
            ? ['text' => 'Loss UGX '.number_format(abs($value), 2), 'tone' => 'loss']
            : ['text' => 'UGX '.number_format($value, 2), 'tone' => 'plain'];
    }

    public function yuanLabel(): string
    {
        return '¥'.number_format((float) $this->rmb_amount, 2);
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

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        return $query;
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
