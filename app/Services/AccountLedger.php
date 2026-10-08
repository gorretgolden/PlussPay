<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountEntry;
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
     * A completed transfer means the cash is in the Uganda till, fee included, so the
     * matching account gains it on the day the transfer was made.
     */
    public static function postCompletedTransfer(Transaction $transaction): void
    {
        $alreadyPosted = AccountEntry::where('transaction_id', $transaction->id)
            ->where('type', 'money_in')
            ->exists();

        if ($alreadyPosted || bccomp((string) $transaction->amount, '0', 2) <= 0) {
            return;
        }

        $account = $transaction->currency === 'USD'
            ? Account::byCode(Account::USD_UGANDA)
            : Account::byCode(Account::UGX_UGANDA);

        $currency = $account->currency;
        $fee = $currency === 'USD' ? (string) $transaction->charge_amount : (string) $transaction->charge_ugx;
        $collected = bcadd((string) $transaction->amount, $fee, 2);

        static::record(
            $account,
            'money_in',
            $collected,
            "Transfer {$transaction->receipt_number} collected from {$transaction->sender_name}"
                .(bccomp($fee, '0', 2) > 0 ? ' (includes '.$currency.' '.number_format((float) $fee, 2).' fee)' : ''),
            $transaction->created_at?->toDateString(),
            $transaction->receipt_number,
            $transaction->id,
        );
    }

    /**
     * The agent who created the transfer earns the service fee in their UGX wallet.
     */
    public static function postAgentFee(Transaction $transaction): void
    {
        if (! $transaction->created_by) {
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

        $agent = User::find($transaction->created_by);

        if (! $agent) {
            return;
        }

        $wallet = Account::walletFor($agent, 'UGX');

        static::record(
            $wallet,
            'agent_fee',
            $fee,
            "Commission on transfer {$transaction->receipt_number}",
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
            "Reversed commission on cancelled transfer {$transaction->receipt_number}",
            now()->toDateString(),
            $transaction->receipt_number,
            $transaction->id,
        );
    }

    /**
     * A cancelled transfer gives the money back, so the deposit is taken out again rather
     * than deleted. The books keep both movements and still balance with the bank.
     */
    public static function reverseCompletedTransfer(Transaction $transaction): void
    {
        $deposit = AccountEntry::where('transaction_id', $transaction->id)
            ->where('type', 'money_in')
            ->first();

        if (! $deposit) {
            return;
        }

        $reversed = AccountEntry::where('transaction_id', $transaction->id)
            ->where('type', 'reversal')
            ->exists();

        if ($reversed) {
            return;
        }

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
