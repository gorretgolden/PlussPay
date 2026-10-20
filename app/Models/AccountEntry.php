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
        'handover_in' => ['label' => 'Received from an agent', 'direction' => 'in', 'group' => 'transfer'],
        'handover_out' => ['label' => 'Handed to the super agent', 'direction' => 'out', 'group' => 'transfer'],
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

    /**
     * On the account pages a line is named by what it did to the books, so a hand-in reads as
     * "Sent to another account". Nobody stands at a counter thinking in accounts. A wallet line
     * gets the words the person holding the cash would use.
     */
    public static function walletLabel(?string $type): string
    {
        return match ($type) {
            'money_in' => 'Collected',
            'money_out' => 'Paid out',
            'agent_fee' => 'Commission',
            'agent_fee_reversal' => 'Commission given back',
            'transfer_out' => 'Handed in',
            'transfer_in' => 'Received back',
            'handover_out' => 'Handed in',
            'handover_in' => 'Received from an agent',
            'adjustment_in' => 'Credited by the office',
            'adjustment_out' => 'Debited by the office',
            'reversal' => 'Given back',
            default => static::labelFor($type),
        };
    }

    /**
     * What each of these accounts has already given up to another account. One query, because the
     * agent list shows every desk at once and a page of balances should not become a page of sums.
     *
     * @param  iterable<int, Account>  $accounts
     * @return array<int, string> account id => net figure handed over
     */
    public static function handedInFor(iterable $accounts): array
    {
        $ids = [];
        $totals = [];

        foreach ($accounts as $account) {
            $ids[] = $account->id;
            $totals[$account->id] = '0.00';
        }

        if ($ids === []) {
            return $totals;
        }

        $rows = static::whereIn('account_id', $ids)
            // A handover_in is money arriving from an agent, not money this drawer has given up,
            // so only the paying-out lines count towards what has been handed in.
            ->whereIn('type', ['transfer_out', 'transfer_in', 'handover_out'])
            ->selectRaw('account_id, type, COALESCE(SUM(amount), 0) as total')
            ->groupBy('account_id', 'type')
            ->get();

        foreach ($rows as $row) {
            $sign = $row->type === 'transfer_in' ? -1 : 1;
            $totals[$row->account_id] = bcadd(
                $totals[$row->account_id] ?? '0.00',
                bcmul((string) $row->total, (string) $sign, 2),
                2,
            );
        }

        return $totals;
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
