<?php

namespace App\Models;

use App\Services\AccountLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * The cash an agent carries is theirs to account for until the super agent has it in hand. The
 * request is therefore a row of its own, waiting, while the ledger still says the money is in the
 * agent's wallet; only an approval moves it, and a decline leaves every figure where it was.
 *
 * A request usually names the transfers it stands for, so the rows an agent handed in are the rows
 * the super agent reads before approving. The bookings keep their own pointer back to the request
 * while it is open, which is how a transaction list can say "waiting for hand-in approval" without
 * joining a queue to find out.
 */
class CashHandover extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'reference',
        'agent_id',
        'super_agent_id',
        'currency',
        'amount',
        'charge_ugx',
        'receipts',
        'note',
        'status',
        'decision_note',
        'decided_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'charge_ugx' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function superAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'super_agent_id');
    }

    /**
     * The transfers this request stands for. They point back here only while the request is open,
     * so an approved hand-in keeps naming its bookings for good and a sent-back one lets them go.
     *
     * @return HasMany<Transaction>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('receipt_number');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Whether this request was raised out of named bookings rather than a typed figure. */
    public function namesBookings(): bool
    {
        return $this->transactions()->exists();
    }

    public function figure(): string
    {
        return Account::format($this->currency, $this->amount);
    }

    /** The charges the named bookings earned, which ride along with the cash and then stay behind. */
    public function chargeFigure(): string
    {
        return Account::format('UGX', $this->charge_ugx);
    }

    /**
     * The receipts the request covers, read off the live bookings when there are any and off the
     * snapshot taken when it was raised once there are not, because a sent-back request still has to
     * say which transfers it was about.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function receiptNumbers(): \Illuminate\Support\Collection
    {
        $live = $this->transactions->pluck('receipt_number')->filter()->values();

        if ($live->isNotEmpty()) {
            return $live;
        }

        return collect(explode(',', (string) $this->receipts))
            ->map(fn ($receipt) => trim((string) $receipt))
            ->filter()
            ->values();
    }

    public function scopePendingFor(Builder $query, User $superAgent): Builder
    {
        return $query->where('super_agent_id', $superAgent->id)->where('status', self::PENDING);
    }

    /**
     * Two agents typing in the same second can read the same sequence, so a reference collision is
     * retried rather than thrown back at the person as a 500.
     *
     * @param  array<int, string>|null  $receipts  the bookings the request stands for
     */
    public static function request(
        User $agent,
        User $superAgent,
        string $currency,
        string $amount,
        ?string $note = null,
        string $chargeUgx = '0.00',
        ?array $receipts = null,
        int $attempts = 5,
    ): self {
        for ($attempt = 1; ; $attempt++) {
            try {
                return static::create([
                    'reference' => static::generateReference(),
                    'agent_id' => $agent->id,
                    'super_agent_id' => $superAgent->id,
                    'currency' => strtoupper($currency),
                    'amount' => $amount,
                    'charge_ugx' => $chargeUgx,
                    'receipts' => $receipts === null ? null : implode(', ', $receipts),
                    'note' => $note,
                    'status' => self::PENDING,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($attempt >= $attempts || ! str_contains($e->getMessage(), 'reference')) {
                    throw $e;
                }

                usleep(60000 * $attempt);
            }
        }
    }

    /**
     * Point the named bookings at this request. The mirror of the request's own state goes on each
     * booking so a list badge reads one column, and the pointer is what lets the desk open the very
     * transfers an agent is asking to hand in.
     *
     * @param  array<int, int>  $ids
     */
    public function link(array $ids): void
    {
        Transaction::whereIn('id', $ids)
            ->whereNull('cash_handover_id')
            ->update(['cash_handover_id' => $this->id, 'hand_in_status' => self::PENDING, 'handed_in_at' => now()]);
    }

    /**
     * The moment the cash physically changes hands. The row is locked while it is decided, so two
     * approvals opened on the same request cannot both move the money and pay it in twice.
     */
    public function approve(?string $note = null): bool
    {
        return DB::transaction(function () use ($note) {
            $locked = static::whereKey($this->getKey())->lockForUpdate()->first();

            if (! $locked || ! $locked->isPending()) {
                return false;
            }

            AccountLedger::handOverCash($locked);

            $locked->update([
                'status' => self::APPROVED,
                'decision_note' => $note,
                'decided_at' => now(),
            ]);

            Transaction::where('cash_handover_id', $locked->id)->update(['hand_in_status' => self::APPROVED]);

            $this->refresh();

            return true;
        });
    }

    public function decline(string $reason): bool
    {
        return $this->close(self::DECLINED, $reason);
    }

    /**
     * The agent calls their own request back, which happens when the transfer turns out to need
     * something else first. Nothing moves in the books: the bookings simply stop being spoken for,
     * so the cash goes back to sitting in the drawer like anyone else's.
     */
    public function cancel(?string $reason = null): bool
    {
        return $this->close(self::CANCELLED, $reason);
    }

    /**
     * A request that never reached an approval gives the bookings back, so each one can be named on
     * a fresh hand-in. The pointer and the mirror are both cleared: leaving either behind would keep
     * a closed request attached to a transfer that is no longer waiting on anybody.
     */
    private function close(string $status, ?string $note): bool
    {
        return DB::transaction(function () use ($status, $note) {
            $locked = static::whereKey($this->getKey())->lockForUpdate()->first();

            if (! $locked || ! $locked->isPending()) {
                return false;
            }

            Transaction::where('cash_handover_id', $locked->id)
                ->update(['cash_handover_id' => null, 'hand_in_status' => null, 'handed_in_at' => null]);

            $locked->update([
                'status' => $status,
                'decision_note' => $note,
                'decided_at' => now(),
            ]);

            $this->refresh();

            return true;
        });
    }

    public static function generateReference(): string
    {
        $date = now()->format('Ymd');

        $next = 0;
        foreach (static::where('reference', 'like', "CH-{$date}-%")->pluck('reference') as $number) {
            if (preg_match('/-(\d+)$/', $number, $matches)) {
                $next = max($next, (int) $matches[1]);
            }
        }

        return sprintf('CH-%s-%04d', $date, $next + 1);
    }
}
