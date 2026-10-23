<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Transaction extends Model
{
    use SoftDeletes;

    /**
     * How the customer handed this desk the money. Only the two mobile money answers arrive with
     * a confirmation number from the payment alert; cash is counted at the counter, and a bank
     * transfer or an answer under Other is booked without one.
     */
    public const PAYMENT_METHODS = ['Cash', 'MTN Mobile Money', 'Airtel Money', 'Bank transfer', 'Other'];

    public const CASH_PAYMENT = 'Cash';

    public const MOBILE_MONEY_METHODS = ['MTN Mobile Money', 'Airtel Money'];

    /**
     * Which end of the corridor the money is on its way to. A booking says this once, at the desk
     * that takes the money in, and the screens read the route off the row afterwards instead of
     * guessing it from the currency, because dollars travel either way.
     */
    public const DESTINATIONS = ['CN' => 'China', 'UG' => 'Uganda'];

    public const TO_CHINA = 'CN';

    public const TO_UGANDA = 'UG';

    /** The money a receiver is actually handed, which is what the payout leg of the books moves. */
    public const PAYOUT_CURRENCIES = ['RMB' => 'Yuan', 'USD' => 'Dollars', 'UGX' => 'Shillings'];

    protected $fillable = [
        'receipt_number',
        'sender_name',
        'sender_contact',
        'receiver_name',
        'receiver_contact',
        'currency',
        'amount',
        'destination',
        'payout_currency',
        'exchange_rate',
        'rmb_buying_rate',
        'rate_buy_ugx',
        'rate_sell_ugx',
        'rmb_amount',
        'ugx_amount',
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
        'payment_method',
        'payment_reference',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'rmb_buying_rate' => 'decimal:6',
        'rate_buy_ugx' => 'decimal:6',
        'rate_sell_ugx' => 'decimal:6',
        'rmb_amount' => 'decimal:2',
        'ugx_amount' => 'decimal:2',
        'charge_rate' => 'decimal:4',
        'charge_amount' => 'decimal:2',
        'charge_ugx' => 'decimal:2',
        'fx_profit_ugx' => 'decimal:2',
        'fx_profit_usd' => 'decimal:2',
        'usd_rmb_profit_cny' => 'decimal:6',
        'usd_rmb_profit_ugx' => 'decimal:6',
        'total_profit_ugx' => 'decimal:6',
        'total_usd_equivalent' => 'decimal:2',
        'settled_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Which desk took the money is read off the hand it was booked into, and it is written once.
        // The column is deliberately left out of $fillable so no form, import sheet or later edit
        // can set it by hand: a person moved to the other desk must not rewrite where a transfer
        // they already booked actually happened.
        static::creating(function (self $transaction) {
            $transaction->country = static::deskOf($transaction->created_by)
                ?? auth()->user()?->country
                ?? User::DEFAULT_COUNTRY;
        });

        // Every booking that does not say which way it is going is on the only route the desk had
        // when the book was started: money taken in Uganda, paid out in China as yuan. The columns
        // default to those values in the database too, so an older row and a row booked today read
        // the same way, and nothing already in the book is re-routed by this.
        static::creating(function (self $transaction) {
            $transaction->destination = $transaction->destination ?: self::TO_CHINA;
            $transaction->payout_currency = $transaction->payout_currency
                ?: ($transaction->destination === self::TO_UGANDA ? 'UGX' : 'RMB');
        });

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

    /**
     * The desk a hand sits at, or null when the book has no such person to read it from.
     */
    private static function deskOf(mixed $holderId): ?string
    {
        if (! $holderId) {
            return null;
        }

        $country = User::whereKey($holderId)->value('country');

        return $country ? (string) $country : null;
    }

    /**
     * Read a desk back off the hand each older transfer was booked into. The migration that added
     * the column runs this once, and the test for it calls the same words, because the book kept
     * no record of a desk before then and the holder's own desk is the only evidence it holds.
     * A transfer nobody is named on, or whose holder has no desk set, stays on the home desk.
     */
    public static function stampMissingDesks(): void
    {
        \DB::statement("
            UPDATE transactions
            SET country = COALESCE(
                (SELECT users.country FROM users WHERE users.id = transactions.created_by),
                'UG'
            )
        ");
    }

    /**
     * Where this money was taken in, in the words the desks use for themselves. Read from the
     * transfer rather than from the person who booked it, so the answer does not change when
     * somebody is moved between desks.
     */
    public function deskLabel(): string
    {
        return User::COUNTRIES[$this->country] ?? ($this->country ?: 'No desk');
    }

    public function deskCode(): string
    {
        return (string) $this->country;
    }

    /**
     * Which end of the corridor this booking is heading for, in the words the desk cards use.
     * Defaults to the route the book has always run, so a row older than the column reads the same
     * as a row booked today.
     */
    public function destinationCode(): string
    {
        return $this->destination === self::TO_UGANDA ? self::TO_UGANDA : self::TO_CHINA;
    }

    /**
     * The corridor a desk books when the customer hands the cash over at its own counter:
     * Uganda sends to China, and China sends to Uganda. The cash never starts at the far end.
     */
    public static function destinationForDesk(?string $country): string
    {
        return $country === 'CN' ? self::TO_UGANDA : self::TO_CHINA;
    }

    public function isToUganda(): bool
    {
        return $this->destinationCode() === self::TO_UGANDA;
    }

    public function destinationLabel(): string
    {
        return static::DESTINATIONS[$this->destinationCode()] ?? 'No destination';
    }

    /**
     * Which end the money was handed over at. This is read off the route rather than off the desk
     * the booking was keyed into, because the two are not the same fact: the desk line above says
     * whose hand typed it in, and a booking made at the office for a receiver in Uganda can only
     * have been paid for in China.
     */
    public function originCode(): string
    {
        return $this->isToUganda() ? self::TO_CHINA : self::TO_UGANDA;
    }

    public function originLabel(): string
    {
        return static::DESTINATIONS[$this->originCode()] ?? 'Unknown';
    }

    /**
     * The one line a screen uses to say what a transfer is, so a list never shows a China-bound
     * booking and a Uganda-bound booking under the same heading.
     */
    public function corridorLabel(): string
    {
        return 'Money to a receiver in '.$this->destinationLabel();
    }

    /**
     * Which money the receiver is actually handed, and therefore which pile of the books this
     * transfer draws on when it is paid out. Rows older than the column promised yuan, which is
     * what their own yuan figure says they did.
     */
    public function payoutCurrency(): string
    {
        if (! $this->payout_currency) {
            return (float) $this->ugx_amount > 0 ? 'UGX' : 'RMB';
        }

        return strtoupper($this->payout_currency);
    }

    /**
     * The payout figure for this transfer's own route, in the column that holds it. Null means the
     * transfer promises nothing in handed-over money, which is how a booking priced before the
     * payout was stored reads.
     */
    public function payoutAmount(): ?string
    {
        return match ($this->payoutCurrency()) {
            'UGX' => (string) $this->ugx_amount,
            'USD' => (string) $this->total_usd_equivalent,
            default => (float) $this->rmb_amount > 0 ? (string) $this->rmb_amount : null,
        };
    }

    /**
     * The money the receiver is handed, written the way each currency is written elsewhere in the
     * book: shillings in notes so no decimals, yuan to the tenth they are counted in, dollars to
     * the cent they are credited at.
     */
    public function payoutLabel(): string
    {
        return match ($this->payoutCurrency()) {
            'UGX' => 'UGX '.number_format((float) $this->ugx_amount),
            'USD' => '$'.number_format((float) $this->total_usd_equivalent, 2),
            default => '¥'.number_format((float) $this->rmb_amount, 1),
        };
    }

    /**
     * The handed-over money of a set of transfers, added up per currency and dropping the currencies
     * that hold nothing. A batch that pays one receiver in yuan and another in dollars is two piles
     * of the book held in two wallets, so it never adds up to one number.
     *
     * @param  \Illuminate\Support\Collection<int, Transaction>  $transactions
     * @return array<string, string>
     */
    public static function sumPayouts($transactions): array
    {
        return $transactions
            ->groupBy(fn (self $t) => $t->payoutCurrency())
            ->map(fn ($rows) => number_format($rows->sum(fn (self $t) => (float) $t->payoutAmount()), 2, '.', ''))
            ->filter(fn (string $figure) => (float) $figure > 0)
            ->all();
    }

    /**
     * Those totals as one phrase, so a mixed batch reads "¥732.30 and $111.63" and a batch that paid
     * no dollars never mentions them.
     *
     * @param  array<string, string>  $payouts
     */
    public static function formatPayouts(array $payouts): string
    {
        if ($payouts === []) {
            return 'nothing';
        }

        return collect($payouts)
            ->map(fn (string $figure, string $currency) => Account::format($currency, $figure))
            ->implode(' and ');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The desk in China the dollars for this transfer were sent to. Their own dollar and yuan
     * wallets are found from this person, so the book always knows whose hand the money is in.
     */
    public function settledDesk(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_to');
    }

    /**
     * The hand-in request that this booking was named on. It is only set while that request is open
     * or has just been approved, because a sent-back or cancelled request gives its bookings back so
     * they can be handed in again on a fresh one.
     */
    public function cashHandover(): BelongsTo
    {
        return $this->belongsTo(CashHandover::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * The cash the customer handed over, at its face value in the money it was paid in.
     *
     * A shilling booking has the charge taken out of the notes counted over the counter, so the
     * charge and the money that travels add back to that figure. Every other booking hands over
     * whole units, dollars or yuan, and the charge is either extra money alongside them or, on the
     * way into Uganda, comes out of the shillings paid over at the far end instead. So only a
     * shilling booking carries the charge inside the cash received, and adding it to any other
     * would count the same money twice.
     */
    public function cashReceived(): string
    {
        return strtoupper($this->currency) === 'UGX'
            ? bcadd((string) $this->amount, (string) $this->charge_ugx, 2)
            : (string) $this->amount;
    }

    /**
     * What the till takes in when this transfer is approved, and the same figure hands back when
     * it is cancelled, so the same text serves both sides of the books.
     */
    public function collectedAmount(): string
    {
        if (strtoupper($this->currency) === 'UGX') {
            return 'UGX '.number_format((float) $this->cashReceived());
        }

        // On the way into Uganda the charge never reaches the till as extra money: it is held back
        // from the shillings paid out here, so the desk takes in exactly what was handed over.
        if ($this->isToUganda()) {
            return $this->principalLabel();
        }

        return $this->principalLabel().' plus '.$this->feeLabel();
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
     * How many yuan one dollar of this transfer was worth. Read from the transfer itself when it
     * carries the figures: the payout and the credit together on a booking that turned dollars into
     * yuan for a receiver in China, or the price stamped on the row when the yuan was handed over
     * here instead. Only a row that holds neither figure borrows the USD/RMB rate standing on the
     * sheet today, because that is the one case where the row genuinely has no price of its own.
     */
    public function yuanRatePerUsd(): float
    {
        if ((float) $this->total_usd_equivalent > 0 && (float) $this->rmb_amount > 0) {
            return (float) $this->rmb_amount / (float) $this->total_usd_equivalent;
        }

        if ((float) $this->exchange_rate > 0) {
            return (float) $this->exchange_rate;
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
            '(CASE WHEN total_usd_equivalent > 0 AND rmb_amount > 0 THEN rmb_amount * 1.0 / total_usd_equivalent'
                .' WHEN exchange_rate > 0 THEN exchange_rate * 1.0 ELSE ? END)',
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
     * The rule that puts a transfer's yuan in one of the three piles, as a SQL fragment per pile.
     * Kept in one place because the money total and the count of transfers beside it have to be
     * cut on the same rule, and a board that sums one way and counts the other reads as a fault
     * in the books rather than a fault on the page.
     *
     * A transfer with no yuan in it belongs to no pile: there is nothing on its way to anybody, so
     * counting it as zero in the paid pile would hide how many receivers are still owed money.
     *
     * @return array<string, string> pile key => WHERE fragment
     */
    private static function yuanPileConditions(): array
    {
        $live = "status = 'completed' AND rmb_amount > 0";

        return [
            'in_uganda' => "{$live} AND settled_at IS NULL AND delivered_at IS NULL",
            'in_china' => "{$live} AND settled_at IS NOT NULL AND delivered_at IS NULL",
            'paid' => "{$live} AND delivered_at IS NOT NULL",
        ];
    }

    /**
     * The three piles the yuan sits in, as SQL, so a board and a report cut the book on exactly
     * the same rule and can never disagree about what has actually reached China. The three are
     * exclusive and add back to every yuan a completed transfer ever promised.
     *
     * @return array<string, string> pile key => select expression
     */
    public static function yuanPileSql(): array
    {
        return array_map(
            fn (string $where, string $key) => "COALESCE(SUM(CASE WHEN {$where} THEN rmb_amount ELSE 0 END), 0) as rmb_{$key}",
            array_values(static::yuanPileConditions()),
            array_keys(static::yuanPileConditions()),
        );
    }

    /**
     * The same three piles counted, for the line under a yuan total that says how many transfers
     * make it up.
     *
     * @return array<string, string> pile key => select expression
     */
    public static function yuanPileCountSql(): array
    {
        return array_map(
            fn (string $where, string $key) => "COALESCE(SUM(CASE WHEN {$where} THEN 1 ELSE 0 END), 0) as transfers_{$key}",
            array_values(static::yuanPileConditions()),
            array_keys(static::yuanPileConditions()),
        );
    }

    /**
     * Transfers whose dollars have not left Uganda yet: the money has been taken in and priced, and
     * it is still sitting with the desk that took it. The pile is cut on the dollars rather than the
     * yuan, because a receiver in China can now be handed dollars, and those still have to cross.
     * A booking into Uganda is not in this list at all: its money arrives in Uganda rather than
     * leaving it.
     *
     * A row that promises yuan but carries no dollar figure is still listed, because it was listed
     * before this corridor existed and the desk has to send something for it. Those rows predate the
     * dollar column being filled in, and dropping one off the list would quietly strand a receiver.
     */
    public function scopeAwaitingSendToChina(Builder $query): Builder
    {
        return $query->where('status', 'completed')
            ->where('destination', self::TO_CHINA)
            ->whereNull('settled_at')
            ->whereNull('delivered_at')
            ->where(function (Builder $q) {
                $q->where('total_usd_equivalent', '>', 0)
                    ->orWhere('rmb_amount', '>', 0);
            });
    }

    /**
     * Which transfers hand over money in one named currency at their far end, and therefore which
     * wallet a payment out of that currency closes. The figure lives in a different column per
     * currency, so the pairing belongs here rather than in each screen that has to find the rows a
     * movement settles. A currency the book never pays out in matches nothing, which is the honest
     * answer: no transfer promises cash in a money the desk does not hold.
     */
    public function scopePayingIn(Builder $query, string $currency): Builder
    {
        return match (strtoupper($currency)) {
            'RMB' => $query->where('payout_currency', 'RMB')->where('rmb_amount', '>', 0),
            'USD' => $query->where('payout_currency', 'USD')->where('total_usd_equivalent', '>', 0),
            'UGX' => $query->where('payout_currency', 'UGX')->where('ugx_amount', '>', 0),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * The transfers that owe somebody cash at the end of their journey, in whichever money their own
     * route promises it. Read against payoutAmount(), so a row that promises yuan but carries no yuan
     * figure is not on this list to be paid 0.00 against.
     */
    public function scopeOwingPayout(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            foreach (['RMB', 'USD', 'UGX'] as $currency) {
                $q->orWhere(fn (Builder $rows) => $rows->payingIn($currency));
            }
        });
    }

    /**
     * Money the desk in China holds dollars for but has not yet handed over to the receiver, in
     * whichever of the two currencies that receiver is being paid in.
     */
    public function scopeAwaitingPayoutInChina(Builder $query): Builder
    {
        return $query->where('status', 'completed')
            ->where('destination', self::TO_CHINA)
            ->whereNotNull('settled_at')
            ->whereNull('delivered_at')
            ->owingPayout();
    }

    /**
     * Money the desk in Uganda owes a receiver in shillings but has not yet handed over. There is no
     * crossing stamp to wait for: a booking into Uganda arrives with its money in Uganda, so every
     * completed booking that direction is in this queue from the moment it is taken until it is paid.
     */
    public function scopeAwaitingPayoutInUganda(Builder $query): Builder
    {
        return $query->where('status', 'completed')
            ->where('destination', self::TO_UGANDA)
            ->whereNull('delivered_at')
            ->owingPayout();
    }

    /**
     * Whether this transfer's money has reached China at all, which is what decides if it can be
     * cancelled from the Uganda side. Once the dollars have moved, cancelling the booking here
     * would leave the cash in two places and the books telling two stories.
     */
    public function hasLeftUganda(): bool
    {
        return $this->settled_at !== null || $this->delivered_at !== null;
    }

    /**
     * Where the money of one transfer actually is, in the words the desk uses for it. Null means
     * there is no crossing to track: the booking is not finished, or it hands over no money at the
     * far end.
     *
     * The keys name the stage rather than a country, because either country can now be the far end.
     * The three are exclusive: a transfer is on its way, it is with the desk at the other end, or it
     * has been handed over.
     *
     * @return array{key: string, text: string, tone: string}|null
     */
    public function stage(): ?array
    {
        if ($this->status !== 'completed' || $this->payoutAmount() === null) {
            return null;
        }

        if ($this->delivered_at !== null) {
            return [
                'key' => 'paid',
                'text' => 'Paid to '.$this->receiver_name.' in '.$this->destinationLabel(),
                'tone' => 'done',
            ];
        }

        if ($this->settled_at !== null) {
            return [
                'key' => 'crossed',
                'text' => 'Dollars with '.($this->settledDesk?->name ?? 'the '.$this->destinationLabel().' desk')
                    .', waiting to be paid out',
                'tone' => 'waiting',
            ];
        }

        // The first line says only which desk holds the money, because the desk that took it is
        // named above this block: a transfer booked into a hand on the China desk has its yuan
        // sitting on that desk, and the sentence must not contradict the desk line.
        return [
            'key' => 'collected',
            'text' => 'Taken in at the '.$this->originLabel().' desk, not yet paid out in '.$this->destinationLabel(),
            'tone' => 'pending',
        ];
    }

    /**
     * The stage a transfer has reached, in the one or two words a list badge can carry. The books
     * call a booking completed the moment the counter takes the cash, but that is not the same as
     * finished: the receiver is still waiting until the money has crossed and been handed over.
     * The badge follows the journey instead, so the same row reads Cash taken, then Waiting in
     * China, then Paid. The stage is read off the destination, so the reverse corridor reads
     * Waiting in Uganda rather than borrowing China's name.
     *
     * Two more steps belong to the hand the cash is in rather than to the corridor: a booking an
     * agent has named on a hand-in waits for that approval, and once it is approved the money has
     * changed hands inside Uganda and is ready to be sent. Both are read off the stamp the request
     * left on this row, so no badge has to look the queue up to say where the cash stands.
     *
     * @return array{key: string, label: string}
     */
    public function progress(): array
    {
        if ($this->status === 'cancelled') {
            return ['key' => 'cancelled', 'label' => 'Cancelled'];
        }

        if ($this->status !== 'completed') {
            return ['key' => 'pending', 'label' => 'Pending'];
        }

        if ($this->delivered_at !== null) {
            return ['key' => 'paid', 'label' => 'Paid'];
        }

        if ($this->settled_at !== null) {
            return ['key' => 'waiting', 'label' => 'Waiting in '.$this->destinationLabel()];
        }

        if ($this->hand_in_status === CashHandover::PENDING) {
            return ['key' => 'hand_in', 'label' => 'Waiting for hand-in approval'];
        }

        if ($this->hand_in_status === CashHandover::APPROVED) {
            return ['key' => 'ready', 'label' => 'Ready to send to '.$this->destinationLabel()];
        }

        return ['key' => 'cash', 'label' => 'Cash taken'];
    }

    /**
     * The bookings a hand-in request is still waiting on. The desk that sends money to China refuses
     * these: the cash they stand for has been promised to a super agent who has not said yes yet, and
     * moving it across before then would spend money the books still count as another desk's.
     */
    public function scopeNamedOnOpenHandIn(Builder $query): Builder
    {
        return $query->where('hand_in_status', CashHandover::PENDING);
    }

    /**
     * Bookings whose cash is in the super agent's hand and have not yet crossed, which is the pile a
     * lead is choosing from when he decides what to send.
     */
    public function scopeReadyToSend(Builder $query): Builder
    {
        return $query->where('status', 'completed')
            ->where('hand_in_status', CashHandover::APPROVED)
            ->whereNull('settled_at')
            ->whereNull('delivered_at');
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
     * The price a transfer actually went out at, which is the only rate the book shows. The buying
     * price stays behind the scenes: it is what the profit was worked out from, but the number a
     * desk quotes a customer and the number a receiver is paid on are both the selling one.
     * A row booked before the price was snapshotted has nothing to show, so it reads n/a instead
     * of borrowing whatever stands on the rate sheet today. A price of nothing reads the same way:
     * it would otherwise look like a dollar bought for no shillings at all.
     *
     * @return array{text: string, tone: string, title: string}
     */
    private function rateCell(?string $sell, string $unit): array
    {
        if ($sell === null || (float) $sell === 0.0) {
            return ['text' => 'n/a', 'tone' => 'muted', 'title' => 'No price was recorded on this transfer'];
        }

        $trim = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return [
            'text' => $trim($sell),
            'tone' => 'plain',
            'title' => 'Sent at '.$trim($sell).' '.$unit.' per 1 USD',
        ];
    }

    /**
     * @return array{text: string, tone: string, title: string}
     */
    public function shillingRateCell(): array
    {
        return $this->rateCell($this->rate_sell_ugx, 'UGX');
    }

    /**
     * @return array{text: string, tone: string, title: string}
     */
    public function yuanRateCell(): array
    {
        return $this->rateCell($this->exchange_rate, 'RMB');
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
                    ->orWhere('receiver_contact', 'like', $search)
                    ->orWhere('payment_reference', 'like', $search);
            });
        }

        foreach (['status', 'currency', 'created_by', 'purpose', 'payment_method', 'country', 'destination', 'payout_currency'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }

        // The journey a transfer is on, cut by the same stamps the badge beside each row is worked
        // out from. A stage and a raw status are never offered together, but both are read here so an
        // older link or a download cannot lose its filter. Each option has to return exactly the rows
        // that print that badge, so the money-in-hand stages are cut on the hand-in stamp as well.
        if ($request->filled('stage')) {
            match ($request->input('stage')) {
                'cash' => $query->where('status', 'completed')
                    ->whereNull('settled_at')
                    ->whereNull('delivered_at')
                    ->whereNull('hand_in_status'),
                'hand_in' => $query->where('status', 'completed')
                    ->whereNull('settled_at')
                    ->whereNull('delivered_at')
                    ->where('hand_in_status', CashHandover::PENDING),
                'ready' => $query->readyToSend(),
                'waiting' => $query->where('status', 'completed')->whereNotNull('settled_at')->whereNull('delivered_at'),
                'paid' => $query->where('status', 'completed')->whereNotNull('delivered_at'),
                'pending' => $query->where('status', 'pending'),
                'cancelled' => $query->where('status', 'cancelled'),
                default => $query->whereRaw('1 = 0'),
            };
        }

        // Which selling price a transfer went out at. The buying price is not a filter any more
        // because it is not a figure the book shows; it still sits in the row and still feeds the
        // profit. Only a viewer who may see profit may narrow the book by price, because narrowing
        // by a price reveals it.
        if ($request->user()?->can('view-profit')) {
            foreach (['usd_rate' => 'rate_sell_ugx', 'rmb_rate' => 'exchange_rate'] as $filter => $column) {
                if ($request->filled($filter) && is_numeric($request->input($filter))) {
                    $query->where($column, number_format((float) $request->input($filter), 6, '.', ''));
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
     * The book cut by desk: how many transfers each one took in, and what that money came to.
     * Built from the same filtered query the list beside it is built from, so the figures always
     * describe exactly the rows on screen.
     *
     * Only a completed transfer carries money: cancelling gave the cash back, so a cancelled
     * booking counts in the number of bookings and not in the shillings, dollars or yuan. The
     * caption under the strip says the same, because a total that quietly dropped rows reads as a
     * fault in the books rather than a rule.
     *
     * The shilling figure is the cash a customer counted out, which holds the charge inside it,
     * exactly as cashReceived() describes it. Dollars and yuan arrive as whole units, so the charge
     * is money beside them rather than part of them. The charge column therefore repeats part of the
     * shilling figure on a shilling booking and adds to it on every other one.
     *
     * @return \Illuminate\Support\Collection<string, object>
     */
    public function scopeDeskTotals(Builder $query): \Illuminate\Support\Collection
    {
        $completed = "status = 'completed'";

        return $query
            ->selectRaw("country,
                COUNT(*) as bookings,
                COALESCE(SUM(CASE WHEN {$completed} AND currency = 'UGX' THEN amount + charge_ugx ELSE 0 END), 0) as cash_ugx,
                COALESCE(SUM(CASE WHEN {$completed} AND currency = 'USD' THEN amount ELSE 0 END), 0) as cash_usd,
                COALESCE(SUM(CASE WHEN {$completed} AND currency = 'RMB' THEN amount ELSE 0 END), 0) as cash_rmb,
                COALESCE(SUM(CASE WHEN {$completed} THEN charge_ugx ELSE 0 END), 0) as charges_ugx,
                COALESCE(SUM(CASE WHEN {$completed} THEN rmb_amount ELSE 0 END), 0) as rmb_out,
                COALESCE(SUM(CASE WHEN {$completed} THEN ugx_amount ELSE 0 END), 0) as ugx_out")
            ->groupBy('country')
            ->get()
            ->keyBy('country');
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
                    ->where($column, '>', 0)
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
