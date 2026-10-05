<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

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
        'rmb_amount',
        'charge_rate',
        'charge_amount',
        'total_usd_equivalent',
        'status',
        'created_by',
        'notes',
        'purpose',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'rmb_amount' => 'decimal:2',
        'charge_rate' => 'decimal:4',
        'charge_amount' => 'decimal:2',
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
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole(['superadmin', 'admin'])) {
            return $query;
        }

        return $query->where('created_by', $user->id);
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
