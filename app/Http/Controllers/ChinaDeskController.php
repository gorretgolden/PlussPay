<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The second half of the corridor. Uganda collects shillings or dollars and books a transfer that
 * promises yuan in China, but the yuan is not paid until the dollars that transfer is worth have
 * been sent across and the desk there has changed them and handed them over. This is where those
 * two movements are recorded, because until now the book simply assumed money teleported.
 *
 * Every figure here is the ledger's own: the send is a transfer between two dollar accounts and the
 * payout is one line out of a yuan account, so the cash the two desks hold and the yuan the
 * receiver is owed are always the same story.
 */
class ChinaDeskController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $request->user();
        [$canSend, $canPay] = $this->sides($viewer);

        return view('china.index', [
            'outbound' => $canSend ? $this->awaitingSend($viewer) : collect(),
            'inbound' => $canPay ? $this->awaitingPayout($viewer) : collect(),
            'paid' => $canPay ? $this->paidTotal($viewer) : null,
            'canSend' => $canSend,
            'canPay' => $canPay,
            'sources' => $this->sendableAccounts($viewer),
            'desks' => $this->chinaDesks(),
            'deskBook' => $viewer->can('view-all-wallets'),
            'usdWallet' => Account::where('owner_id', $viewer->id)->where('currency', 'USD')->first(),
            'rmbWallet' => Account::where('owner_id', $viewer->id)->where('currency', 'RMB')->first(),
        ]);
    }

    /**
     * Which half of the corridor a person is shown. Both desk roles are granted the two settlement
     * abilities so a duty shift can cover either side, but an agent in Kampala is never asked to
     * confirm a payout they cannot make, and a desk in China is never offered dollars that are not
     * theirs to send. Anyone who may see the whole book can work either side.
     *
     * @return array{0: bool, 1: bool} may send, may pay out
     */
    private function sides(User $viewer): array
    {
        $inChina = ($viewer->country ?: User::DEFAULT_COUNTRY) === 'CN';

        return [
            ! $inChina && $viewer->can('send-to-china'),
            $viewer->can('confirm-china-payout') && ($inChina || $viewer->can('view-all-transactions')),
        ];
    }

    /**
     * A batch of bookings leaves the Uganda side in one movement: the dollars each transfer is
     * worth go from the chosen account into the chosen desk's own dollar wallet, and each booking
     * is stamped as sent in the same transaction. Nothing is half-written; if one line cannot be
     * posted, the whole batch stays where it was, because a settlement split down the middle would
     * leave the two desks disagreeing about who holds the money.
     */
    public function send(Request $request)
    {
        $viewer = $request->user();

        // The screen hides this side from a desk in China, and the click is refused for the same
        // reason: a person already in China has no dollars of the office's to forward.
        abort_unless($this->sides($viewer)[0], 403, 'Only a desk in Uganda sends dollars to China.');

        $validated = $request->validate([
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['integer', 'exists:transactions,id'],
            'recipient_id' => ['required', 'integer', Rule::exists('users', 'id')->where('country', 'CN')->where('is_active', true)],
            'source_account_id' => ['required', 'integer', Rule::in(collect($this->sendableAccounts($viewer))->pluck('id')->all())],
            'entry_date' => ['nullable', 'date'],
        ], [
            'transaction_ids.required' => 'Tick the transfers whose dollars are going to China.',
            'recipient_id.required' => 'Choose which desk in China is receiving the money.',
            'recipient_id.exists' => 'That person is not an active desk in China. Pick someone whose country is China.',
            'source_account_id.required' => 'Choose which dollar account the money leaves from.',
            'source_account_id.in' => 'That account is not one of your dollar accounts or the office dollar book.',
        ]);

        $recipient = User::findOrFail($validated['recipient_id']);
        $source = Account::findOrFail($validated['source_account_id']);
        $ids = collect($validated['transaction_ids'])->map(fn ($id) => (int) $id)->unique()->values();

        $transactions = $this->sendable($viewer, $ids);

        if ($transactions->count() !== $ids->count()) {
            return back()->withErrors([
                'transaction_ids' => $this->staleSelection($ids, $transactions, fn ($q) => $q->visibleTo($viewer)),
            ]);
        }

        $total = $transactions->sum(fn (Transaction $t) => (float) $t->total_usd_equivalent);

        if (bccomp(number_format($total, 2, '.', ''), (string) $source->balance(), 2) > 0) {
            return back()->withErrors([
                'source_account_id' => $source->name.' holds '.Account::format('USD', $source->balance())
                    .', but these '.$transactions->count().' transfers need '
                    .Account::format('USD', $total)
                    .'. Buy the dollars at the currency exchange desk first, or send fewer transfers.',
            ])->withInput();
        }

        try {
            DB::transaction(function () use ($transactions, $source, $recipient, $validated) {
                foreach ($transactions as $transaction) {
                    AccountLedger::sendToChina(
                        $transaction,
                        $source,
                        $recipient,
                        $validated['entry_date'] ?? null,
                    );
                }
            });
        } catch (InsufficientFundsException $e) {
            return back()->withErrors(['source_account_id' => $e->getMessage()])->withInput();
        }

        $figures = Account::format('USD', $total);

        ActivityLog::create([
            'user_id' => $viewer->id,
            'action' => 'sent_to_china',
            'description' => 'Sent '.$figures.' for '.$transactions->count()
                .' transfer'.($transactions->count() === 1 ? '' : 's').' to '.$recipient->name.' in China'
                .' out of '.$source->name,
            'old_value' => json_encode(['source_balance' => bcadd((string) $source->balance(), $total, 2)]),
            'new_value' => json_encode([
                'source_balance' => $source->balance(),
                'recipient' => $recipient->name,
                'amount_usd' => $figures,
                'receipts' => $transactions->pluck('receipt_number')->implode(', '),
            ]),
        ]);

        return redirect()->route('china.index')->with('success', $figures.' sent to '.$recipient->name
            .' in China for '.$transactions->count().' transfer'.($transactions->count() === 1 ? '' : 's')
            .'. Their dollar wallet now holds '.Account::format('USD', Account::walletFor($recipient, 'USD')->balance())
            .'.');
    }

    /**
     * The yuan finally reaches the receiver, out of the yuan wallet of the very desk the dollars
     * were sent to. That side is allowed to pay before Uganda's dollars have been changed, so the
     * wallet can run below zero and the screen says so rather than blocking a real payment.
     */
    public function payout(Request $request)
    {
        $viewer = $request->user();

        // Confirming a payout is claiming the receiver was handed their yuan in China, so only the
        // desk there, or the office that sees the whole book, may say it.
        abort_unless($this->sides($viewer)[1], 403, 'Only the desk in China confirms a payout.');

        $validated = $request->validate([
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['integer', 'exists:transactions,id'],
            'entry_date' => ['nullable', 'date'],
        ], [
            'transaction_ids.required' => 'Tick the transfers the receiver has been paid for.',
        ]);

        $ids = collect($validated['transaction_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $transactions = $this->payable($viewer, $ids);

        if ($transactions->count() !== $ids->count()) {
            return back()->withErrors([
                'transaction_ids' => $this->staleSelection($ids, $transactions,
                    fn ($q) => $q->when(! $viewer->can('view-all-transactions'), fn ($rows) => $rows->where('settled_to', $viewer->id))),
            ]);
        }

        $paid = collect();

        DB::transaction(function () use ($transactions, $validated, $paid) {
            foreach ($transactions as $transaction) {
                $account = $this->payoutAccount($transaction);

                AccountLedger::payOutInChina(
                    $transaction,
                    $account,
                    $validated['entry_date'] ?? null,
                );

                $paid->push($transaction->receipt_number);
            }
        });

        $yuan = $transactions->sum(fn (Transaction $t) => (float) $t->rmb_amount);

        ActivityLog::create([
            'user_id' => $viewer->id,
            'action' => 'china_payout_recorded',
            'description' => 'Paid '.Account::format('RMB', $yuan).' to the receivers of '.$paid->count()
                .' transfer'.($paid->count() === 1 ? '' : 's').' in China',
            'new_value' => json_encode([
                'amount_rmb' => Account::format('RMB', $yuan),
                'receipts' => $paid->implode(', '),
            ]),
        ]);

        return redirect()->route('china.index')->with('success', Account::format('RMB', $yuan)
            .' paid out in China for '.implode(', ', $paid->all())
            .'. Those transfers are closed on both sides of the corridor.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, Transaction>
     */
    private function awaitingSend(User $viewer)
    {
        return Transaction::visibleTo($viewer)
            ->awaitingSendToChina()
            ->with('creator')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Transaction>
     */
    private function awaitingPayout(User $viewer)
    {
        return Transaction::awaitingPayoutInChina()
            ->when(! $viewer->can('view-all-transactions'), fn ($q) => $q->where('settled_to', $viewer->id))
            ->with(['settledDesk', 'creator'])
            ->orderBy('settled_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * How much has actually finished its journey. Read on the same visibility rule as the payout
     * list above it, so the three figures on the page are one story: what is still in Uganda, what
     * is in China waiting, and what has been paid, with no overlap and no fourth state.
     *
     * @return array{count: int, rmb: string}
     */
    private function paidTotal(User $viewer): array
    {
        $rows = Transaction::where('status', 'completed')
            ->where('rmb_amount', '>', 0)
            ->whereNotNull('delivered_at')
            ->when(! $viewer->can('view-all-transactions'), fn ($q) => $q->where('settled_to', $viewer->id))
            ->get(['id', 'rmb_amount']);

        return ['count' => $rows->count(), 'rmb' => (string) $rows->sum('rmb_amount')];
    }

    /**
     * Which dollar accounts may pay for a send. A desk's own wallet is always theirs to spend, and
     * the office book joins it for whoever is allowed to see the office accounts, because that till
     * belongs to the business rather than to the person standing at the counter.
     *
     * @return array<int, Account>
     */
    private function sendableAccounts(User $viewer): array
    {
        $accounts = Account::where('owner_id', $viewer->id)
            ->where('currency', 'USD')
            ->where('is_active', true)
            ->get()
            ->all();

        if ($viewer->can('view-accounts')) {
            $accounts[] = Account::byCode(Account::USD_UGANDA);
        }

        return $accounts;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function chinaDesks()
    {
        return User::where('country', 'CN')
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->can('confirm-china-payout'))
            ->values();
    }

    /**
     * The rows this sender may still send, read under a lock so two desks ticking the same
     * transfers at the same moment cannot both send them. A booking that stopped qualifying
     * between the page load and this click is simply not returned, and the caller is told which.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, Transaction>
     */
    private function sendable(User $viewer, $ids)
    {
        return Transaction::visibleTo($viewer)
            ->awaitingSendToChina()
            ->whereIn('id', $ids->all())
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, Transaction>
     */
    private function payable(User $viewer, $ids)
    {
        return Transaction::awaitingPayoutInChina()
            ->when(! $viewer->can('view-all-transactions'), fn ($q) => $q->where('settled_to', $viewer->id))
            ->whereIn('id', $ids->all())
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }

    /**
     * The yuan for a payout comes from the wallet of the desk that was actually sent the dollars,
     * so the corridor never mixes one agent's float with another's. A desk that has since been
     * deleted falls back to the company yuan book, which keeps the receiver's payment recordable.
     */
    private function payoutAccount(Transaction $transaction): Account
    {
        $desk = $transaction->settledDesk ?? ($transaction->settled_to ? User::find($transaction->settled_to) : null);

        if ($desk) {
            return Account::walletFor($desk, 'RMB');
        }

        return Account::byCode(Account::RMB_CHINA);
    }

    /**
     * Names the receipts that can no longer be moved rather than refusing the batch in general
     * terms, because the clerk needs to know whether it was a colleague's click or a stale page.
     * Only rows this side already lists are named, and the rest are counted: a hand-typed list of
     * ids cannot be used to read out another desk's receipt numbers.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $ids
     * @param  \Illuminate\Support\Collection<int, Transaction>  $found
     * @param  callable(\Illuminate\Database\Eloquent\Builder): mixed  $reach  the visibility the page itself lists by
     */
    private function staleSelection($ids, $found, callable $reach): string
    {
        $missing = $ids->diff($found->pluck('id'))->values();
        $named = $reach(Transaction::query())->whereIn('id', $missing->all())->pluck('receipt_number');
        $hidden = $missing->count() - $named->count();

        $parts = [];

        if ($named->isNotEmpty()) {
            $parts[] = 'These transfers can no longer be moved in one go: '.$named->implode(', ');
        }

        if ($hidden > 0) {
            $parts[] = $hidden.' of the ticks are not transfers this desk lists';
        }

        return implode('. ', $parts).'. Someone else has already sent or paid them, or they are no longer'
            .' completed. Reload the page and tick them again.';
    }
}
