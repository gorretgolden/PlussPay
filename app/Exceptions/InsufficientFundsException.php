<?php

namespace App\Exceptions;

use App\Models\Account;
use RuntimeException;

/**
 * Raised by the ledger when money is asked to leave an account that does not hold it. The books
 * are only worth reading if a balance can never fall below the cash actually in the till, so the
 * posting is refused and the caller is told what the account really holds.
 */
class InsufficientFundsException extends RuntimeException
{
    public function __construct(
        public readonly Account $account,
        public readonly string $amount,
        public readonly string $available,
    ) {
        parent::__construct(
            $account->name.' holds '.Account::format($account->currency, $available)
            .', which is not enough for this payment of '
            .Account::format($account->currency, $amount).'.'
        );
    }
}
