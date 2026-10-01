<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
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
