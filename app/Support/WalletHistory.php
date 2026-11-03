<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The drawer's own book: every movement of one person's wallets, each line carrying the balance
 * it left behind. The running figure is walked from the oldest line to the newest per wallet and
 * only then are the lines merged, filtered and paged, because a balance that starts anywhere but
 * the first line is a guess.
 *
 * The screen and the Excel sheet both read from here, so a filter applied on the page is the same
 * filter applied to the download.
 */
final class WalletHistory
{
    /** The kinds a drawer can show, in the order the filter box lists them. */
    public const KINDS = [
        'money_in' => 'Collected',
        'agent_fee' => 'Commission',
        'agent_fee_reversal' => 'Commission given back',
        'transfer_out' => 'Handed in',
        'transfer_in' => 'Received back',
        'handover_out' => 'Handed in',
        'handover_in' => 'Received from an agent',
        'fee_handover_out' => 'Charges handed in',
        'fee_handover_in' => 'Charges received',
        'adjustment_in' => 'Credited by the office',
        'adjustment_out' => 'Debited by the office',
        'reversal' => 'Given back',
        'money_out' => 'Paid out',
    ];

    /**
     * Every line of the person's wallets, newest first.
     *
     * The ledger stores the day a movement belongs to, not the minute, so the clock beside a date
     * is the moment the line was written. A movement back-dated to a day last week then shows that
     * day with this morning's hour, which is the honest pair: the books say when it counts, the
     * clock says when somebody typed it.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function lines(User $user): array
    {
        $lines = [];

        foreach (Account::walletsFor($user) as $wallet) {
            $running = (string) $wallet->opening_balance;
            $own = [];

            $entries = AccountEntry::where('account_id', $wallet->id)
                ->with(['transaction:id,status,receipt_number,created_by', 'creator:id,name'])
                ->orderBy('entry_date')
                ->orderBy('id')
                ->get();

            foreach ($entries as $entry) {
                $running = $entry->direction === 'in'
                    ? bcadd($running, (string) $entry->amount, 2)
                    : bcsub($running, (string) $entry->amount, 2);

                $own[] = [
                    'id' => $entry->id,
                    'date' => $entry->entry_date,
                    'clock' => $entry->created_at,
                    'currency' => $wallet->currency,
                    'type' => $entry->type,
                    'label' => AccountEntry::walletLabel($entry->type, $entry->creator?->name),
                    'description' => $entry->description,
                    'reference' => $entry->reference,
                    'direction' => $entry->direction,
                    'status' => $entry->transaction?->status,
                    'transaction_id' => $entry->transaction_id,
                    'booked_by' => $entry->transaction?->created_by,
                    'in' => $entry->direction === 'in' ? (string) $entry->amount : null,
                    'out' => $entry->direction === 'out' ? (string) $entry->amount : null,
                    'running' => $running,
                ];
            }

            $lines = array_merge($lines, array_reverse($own));
        }

        // Newest first, and the line's own id breaks a tie. Two drawers written in the same second
        // would otherwise land in whichever order the wallets were read, and a page whose balance
        // column jumps about is a page nobody trusts.
        usort($lines, fn ($a, $b) => [
            $b['date']->toDateString(), $b['clock']?->getTimestamp() ?? 0, $b['id'],
        ] <=> [
            $a['date']->toDateString(), $a['clock']?->getTimestamp() ?? 0, $a['id'],
        ]);

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function filtered(array $lines, Request $request): array
    {
        $wallet = strtoupper((string) $request->query('wallet', ''));
        $kind = (string) $request->query('kind', '');
        $direction = (string) $request->query('direction', '');
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        $search = trim((string) $request->query('search', ''));

        return array_values(array_filter($lines, function (array $line) use ($wallet, $kind, $direction, $from, $to, $search) {
            if ($wallet !== '' && $line['currency'] !== $wallet) {
                return false;
            }

            if ($kind !== '' && $line['type'] !== $kind) {
                return false;
            }

            if ($direction !== '' && $line['direction'] !== $direction) {
                return false;
            }

            $day = $line['date']->toDateString();

            if ($from !== '' && $day < $from) {
                return false;
            }

            if ($to !== '' && $day > $to) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            $hay = strtolower($line['reference'].' '.$line['description'].' '.$line['label']);

            return str_contains($hay, strtolower($search));
        }));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public static function paginate(array $lines, Request $request, int $perPage = 25): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage();
        $slice = array_slice($lines, ($page - 1) * $perPage, $perPage);

        return (new LengthAwarePaginator($slice, count($lines), $perPage, $page))
            ->withPath($request->url())
            ->withQueryString();
    }
}
