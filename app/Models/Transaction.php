<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

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

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', "%{$search}%")
                    ->orWhere('sender_name', 'like', "%{$search}%")
                    ->orWhere('receiver_name', 'like', "%{$search}%")
                    ->orWhere('sender_contact', 'like', "%{$search}%")
                    ->orWhere('receiver_contact', 'like', "%{$search}%");
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
        $date = now()->format('Ymd');
        $last = static::where('receipt_number', 'like', "PS-{$date}-%")
            ->orderBy('id', 'desc')
            ->first();

        $next = $last ? (int) substr($last->receipt_number, -4) + 1 : 1;

        return sprintf('PS-%s-%04d', $date, $next);
    }
}
