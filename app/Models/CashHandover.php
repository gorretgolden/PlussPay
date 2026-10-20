<?php

namespace App\Models;

use App\Services\AccountLedger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * The cash an agent carries is theirs to account for until the super agent has it in hand. The
 * request is therefore a row of its own, waiting, while the ledger still says the money is in the
 * agent's wallet; only an approval moves it, and a decline leaves every figure where it was.
 */
class CashHandover extends Model
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';

    protected $fillable = [
        'reference',
        'agent_id',
        'super_agent_id',
        'currency',
        'amount',
        'note',
        'status',
        'decision_note',
        'decided_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
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

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function figure(): string
    {
        return Account::format($this->currency, $this->amount);
    }

    public function scopePendingFor(Builder $query, User $superAgent): Builder
    {
        return $query->where('super_agent_id', $superAgent->id)->where('status', self::PENDING);
    }

    /**
     * Two agents typing in the same second can read the same sequence, so a reference collision is
     * retried rather than thrown back at the person as a 500.
     */
    public static function request(User $agent, User $superAgent, string $currency, string $amount, ?string $note = null, int $attempts = 5): self
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return static::create([
                    'reference' => static::generateReference(),
                    'agent_id' => $agent->id,
                    'super_agent_id' => $superAgent->id,
                    'currency' => strtoupper($currency),
                    'amount' => $amount,
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

            $this->refresh();

            return true;
        });
    }

    public function decline(string $reason): bool
    {
        return DB::transaction(function () use ($reason) {
            $locked = static::whereKey($this->getKey())->lockForUpdate()->first();

            if (! $locked || ! $locked->isPending()) {
                return false;
            }

            $locked->update([
                'status' => self::DECLINED,
                'decision_note' => $reason,
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
