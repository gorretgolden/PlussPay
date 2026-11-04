<?php

namespace App\Services;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\CashHandover;
use App\Models\CurrencyExchange;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountLedger
{
    public static function record(
        Account $account,
        string $type,
        string $amount,
        string $description,
        ?string $date = null,
        ?string $reference = null,
        ?int $transactionId = null,
        ?Account $counterparty = null,
        ?string $rate = null,
    ): AccountEntry {
        if (! isset(AccountEntry::TYPES[$type])) {
            throw new InvalidArgumentException("Unknown ledger entry type [{$type}].");
        }

        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('A ledger entry needs an amount greater than zero.');
        }

        return DB::transaction(function () use ($account, $type, $amount, $description, $date, $reference, $transactionId, $counterparty, $rate) {
            static::guardAgainstOverdraft($account, $type, $amount);

            return AccountEntry::create([
                'account_id' => $account->id,
                'counterparty_account_id' => $counterparty?->id,
                'transaction_id' => $transactionId,
                'entry_date' => $date ?? now()->toDateString(),
                'type' => $type,
                'direction' => AccountEntry::directionFor($type),
                'amount' => $amount,
                'rate' => $rate,
                'reference' => $reference,
                'description' => $description,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Money cannot leave a Uganda till that does not hold it, because that cash belongs to the
     * customers whose transfers have not landed yet, so the balance is read and checked before the
     * line is written. The account row itself is locked for the length of the transaction: two
     * deals booked on the same wallet at the same moment then queue instead of both spending the
     * same cash, which is the only way the overdraft check means anything.
     *
     * The China accounts are deliberately left free to go below zero. That side pays the receiver
     * in yuan out of its own float before Uganda settles the rouble of the deal across, so a
     * negative there is the honest picture of who is owed what rather than a missing payment.
     */
    private static function guardAgainstOverdraft(Account $account, string $type, string $amount): void
    {
        if (AccountEntry::directionFor($type) !== 'out' || AccountEntry::isCorrective($type)) {
            return;
        }

        $locked = Account::whereKey($account->id)->lockForUpdate()->first() ?? $account;

        if ($locked->country !== 'UG') {
            return;
        }

        $available = $locked->balance();

        if (bccomp($available, $amount, 2) < 0) {
            throw new InsufficientFundsException($locked, $amount, $available);
        }
    }

    /**
     * The same currency moving between our own accounts, such as dollars sent to China.
     * Nothing is gained or lost, so both legs carry the same amount.
     *
     * @return array{0: AccountEntry, 1: AccountEntry}
     */
    public static function transfer(Account $from, Account $to, string $amount, string $description, ?string $date = null, ?string $reference = null, ?int $transactionId = null): array
    {
        if ($from->currency !== $to->currency) {
            throw new InvalidArgumentException('Use an exchange to move money between different currencies.');
        }

        return DB::transaction(function () use ($from, $to, $amount, $description, $date, $reference, $transactionId) {
            $out = static::record($from, 'transfer_out', $amount, $description, $date, $reference, $transactionId, $to);
            $in = static::record($to, 'transfer_in', $amount, $description, $date, $reference, $transactionId, $from);

            return [$out, $in];
        });
    }

    /**
     * Cash an agent collected leaves their hand and lands in the super agent's wallet of the same
     * currency, which is the moment the agent stops being answerable for it. One movement, two
     * lines, the same figure on both, so the total the business holds never changes and whatever
     * is left in the agent's wallet is always the cash still in their drawer.
     *
     * A request that names its transfers carries two piles, because an agent's drawer holds both:
     * the money that has to travel, and the charges those bookings earned. Both cross to the super
     * agent on approval, and both are recorded apart because only the first of them goes on to
     * China. A whole-drawer request typed in one figure has no charge of its own to name, so it
     * moves the one line it always did.
     *
     * @return array<int, AccountEntry>
     */
    public static function handOverCash(CashHandover $handover): array
    {
        $currency = strtoupper($handover->currency);
        $amount = number_format((float) $handover->amount, 2, '.', '');
        $charges = number_format((float) $handover->charge_ugx, 2, '.', '');
        $from = Account::walletFor($handover->agent, $currency);
        $to = Account::walletFor($handover->superAgent, $currency);

        $description = 'Cash handover '.$handover->reference.': '.$handover->agent->name
            .' handed '.Account::format($currency, $amount).' to '.$handover->superAgent->name
            .($handover->note ? '. '.$handover->note : '');

        return DB::transaction(function () use ($from, $to, $amount, $charges, $description, $handover) {
            // The request was typed against a balance that may have moved since. The drawer is
            // locked while it is read, so the approval cannot pay out cash the agent no longer has.
            $locked = Account::whereKey($from->id)->lockForUpdate()->first() ?? $from;
            $available = $locked->balance();

            if (bccomp($available, $amount, 2) < 0) {
                throw new InsufficientFundsException($locked, $amount, $available);
            }

            $entries = [
                static::record($from, 'handover_out', $amount, $description, null, $handover->reference, null, $to),
                static::record($to, 'handover_in', $amount, $description, null, $handover->reference, null, $from),
            ];

            if (bccomp($charges, '0', 2) > 0) {
                $shillings = Account::walletFor($handover->agent, 'UGX');
                $kept = Account::walletFor($handover->superAgent, 'UGX');

                $chargeDescription = 'Charges from handover '.$handover->reference.': '
                    .$handover->agent->name.' handed the UGX '.number_format((float) $charges)
                    .' those transfers charged over to '.$handover->superAgent->name;

                // On a shilling booking these are the same two wallets the principal moved between,
                // and the lines stay apart anyway: one pile travels on to China and the other does
                // not, which is the whole difference the super agent's drawer has to keep showing.
                $held = $from->id === $shillings->id ? $locked : (Account::whereKey($shillings->id)->lockForUpdate()->first() ?? $shillings);

                if (bccomp($held->balance(), $charges, 2) < 0) {
                    throw new InsufficientFundsException($held, $charges, $held->balance());
                }

                $entries[] = static::record($shillings, 'fee_handover_out', $charges, $chargeDescription, null, $handover->reference, null, $kept);
                $entries[] = static::record($kept, 'fee_handover_in', $charges, $chargeDescription, null, $handover->reference, null, $shillings);
            }

            return $entries;
        });
    }

    /**
     * The dollars one transfer is worth leave the Uganda side whole and land in the desk in China's
     * own dollar wallet, and the transfer is stamped as sent in the same breath. The ledger, both
     * wallets, and the transfer have to agree that the money crossed or none of it changes, because
     * a half-recorded settlement is worse than none: the books would show dollars in China while
     * the screens still said Uganda was holding them.
     *
     * @return array{0: AccountEntry, 1: AccountEntry}
     */
    public static function sendToChina(Transaction $transaction, Account $from, User $desk, ?string $date = null): array
    {
        $amount = number_format((float) $transaction->total_usd_equivalent, 2, '.', '');

        $legs = static::transfer(
            $from,
            Account::walletFor($desk, 'USD'),
            $amount,
            "Transfer {$transaction->receipt_number}: ".Account::format('USD', $amount)
                .' sent to '.$desk->name.' in China for '.$transaction->receiver_name,
            $date,
            $transaction->receipt_number,
            $transaction->id,
        );

        $transaction->forceFill(['settled_at' => now(), 'settled_to' => $desk->id])->save();

        return $legs;
    }

    /**
     * The money a transfer promised at its far end is put into the receiver's hand, out of the
     * account the paying desk names, in the money the receiver was promised, so the two sides of
     * the chain always point at each other. A China payout draws on the wallet of the very desk
     * that was sent the dollars for it, and one handed dollars pays straight out of the dollars
     * that crossed, with no exchange in between. The China account is allowed to run below zero
     * (see guardAgainstOverdraft), because that side pays the receiver before Uganda has made the
     * dollars whole across. A Uganda payout draws on the office shilling book, because a booking
     * into Uganda carries no crossing: its money is in Uganda from the day it is taken. The stamp
     * that follows is what moves the transfer out of "unpaid" and into "paid".
     */
    public static function payOutReceiver(Transaction $transaction, Account $account, ?string $date = null): AccountEntry
    {
        $amount = number_format((float) $transaction->payoutAmount(), 2, '.', '');

        $entry = static::record(
            $account,
            'money_out',
            $amount,
            "Transfer {$transaction->receipt_number}: ".Account::format($account->currency, $amount)
                .' paid to '.$transaction->receiver_name.' in '.$transaction->destinationLabel(),
            $date,
            $transaction->receipt_number,
            $transaction->id,
        );

        $transaction->forceFill(['delivered_at' => now()])->save();

        return $entry;
    }

    /**
     * One currency given up and another taken in, which is how dollars bought in Uganda and
     * RMB handed over in China are recorded. The rate is stored so the deal can be re-checked.
     *
     * @return array{0: AccountEntry, 1: AccountEntry}
     */
    public static function exchange(Account $from, Account $to, string $outAmount, string $inAmount, string $description, ?string $date = null, ?string $reference = null): array
    {
        if ($from->currency === $to->currency) {
            throw new InvalidArgumentException('Use a transfer when both accounts hold the same currency.');
        }

        $rate = bcdiv($inAmount, $outAmount, 6);

        return DB::transaction(function () use ($from, $to, $outAmount, $inAmount, $description, $date, $reference, $rate) {
            $out = static::record($from, 'exchange_out', $outAmount, $description, $date, $reference, null, $to, $rate);
            $in = static::record($to, 'exchange_in', $inAmount, $description, $date, $reference, null, $from, $rate);

            return [$out, $in];
        });
    }

    /**
     * The agent who physically holds a booking's cash, or null when the booking was taken at the
     * office desk. Only the agent roles keep money in a wallet, because everyone else books from
     * behind the counter, where the till is in the same room. The super agent holds cash too: it is
     * the hand the other agents hand theirs in to.
     */
    private static function cashHeldBy(Transaction $transaction): ?User
    {
        if (! $transaction->created_by) {
            return null;
        }

        $collector = User::find($transaction->created_by);

        return $collector && $collector->hasAnyRole(['agent', 'super-agent']) ? $collector : null;
    }

    /**
     * Which office till the money handed over for a booking belongs in. Read off the end the cash
     * comes from and the money it arrives in, never off the desk that typed the booking: money paid
     * for in China is China's money even when the office here keys it in.
     */
    public static function intakeTill(Transaction $transaction): Account
    {
        return match (true) {
            $transaction->isToUganda() && strtoupper($transaction->currency) === 'RMB' => Account::byCode(Account::RMB_CHINA),
            $transaction->isToUganda() => Account::byCode(Account::USD_CHINA),
            strtoupper($transaction->currency) === 'USD' => Account::byCode(Account::USD_UGANDA),
            default => Account::byCode(Account::UGX_UGANDA),
        };
    }

    /**
     * Where collected cash belongs depends on who took it. An agent stands beside the customer and
     * holds that money in their own hand until it is handed in, so the collection credits the agent's
     * wallet and the office till only grows when the cash actually arrives. A booking taken at the
     * office desk is office money from the start, so it goes straight into the till as it always did.
     *
     * A customer who pays shillings hands over one sum that already contains the charge, so the
     * principal and the charge are two lines that add back to what was handed over: the principal is
     * money held for the office, the charge is the collector's own earnings. A customer who hands
     * over dollars forwards the dollars whole and adds the charge in shillings on top, so the deal
     * lands in two accounts and each gains only the money it actually holds.
     *
     * A booking into Uganda is the one case where the charge is not money the till receives: the
     * customer hands over yuan or dollars whole in China and the charge comes back out of the
     * shillings paid to the receiver here, so the intake is one line of exactly what arrived.
     */
    public static function postCompletedTransfer(Transaction $transaction): void
    {
        $alreadyPosted = AccountEntry::where('transaction_id', $transaction->id)
            ->where('type', 'money_in')
            ->exists();

        if ($alreadyPosted || bccomp((string) $transaction->amount, '0', 2) <= 0) {
            return;
        }

        $date = $transaction->created_at?->toDateString();
        $fee = (string) $transaction->charge_ugx;

        if ($holder = static::cashHeldBy($transaction)) {
            static::record(
                Account::walletFor($holder, $transaction->currency),
                'money_in',
                (string) $transaction->amount,
                "Transfer {$transaction->receipt_number} collected in {$transaction->currency} from {$transaction->sender_name}, held by {$holder->name} until handed in",
                $date,
                $transaction->receipt_number,
                $transaction->id,
            );

            return;
        }

        if ($transaction->isToUganda()) {
            static::record(
                static::intakeTill($transaction),
                'money_in',
                (string) $transaction->amount,
                "Transfer {$transaction->receipt_number} collected in {$transaction->currency} from {$transaction->sender_name} in China",
                $date,
                $transaction->receipt_number,
                $transaction->id,
            );

            return;
        }

        if ($transaction->currency === 'USD') {
            static::record(
                Account::byCode(Account::USD_UGANDA),
                'money_in',
                (string) $transaction->amount,
                "Transfer {$transaction->receipt_number} collected in dollars from {$transaction->sender_name}",
                $date,
                $transaction->receipt_number,
                $transaction->id,
            );

            if (bccomp($fee, '0', 2) > 0) {
                static::record(
                    Account::byCode(Account::UGX_UGANDA),
                    'money_in',
                    $fee,
                    "Transfer {$transaction->receipt_number} charge collected in shillings from {$transaction->sender_name}",
                    $date,
                    $transaction->receipt_number,
                    $transaction->id,
                );
            }

            return;
        }

        static::record(
            Account::byCode(Account::UGX_UGANDA),
            'money_in',
            bcadd((string) $transaction->amount, $fee, 2),
            "Transfer {$transaction->receipt_number} collected from {$transaction->sender_name}"
                .(bccomp($fee, '0', 2) > 0 ? ' (includes UGX '.number_format((float) $fee, 2).' fee)' : ''),
            $date,
            $transaction->receipt_number,
            $transaction->id,
        );
    }

    /**
     * The agent who collected a transfer keeps its charge as their own commission, in the same hand
     * that holds the principal. A booking taken at the office desk has no collector to pay, and its
     * charge was posted into the till with the rest of the cash, so writing a commission line for it
     * as well would count the same money twice.
     *
     * A booking into Uganda pays no commission line either way: the charge was never collected as
     * money, it is held back from the shillings that get paid out here, and the hand that took the
     * yuan in China is not holding shillings to keep it out of.
     */
    public static function postAgentFee(Transaction $transaction): void
    {
        if (! $transaction->created_by || $transaction->isToUganda()) {
            return;
        }

        $fee = (string) $transaction->charge_ugx;

        if (bccomp($fee, '0', 2) <= 0) {
            return;
        }

        $alreadyPosted = AccountEntry::where('transaction_id', $transaction->id)
            ->where('type', 'agent_fee')
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $agent = static::cashHeldBy($transaction);

        if (! $agent) {
            return;
        }

        $wallet = Account::walletFor($agent, 'UGX');

        static::record(
            $wallet,
            'agent_fee',
            $fee,
            "Charge on transfer {$transaction->receipt_number}",
            $transaction->created_at?->toDateString(),
            $transaction->receipt_number,
            $transaction->id,
        );
    }

    /**
     * A cancelled transfer takes back the agent's commission too.
     */
    public static function reverseAgentFee(Transaction $transaction): void
    {
        $deposit = AccountEntry::where('transaction_id', $transaction->id)
            ->where('type', 'agent_fee')
            ->first();

        if (! $deposit) {
            return;
        }

        $reversed = AccountEntry::where('transaction_id', $transaction->id)
            ->where('type', 'agent_fee_reversal')
            ->exists();

        if ($reversed) {
            return;
        }

        $wallet = Account::find($deposit->account_id);

        static::record(
            $wallet ?? Account::walletFor(User::find($transaction->created_by), 'UGX'),
            'agent_fee_reversal',
            (string) $deposit->amount,
            "Charge given back on cancelled transfer {$transaction->receipt_number}",
            now()->toDateString(),
            $transaction->receipt_number,
            $transaction->id,
        );
    }

    /**
     * A currency exchange with a walk-in customer moves money across the counter rather than
     * between our own books, so it posts two single legs: the account that takes their money goes
     * up and the account that hands ours over comes down. Both lines carry the same reference and
     * point at each other, which is what lets any screen name the two accounts a deal touched.
     */
    public static function postCurrencyExchange(CurrencyExchange $exchange): void
    {
        if (AccountEntry::where('reference', $exchange->reference)->exists()) {
            return;
        }

        $rate = $exchange->rate ? (string) $exchange->rate : null;
        $in = $exchange->inAccount;
        $out = $exchange->outAccount;
        $fee = self::exchangeFee($exchange);

        if ($in) {
            // What the customer handed over is split: the part that bought their currency and the
            // commission the desk kept, so the money the deal earned shows as its own line instead
            // of hiding inside the spread.
            $bought = bcsub((string) $exchange->in_amount, $fee, 2);

            if (bccomp($bought, '0.00', 2) > 0) {
                static::record(
                    $in,
                    'money_in',
                    $bought,
                    'Currency exchange '.$exchange->reference.': '.Account::format($in->currency, $bought)
                        .' taken in from '.$exchange->person_name,
                    $exchange->exchange_date->toDateString(),
                    $exchange->reference,
                    null,
                    $out,
                    $rate,
                );
            }

            if (bccomp($fee, '0.00', 2) > 0) {
                static::record(
                    $in,
                    'exchange_fee',
                    $fee,
                    'Charge on currency exchange '.$exchange->reference.': '
                        .Account::format($in->currency, $fee).' kept from '.$exchange->person_name,
                    $exchange->exchange_date->toDateString(),
                    $exchange->reference,
                    null,
                    null,
                    $rate,
                );
            }
        }

        if ($out) {
            static::record(
                $out,
                'money_out',
                (string) $exchange->out_amount,
                'Currency exchange '.$exchange->reference.': '.Account::format($out->currency, $exchange->out_amount)
                    .' handed to '.$exchange->person_name,
                $exchange->exchange_date->toDateString(),
                $exchange->reference,
                null,
                $in,
                $rate,
            );
        }
    }

    /**
     * The commission as a plain figure, or zero. A deal recorded before the desk charged one still
     * posts exactly the two legs it always did.
     */
    private static function exchangeFee(CurrencyExchange $exchange): string
    {
        $fee = $exchange->fee === null ? '0.00' : number_format((float) $exchange->fee, 2, '.', '');

        if (bccomp($fee, '0.00', 2) <= 0 || bccomp($fee, (string) $exchange->in_amount, 2) > 0) {
            return '0.00';
        }

        return $fee;
    }

    /**
     * Cancelling a deal gives back what changed hands. The shillings we took go back out and the
     * dollars or yuan we handed over come back in, so the account totals return to where they
     * were while both the deal and the undo stay on the trail.
     */
    public static function reverseCurrencyExchange(CurrencyExchange $exchange, ?string $reason = null): void
    {
        $alreadyReversed = AccountEntry::where('reference', $exchange->reference)
            ->where('type', 'exchange_back_out')
            ->exists();

        if ($alreadyReversed) {
            return;
        }

        $note = $reason ? ' Reason: '.$reason.'.' : '.';
        $in = $exchange->inAccount;
        $out = $exchange->outAccount;

        if ($in) {
            static::record(
                $in,
                'exchange_back_out',
                (string) $exchange->in_amount,
                'Cancelled currency exchange '.$exchange->reference.': '.Account::format($in->currency, $exchange->in_amount)
                    .' returned to '.$exchange->person_name.$note,
                now()->toDateString(),
                $exchange->reference,
                null,
                $out,
            );
        }

        if ($out) {
            static::record(
                $out,
                'exchange_back_in',
                (string) $exchange->out_amount,
                'Cancelled currency exchange '.$exchange->reference.': '.Account::format($out->currency, $exchange->out_amount)
                    .' came back from '.$exchange->person_name.$note,
                now()->toDateString(),
                $exchange->reference,
                null,
                $in,
            );
        }
    }

    /**
     * Money the business spent on itself leaves the account it was paid from and is gone, which is
     * why an expense is posted as a payment out rather than kept on a separate sheet.
     */
    public static function postExpense(Expense $expense): void
    {
        $account = $expense->account;

        if (! $account || AccountEntry::where('reference', $expense->reference)
            ->where('direction', 'out')->exists()) {
            return;
        }

        static::record(
            $account,
            'money_out',
            (string) $expense->amount,
            $expense->category.': '.$expense->description.' ('.$expense->reference.', paid via '
                .strtolower((string) ($expense->paid_via ?: 'cash')).')',
            $expense->expense_date->toDateString(),
            $expense->reference,
            null,
            null,
            null,
        );
    }

    /**
     * A mistake is called back with the opposite line rather than deleted, so the books show both
     * the payment and the refund.
     */
    public static function reverseExpense(Expense $expense, ?string $reason = null): void
    {
        $account = $expense->account;

        if (! $account || AccountEntry::where('reference', $expense->reference)
            ->where('direction', 'in')->exists()) {
            return;
        }

        static::record(
            $account,
            'money_in',
            (string) $expense->amount,
            'Voided expense '.$expense->reference.': '.$expense->category.', '.$expense->description
                .' paid back into the account'.($reason ? '. Reason: '.$reason : ''),
            now()->toDateString(),
            $expense->reference,
            null,
            null,
            null,
        );
    }

    /**
     * A cancelled transfer gives the money back, so every deposit is taken out again rather than
     * deleted. A dollar-funded deal deposited twice, so it is given back twice, in each currency.
     * The books keep both movements and still balance with the till.
     */
    public static function reverseCompletedTransfer(Transaction $transaction): void
    {
        $deposits = AccountEntry::where('transaction_id', $transaction->id)
            ->where('type', 'money_in')
            ->get();

        if ($deposits->isEmpty()) {
            return;
        }

        $reversed = AccountEntry::where('transaction_id', $transaction->id)
            ->where('type', 'reversal')
            ->exists();

        if ($reversed) {
            return;
        }

        foreach ($deposits as $deposit) {
            $account = Account::find($deposit->account_id);
            $currency = $account?->currency ?? $transaction->currency;

            static::record(
                $account ?? Account::byCode(Account::UGX_UGANDA),
                'reversal',
                (string) $deposit->amount,
                "Cancelled transfer {$transaction->receipt_number} returned to {$transaction->sender_name} ({$currency} ".number_format((float) $deposit->amount, 2).')',
                now()->toDateString(),
                $transaction->receipt_number,
                $transaction->id,
            );
        }
    }
}
