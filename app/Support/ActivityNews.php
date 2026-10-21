<?php

namespace App\Support;

use App\Http\Controllers\RolePermissionController;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CurrencyExchange;
use App\Models\Expense;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ActivityRecorded;
use Illuminate\Support\Facades\Notification;

/**
 * The one place that decides which audit rows are worth telling somebody about, who hears
 * about each kind, and where the click lands.
 *
 * It reads the audit trail instead of being called from the controllers, so a screen that
 * already writes a good sentence gets its news for free and a new screen cannot forget to
 * announce itself. The wording of a notice is fixed when it is written: the audit row it
 * came from can be pruned, and the record it points at can be deleted, without taking the
 * notice's own words down with them.
 */
final class ActivityNews
{
    /** Every ability that puts a person on the receiving end of at least one kind of news. */
    public const AUDIENCE = [
        'view-all-transactions', 'view-all-exchanges', 'view-all-expenses', 'view-all-wallets',
        'view-users', 'view-exchange-rates', 'manage-settings',
    ];

    /** A reader hears about a kind of news only if they can already open it. */
    private const ABILITIES = [
        'transfers' => ['view-all-transactions'],
        'china' => ['view-all-transactions'],
        'exchanges' => ['view-all-exchanges'],
        'expenses' => ['view-all-expenses'],
        'wallets' => ['view-all-wallets'],
        'users' => ['view-users'],
        'rates' => ['view-exchange-rates'],
        'settings' => ['manage-settings'],
    ];

    /**
     * The sidebar slot each kind of news counts towards. `wallets` has no slot of its own:
     * the My Wallet link is a person's own money, not the office's.
     */
    public const SIDEBAR_SLOTS = [
        'transfers' => 'transactions',
        'china' => 'china',
        'exchanges' => 'exchanges',
        'expenses' => 'expenses',
        'users' => 'users',
        'rates' => 'settings',
        'settings' => 'settings',
    ];

    private const LINKS = [
        'transfers' => 'Open the transfer',
        'china' => 'Open the China desk',
        'exchanges' => 'Open the exchange',
        'expenses' => 'Open the expense',
        'wallets' => 'Open the account',
        'users' => 'Open Users',
        'rates' => 'Open Exchange Rates',
        'settings' => 'Open Settings',
    ];

    /** News that comes out of the books the screens write by hand. */
    private const BY_ACTION = [
        'bulk_import' => ['transfers', 'Transfers were imported in bulk'],
        'sent_to_china' => ['china', 'Dollars were sent to the China desk'],
        'china_payout_recorded' => ['china', 'Yuan was paid out in China'],
        'wallet_adjusted' => ['wallets', 'A wallet balance was corrected'],
        'cash_handed_in' => ['wallets', 'Cash was handed in'],
        'cash_handover_requested' => ['wallets', 'An agent asked to hand cash in'],
        'cash_handover_approved' => ['wallets', 'Cash was handed to a super agent'],
        'cash_handover_declined' => ['wallets', 'A cash hand-in was declined'],
        'exchange_rate_edited' => ['rates', 'A price was corrected'],
        'exchange_rate_activated' => ['rates', 'The price in use changed'],
        'exchange_rate_deactivated' => ['rates', 'A price was taken out of use'],
        'market_buying_rate_applied' => ['rates', 'The market moved the buying rate'],
        'settings_updated' => ['settings', 'System settings were saved'],
        'user_created' => ['users', 'A new sign-in account was made'],
        'user_updated' => ['users', 'A sign-in account was edited'],
        'user_deleted' => ['users', 'A sign-in account was deleted'],
        'user_activated' => ['users', 'A sign-in account was allowed back in'],
        'user_deactivated' => ['users', 'A sign-in account was switched off'],
        'locked' => ['users', 'A sign-in account was locked out'],
        'unlocked' => ['users', 'A sign-in account was unlocked'],
        'role_changed' => ['users', 'A role was changed'],
        'password_reset' => ['users', 'A password was reset'],
    ];

    /**
     * A transfer is named by its own id on the audit row, because the action words are shared:
     * the customer book and the transfer book both write "created", and only the row carrying a
     * transaction id is about money moving.
     */
    private const BY_TRANSFER = [
        'created' => 'A new transfer was booked',
        'approved' => 'A transfer was approved',
        'cancelled' => 'A transfer was cancelled',
        'status_changed' => 'A transfer status was changed',
    ];

    /**
     * What the audit trail never writes is the money itself, so these two books have their
     * sentence built from the row instead of from the generic "Expense #12 created" the
     * shared trait has to fall back to.
     */
    private const BY_SUBJECT = [
        'expenses' => ['expenses', 'A new expense was entered', 'An expense was edited', 'An expense was cancelled', 'An expense was deleted'],
        'currency_exchanges' => ['exchanges', 'Money changed hands at the counter', 'An exchange was edited', 'An exchange was cancelled', 'An exchange was deleted'],
    ];

    /**
     * Unread counts for the sidebar badges: the total, and the count per slot. Read out of the
     * notices a person was actually sent, so a badge can never count news the reader is not
     * allowed to open.
     *
     * @return array<string, int>
     */
    public static function badgeCounts(User $user): array
    {
        $counts = ['total' => 0];

        foreach ($user->unreadNotifications()->get(['id', 'data']) as $notice) {
            $slot = self::SIDEBAR_SLOTS[$notice->data['section'] ?? ''] ?? null;
            $counts['total']++;

            if ($slot) {
                $counts[$slot] = ($counts[$slot] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @return array{section: string, headline: string, body: string, actor: string,
     *               actor_role: string, path: string, link_label: string, log_id: string}|null
     *         null means this audit row is not news to anybody.
     */
    public static function describe(ActivityLog $log): ?array
    {
        $news = self::news($log);

        if ($news === null) {
            return null;
        }

        [$section, $headline] = $news;

        return [
            'section' => $section,
            'headline' => $headline,
            'body' => self::body($log, $headline),
            'actor' => $log->user_name ?: 'The system',
            'actor_role' => $log->user_role ? RolePermissionController::displayName($log->user_role) : '',
            'path' => self::path($log, $section),
            'link_label' => self::LINKS[$section],
            'log_id' => (string) $log->id,
        ];
    }

    /**
     * Hand the notice to everyone still able to read that part of the book, except the person
     * who just did it. Runs on the same request that wrote the audit row: the hosting has no
     * worker to hand a queue to, and a message that arrived a minute later would be fine but
     * a message that never arrived would not.
     */
    public static function announce(ActivityLog $log): void
    {
        $news = self::describe($log);

        if ($news === null) {
            return;
        }

        $audience = self::audience(self::ABILITIES[$news['section']], $log->user_id);

        if ($audience->isEmpty()) {
            return;
        }

        Notification::sendNow($audience, new ActivityRecorded($news));
    }

    /**
     * @param  array<int, string> $abilities
     * @return \Illuminate\Support\Collection<int, User>
     */
    private static function audience(array $abilities, ?int $didIt): \Illuminate\Support\Collection
    {
        return User::query()
            ->where('is_active', true)
            ->when($didIt, fn ($q) => $q->whereKeyNot($didIt))
            ->where(fn ($q) => $q
                ->whereHas('roles', fn ($r) => $r->where('name', 'superadmin'))
                ->orWhereHas('roles.permissions', fn ($p) => $p->whereIn('name', $abilities))
                ->orWhereHas('permissions', fn ($p) => $p->whereIn('name', $abilities)))
            ->get();
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function news(ActivityLog $log): ?array
    {
        // A row of a bulk upload is one audit entry per booking; the summary written at the end
        // of the file is what a reader wants, so the per-row entries are passed over.
        if ($log->action === 'bulk_imported') {
            return null;
        }

        if ($log->transaction_id) {
            $headline = self::BY_TRANSFER[$log->action] ?? null;

            return $headline === null ? null : ['transfers', $headline];
        }

        $book = self::BY_SUBJECT[$log->subject_type] ?? null;

        if ($book === null) {
            return self::BY_ACTION[$log->action] ?? null;
        }

        // The shared trait writes created, updated and deleted for both books, and an update
        // that only carries a void is a cancellation rather than an edit.
        if ($log->action === 'created') {
            return [$book[0], $book[1]];
        }

        if ($log->action === 'deleted') {
            return [$book[0], $book[4]];
        }

        if ($log->action !== 'updated') {
            return null;
        }

        return [$book[0], self::isVoid($log) ? $book[3] : $book[2]];
    }

    private static function isVoid(ActivityLog $log): bool
    {
        $after = json_decode((string) $log->new_value, true);

        return is_array($after) && ($after['status'] ?? null) === Expense::VOID;
    }

    private static function body(ActivityLog $log, string $headline): string
    {
        $line = match (true) {
            (bool) $log->transaction_id => self::transferLine($log),
            $log->subject_type === 'expenses' => self::expenseLine($log),
            $log->subject_type === 'currency_exchanges' => self::exchangeLine($log),
            default => null,
        };

        return $line ?: ($log->description ?: $headline);
    }

    /**
     * The three figures a transfer is made of, each named for what it is: the shillings that came
     * over the counter, the charge taken out of them, and the dollars that left. A dollar booking
     * hands over its own currency, so it has no shilling figure of its own and the charge sits
     * beside it as extra money rather than inside it.
     */
    private static function transferLine(ActivityLog $log): ?string
    {
        $transfer = Transaction::find($log->transaction_id);

        if (! $transfer) {
            return null;
        }

        $travels = Account::format($transfer->currency, (float) $transfer->amount);
        $dollars = Account::format('USD', (float) $transfer->total_usd_equivalent);
        $fee = Account::format('UGX', (float) $transfer->charge_ugx);
        $who = $transfer->receiver_name ?: 'a receiver';
        $money = $transfer->currency === 'USD' ? $travels : $travels.' in for '.$dollars;

        return match ($log->action) {
            'cancelled' => "{$transfer->receipt_number}: {$money} to {$who} is cancelled and the money is back in the till",
            'approved' => "{$transfer->receipt_number}: {$money} to {$who} is approved and the till takes {$fee}",
            'status_changed' => "{$transfer->receipt_number}: {$money} to {$who} is now {$transfer->status}",
            default => "{$transfer->receipt_number}: {$money} to {$who}, {$fee} charge",
        };
    }

    private static function expenseLine(ActivityLog $log): ?string
    {
        $expense = Expense::find($log->subject_id);

        if (! $expense) {
            return null;
        }

        $money = Account::format($expense->currency, (float) $expense->amount);
        $what = $expense->description ?: $expense->category ?: 'Untitled';
        $where = $expense->account ? ' out of '.$expense->account->name : '';

        return match (true) {
            $log->action === 'deleted' => "{$expense->reference}: the {$money} spent on {$what} was deleted",
            $log->action === 'updated' && $expense->isVoid() => "{$expense->reference}: the {$money} spent on {$what} is cancelled and the money put back",
            $log->action === 'updated' => "{$expense->reference}: {$money} spent on {$what}{$where} was edited",
            default => "{$expense->reference}: {$money} spent on {$what}{$where}",
        };
    }

    private static function exchangeLine(ActivityLog $log): ?string
    {
        $exchange = CurrencyExchange::find($log->subject_id);

        if (! $exchange) {
            return null;
        }

        $in = Account::format($exchange->in_currency, (float) $exchange->in_amount);
        $out = Account::format($exchange->out_currency, (float) $exchange->out_amount);
        $who = $exchange->person_name ? ' for '.$exchange->person_name : '';

        return match (true) {
            $log->action === 'deleted' => "{$exchange->reference}: the exchange of {$in} for {$out} was deleted",
            $log->action === 'updated' && $exchange->isVoid() => "{$exchange->reference}: the exchange of {$in} for {$out} is cancelled and both accounts put back",
            $log->action === 'updated' => "{$exchange->reference}: {$in} in for {$out}{$who} was edited",
            default => "{$exchange->reference}: {$in} in for {$out}{$who}",
        };
    }

    private static function path(ActivityLog $log, string $section): string
    {
        if ($log->transaction_id) {
            return '/transactions/'.$log->transaction_id;
        }

        return match ($section) {
            'china' => '/china',
            'expenses' => $log->subject_id ? '/expenses/'.$log->subject_id : '/expenses',
            'exchanges' => $log->subject_id ? '/exchanges/'.$log->subject_id : '/exchanges',
            'wallets' => $log->subject_type === 'accounts' && $log->subject_id
                ? '/accounts/'.$log->subject_id
                : '/wallet',
            'users' => '/users',
            'rates' => '/exchange-rates',
            'settings' => '/settings',
            default => '/transactions',
        };
    }
}
