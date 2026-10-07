<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\AccountLedger;

/**
 * The cash books follow the transfer's status, so nobody has to remember to post it.
 */
class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        if ($transaction->status === 'completed') {
            AccountLedger::postCompletedTransfer($transaction);
        }
    }

    public function updated(Transaction $transaction): void
    {
        if (! $transaction->wasChanged('status')) {
            return;
        }

        if ($transaction->status === 'completed') {
            AccountLedger::postCompletedTransfer($transaction);

            return;
        }

        if ($transaction->getOriginal('status') === 'completed') {
            AccountLedger::reverseCompletedTransfer($transaction);
        }
    }

    public function deleted(Transaction $transaction): void
    {
        if ($transaction->status === 'completed') {
            AccountLedger::reverseCompletedTransfer($transaction);
        }
    }
}
