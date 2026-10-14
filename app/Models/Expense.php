<?php

namespace App\Models;

use App\Services\AccountLedger;
use App\Support\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Expense extends Model
{
    use Auditable;

    public const ACTIVE = 'active';
    public const VOID = 'void';

    /**
     * The heads she keeps her spending under. Free text is allowed for the rare one that does
     * not fit, which keeps the list short instead of growing a category for everything.
     */
    public const CATEGORIES = [
        'Rent',
        'Salaries',
        'Mobile money charges',
        'Bank charges',
        'Transport',
        'Airtime and internet',
        'Utilities',
        'Licences and permits',
        'Marketing',
        'Supplies',
        'Other',
    ];

    public const PAID_VIA = ['Cash', 'Mobile money', 'Bank transfer'];

    protected $fillable = [
        'reference',
        'expense_date',
        'category',
        'description',
        'currency',
        'amount',
        'account_id',
        'paid_via',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function legs()
    {
        return AccountEntry::with('account')
            ->where('reference', $this->reference)
            ->orderBy('direction')
            ->orderBy('id')
            ->get();
    }

    public function isVoid(): bool
    {
        return $this->status === self::VOID;
    }

    public function scopeFiltered(Builder $query, Request $request): Builder
    {
        if ($request->filled('search')) {
            $term = '%'.addcslashes($request->search, '%_\\').'%';
            $query->where(function ($q) use ($term) {
                $q->where('description', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhere('notes', 'like', $term);
            });
        }

        foreach (['category', 'paid_via', 'status'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->account_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('expense_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('expense_date', '<=', $request->date_to);
        }

        return $query;
    }

    /**
     * Save the expense and take the money out of the account in the same step, so the cash books
     * can never show an expense that has not been paid for.
     */
    public static function record(array $attributes, int $attempts = 5): self
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes) {
                    $expense = static::create(array_merge(
                        $attributes,
                        ['reference' => static::generateReference(), 'status' => self::ACTIVE]
                    ));

                    AccountLedger::postExpense($expense);

                    return $expense;
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt >= $attempts || ! str_contains($e->getMessage(), 'reference')) {
                    throw $e;
                }

                usleep(60000 * $attempt);
            }
        }
    }

    public function void(?string $reason = null): void
    {
        if ($this->isVoid()) {
            return;
        }

        DB::transaction(function () use ($reason) {
            $this->update(['status' => self::VOID]);
            AccountLedger::reverseExpense($this, $reason);
        });
    }

    public static function generateReference(): string
    {
        $date = now()->format('Ymd');

        $next = 0;
        foreach (static::where('reference', 'like', "EXP-{$date}-%")->pluck('reference') as $number) {
            if (preg_match('/-(\d+)$/', $number, $matches)) {
                $next = max($next, (int) $matches[1]);
            }
        }

        return sprintf('EXP-%s-%04d', $date, $next + 1);
    }
}
