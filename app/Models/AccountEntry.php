<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

class AccountEntry extends Model
{
    /**
     * Every line carries its own direction, so a balance is a plain sum of what came in
     * minus what went out and a back-dated correction never breaks the running total.
     */
    public const TYPES = [
        'money_in' => ['label' => 'Money received', 'direction' => 'in', 'group' => 'single'],
        'money_out' => ['label' => 'Money paid out', 'direction' => 'out', 'group' => 'single'],
        'transfer_in' => ['label' => 'Received from another account', 'direction' => 'in', 'group' => 'transfer'],
        'transfer_out' => ['label' => 'Sent to another account', 'direction' => 'out', 'group' => 'transfer'],
        'exchange_in' => ['label' => 'Bought into this account', 'direction' => 'in', 'group' => 'exchange'],
        'exchange_out' => ['label' => 'Sold out of this account', 'direction' => 'out', 'group' => 'exchange'],
        'exchange_back_in' => ['label' => 'Handed back (deal cancelled)', 'direction' => 'in', 'group' => 'exchange'],
        'exchange_back_out' => ['label' => 'Taken back (deal cancelled)', 'direction' => 'out', 'group' => 'exchange', 'corrective' => true],
        'opening_balance' => ['label' => 'Opening balance', 'direction' => 'in', 'group' => 'single'],
        'adjustment_in' => ['label' => 'Correction (adds money)', 'direction' => 'in', 'group' => 'single'],
        'adjustment_out' => ['label' => 'Correction (removes money)', 'direction' => 'out', 'group' => 'single'],
        'reversal' => ['label' => 'Cancelled transfer', 'direction' => 'out', 'group' => 'single', 'corrective' => true],
        'agent_fee' => ['label' => 'Agent commission', 'direction' => 'in', 'group' => 'single'],
        'agent_fee_reversal' => ['label' => 'Reversed agent commission', 'direction' => 'out', 'group' => 'single', 'corrective' => true],
        'exchange_fee' => ['label' => 'Commission kept on a deal', 'direction' => 'in', 'group' => 'single'],
    ];

    protected $fillable = [
        'account_id',
        'counterparty_account_id',
        'transaction_id',
        'entry_date',
        'type',
        'direction',
        'amount',
        'rate',
        'reference',
        'description',
        'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
        'rate' => 'decimal:6',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counterparty_account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function directionFor(string $type): string
    {
        return self::TYPES[$type]['direction'] ?? 'in';
    }

    /**
     * An undo is never refused for want of cash. If a deal has to be called back, the refund goes
     * through even when the account has since spent the money, because blocking it would leave the
     * mistake standing and the books worse than the error they correct.
     */
    public static function isCorrective(string $type): bool
    {
        return (bool) (self::TYPES[$type]['corrective'] ?? false);
    }

    public static function labelFor(?string $type): string
    {
        return self::TYPES[$type]['label'] ?? (string) $type;
    }

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        if ($request->filled('search')) {
            $search = '%'.addcslashes($request->search, '%_\\').'%';
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', $search)
                    ->orWhere('reference', 'like', $search);
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->input('direction'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('entry_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('entry_date', '<=', $request->date_to);
        }

        return $query;
    }
}
