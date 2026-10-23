<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CashHandover;
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

        // The two lists are read once here because each side's money card and its table are the same
        // rows, and a card that re-queried them could disagree with the list under it.
        $outbound = $canSend ? $this->awaitingSend($viewer) : collect();
        $inbound = $canPay ? $this->awaitingPayout($viewer) : collect();

        return view('china.index', [
            'outbound' => $outbound,
            'inbound' => $inbound,
            'outboundPayouts' => Transaction::sumPayouts($outbound),
            'inboundPayouts' => Transaction::sumPayouts($inbound),
            // The two figures the sending desk has to keep apart: the charges these transfers earned
            // are money that stays in Uganda, and the ones still named on an open hand-in cannot be
            // ticked until that request is closed.
            'outboundCharges' => $outbound->reduce(
                fn (string $carry, Transaction $t) => $t->isToUganda()
                    ? $carry
                    : bcadd($carry, (string) $t->charge_ugx, 2),
                '0.00',
            ),
            'outboundHeldBack' => $outbound->filter(fn (Transaction $t) => $t->hand_in_status === CashHandover::PENDING)->count(),
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
     * Which half of the corridor a person is shown. Sending keeps its desk rule: dollars leave only
     * from Uganda, and a desk in China is never offered dollars that are not theirs to send. The
     * payout side is the payout pen alone: one person often runs both ends of the corridor, so any
     * hand holding confirm-china-payout sees the whole waiting list and may clear it, wherever its
     * desk stands. The log still names who marked a receiver paid.
     *
     * @return array{0: bool, 1: bool} may send, may pay out
     */
    private function sides(User $viewer): array
    {
        $inChina = ($viewer->country ?: User::DEFAULT_COUNTRY) === 'CN';

        return [
            ! $inChina && $viewer->can('send-to-china'),
            $viewer->can('confirm-china-payout'),
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
            // The dropdown only offers desks that can pay receivers, so the click has to hold to
            // the same list: dollars sent to anyone else would land in a queue no one can clear.
            'recipient_id' => ['required', 'integer', Rule::in($this->chinaDesks()->pluck('id')->all())],
            'source_account_id' => ['required', 'integer', Rule::in(collect($this->sendableAccounts($viewer))->pluck('id')->all())],
            'entry_date' => ['nullable', 'date'],
        ], [
            'transaction_ids.required' => 'Tick the transfers whose dollars are going to China.',
            'recipient_id.required' => 'Choose which desk in China is receiving the money.',
            'recipient_id.in' => 'That person cannot pay receivers in China yet. Pick a desk that handles payouts.',
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

        // A booking named on a hand-in that has not been approved yet is cash somebody else is still
        // answerable for, so it cannot cross while that request is open. The desk is told which pen
        // closes it rather than being left with a refusal in general terms.
        $heldBack = $transactions->filter(fn (Transaction $t) => $t->hand_in_status === CashHandover::PENDING);

        if ($heldBack->isNotEmpty()) {
            return back()->withErrors([
                'transaction_ids' => 'These transfers are named on a hand-in that is still waiting to be approved: '
                    .$heldBack->pluck('receipt_number')->implode(', ').'. Approve the hand-in first, or cancel it.',
            ])->withInput();
        }

        // The charges those transfers earned never cross with them: the money a receiver is paid is
        // what the booking is worth, and the commission stays in the hand that collected it.
        $charges = $transactions->reduce(
            fn (string $carry, Transaction $t) => $t->isToUganda()
                ? $carry
                : bcadd($carry, (string) $t->charge_ugx, 2),
            '0.00',
        );

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
                'charges_left_behind_ugx' => $charges,
                'receipts' => $transactions->pluck('receipt_number')->implode(', '),
            ]),
        ]);

        return redirect()->route('china.index')->with('success', $figures.' sent to '.$recipient->name
            .' in China for '.$transactions->count().' transfer'.($transactions->count() === 1 ? '' : 's')
            .'. Their dollar wallet now holds '.Account::format('USD', Account::walletFor($recipient, 'USD')->balance())
            .'.'
            .(bccomp($charges, '0.00', 2) > 0
                ? ' The UGX '.number_format((float) $charges).' those transfers charged did not cross; it stays with the desk that collected it.'
                : ''));
    }

    /**
     * The yuan finally reaches the receiver, out of the yuan wallet of the very desk the dollars
     * were sent to. That side is allowed to pay before Uganda's dollars have been changed, so the
     * wallet can run below zero and the screen says so rather than blocking a real payment.
     */
    public function payout(Request $request)
    {
        $viewer = $request->user();

        // Confirming a payout is claiming the receiver was handed their money in China, so only a
        // hand holding the payout pen may say it.
        abort_unless($this->sides($viewer)[1], 403, 'Only a hand with the payout pen confirms a payout.');

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
                'transaction_ids' => $this->staleSelection($ids, $transactions, fn ($q) => $q),
            ]);
        }

        $paid = collect();

        DB::transaction(function () use ($transactions, $validated, $paid) {
            foreach ($transactions as $transaction) {
                $account = $this->payoutAccount($transaction);

                AccountLedger::payOutReceiver(
                    $transaction,
                    $account,
                    $validated['entry_date'] ?? null,
                );

                $paid->push($transaction->receipt_number);
            }
        });

        $figures = Transaction::formatPayouts(Transaction::sumPayouts($transactions));

        ActivityLog::create([
            'user_id' => $viewer->id,
            'action' => 'china_payout_recorded',
            'description' => 'Paid '.$figures.' to the receivers of '.$paid->count()
                .' transfer'.($paid->count() === 1 ? '' : 's').' in China',
            'new_value' => json_encode([
                'amount_paid' => $figures,
                'receipts' => $paid->implode(', '),
            ]),
        ]);

        return redirect()->route('china.index')->with('success', $figures
            .' paid in China for '.implode(', ', $paid->all())
            .'. '.($paid->count() === 1 ? 'That transfer now reads Paid.' : 'Those transfers now read Paid.'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, Transaction>
     */
    private function awaitingSend(User $viewer)
    {
        return Transaction::visibleTo($viewer)
            ->awaitingSendToChina()
            ->with(['creator', 'cashHandover:id,reference,status,super_agent_id'])
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
     * The paid money is totalled per currency rather than as one number, because a receiver paid in
     * yuan and one paid in dollars are two different piles of the book and adding them together
     * would invent a third currency nobody holds.
     *
     * @return array{count: int, payouts: array<string, string>}
     */
    private function paidTotal(User $viewer): array
    {
        $rows = Transaction::where('status', 'completed')
            ->whereNotNull('delivered_at')
            ->owingPayout()
            ->get(['id', 'rmb_amount', 'total_usd_equivalent', 'ugx_amount', 'payout_currency', 'destination']);

        return [
            'count' => $rows->count(),
            'payouts' => Transaction::sumPayouts($rows),
        ];
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
            ->whereIn('id', $ids->all())
            ->lockForUpdate()
            ->orderBy('id')
            ->get();
    }

    /**
     * The money for a payout comes from the wallet, in the money the receiver was promised, of the
     * desk that was actually sent it, so the corridor never mixes one agent's float with another's.
     * A receiver paid in dollars is paid out of the dollars that crossed, which is why that side
     * needs no exchange at all. A desk that has since been deleted falls back to the company book of
     * that currency, which keeps the receiver's payment recordable.
     */
    private function payoutAccount(Transaction $transaction): Account
    {
        $currency = $transaction->payoutCurrency();
        $desk = $transaction->settledDesk ?? ($transaction->settled_to ? User::find($transaction->settled_to) : null);

        if ($desk) {
            return Account::walletFor($desk, $currency);
        }

        return Account::byCode(match ($currency) {
            'USD' => Account::USD_CHINA,
            default => Account::RMB_CHINA,
        });
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
