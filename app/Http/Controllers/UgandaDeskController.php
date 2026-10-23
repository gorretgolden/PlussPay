<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Services\AccountLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The far end of the corridor that starts in China. A desk there books a transfer that promises
 * shillings in Uganda, and the shillings are handed over here, in the receiver's own country. There
 * is no crossing to record first: a booking into Uganda arrives with its money in Uganda, so the
 * queue this page lists is every Uganda booking that has not been paid out yet.
 *
 * The queue is cleared by the same payout pen that clears China's, because one person often runs
 * both ends of the corridor and the log names who marked a receiver paid. The money leaves the
 * office shilling book, which is where a Uganda booking's payout has always sat: no desk was sent
 * anything to hold for it.
 */
class UgandaDeskController extends Controller
{
    public function index(Request $request)
    {
        // The page is one queue and nothing else, so it opens only for the pen that may clear it.
        $viewer = $request->user();

        abort_unless($viewer->can('confirm-china-payout'), 403, 'Only a hand with the payout pen opens the Uganda desk.');

        $inbound = Transaction::awaitingPayoutInUganda()
            ->with('creator')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return view('uganda.index', [
            'inbound' => $inbound,
            'inboundPayouts' => Transaction::sumPayouts($inbound),
        ]);
    }

    /**
     * The shillings finally reach the receiver, out of the office shilling book. That book is
     * guarded like every Uganda account, so a payout the till cannot cover is refused with the
     * shortfall named rather than quietly posted.
     */
    public function payout(Request $request)
    {
        $viewer = $request->user();

        abort_unless($viewer->can('confirm-china-payout'), 403, 'Only a hand with the payout pen confirms a payout.');

        $validated = $request->validate([
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['integer', 'exists:transactions,id'],
            'entry_date' => ['nullable', 'date'],
        ], [
            'transaction_ids.required' => 'Tick the transfers the receiver has been paid for.',
        ]);

        $ids = collect($validated['transaction_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $transactions = Transaction::awaitingPayoutInUganda()
            ->whereIn('id', $ids->all())
            ->lockForUpdate()
            ->orderBy('id')
            ->get();

        if ($transactions->count() !== $ids->count()) {
            return back()->withErrors([
                'transaction_ids' => $this->staleSelection($ids, $transactions),
            ]);
        }

        $account = Account::byCode(Account::UGX_UGANDA);
        $paid = collect();

        try {
            DB::transaction(function () use ($transactions, $account, $validated, $paid) {
                foreach ($transactions as $transaction) {
                    AccountLedger::payOutReceiver(
                        $transaction,
                        $account,
                        $validated['entry_date'] ?? null,
                    );

                    $paid->push($transaction->receipt_number);
                }
            });
        } catch (InsufficientFundsException $e) {
            return back()->withErrors(['transaction_ids' => $e->getMessage()])->withInput();
        }

        $figures = Transaction::formatPayouts(Transaction::sumPayouts($transactions));

        ActivityLog::create([
            'user_id' => $viewer->id,
            'action' => 'uganda_payout_recorded',
            'description' => 'Paid '.$figures.' to the receivers of '.$paid->count()
                .' transfer'.($paid->count() === 1 ? '' : 's').' in Uganda',
            'new_value' => json_encode([
                'amount_paid' => $figures,
                'receipts' => $paid->implode(', '),
            ]),
        ]);

        return redirect()->route('uganda.index')->with('success', $figures
            .' paid in Uganda for '.implode(', ', $paid->all())
            .'. '.($paid->count() === 1 ? 'That transfer now reads Paid.' : 'Those transfers now read Paid.'));
    }

    /**
     * Names the receipts that can no longer be moved rather than refusing the batch in general
     * terms, because the clerk needs to know whether it was a colleague's click or a stale page.
     * Only rows this queue lists are named, and the rest are counted: a hand-typed list of ids
     * cannot be used to read out another queue's receipt numbers.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $ids
     * @param  \Illuminate\Support\Collection<int, Transaction>  $found
     */
    private function staleSelection($ids, $found): string
    {
        $missing = $ids->diff($found->pluck('id'))->values();
        $named = Transaction::whereIn('id', $missing->all())->pluck('receipt_number');
        $hidden = $missing->count() - $named->count();

        $parts = [];

        if ($named->isNotEmpty()) {
            $parts[] = 'These transfers can no longer be moved in one go: '.$named->implode(', ');
        }

        if ($hidden > 0) {
            $parts[] = $hidden.' of the ticks are not transfers this queue lists';
        }

        return implode('. ', $parts).'. Someone else has already paid them, or they are no longer'
            .' completed. Reload the page and tick them again.';
    }
}
