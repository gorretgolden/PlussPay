<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\CashHandover;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\WalletHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        if ($user->can('view-all-wallets')) {
            $agents = User::role(['agent', 'super-agent'])->where('is_active', true)->get();
            $allWallets = [];

            $every = [];

            foreach ($agents as $agent) {
                $wallets = Account::walletsFor($agent);
                $every = array_merge($every, array_values($wallets));
                $allWallets[$agent->id] = [
                    'user' => $agent,
                    'wallets' => $wallets,
                ];
            }

            // Anyone else who still owns a wallet (a blocked agent, a changed role) stays on the
            // list so the cash in their hand never drops out of sight. Existing wallets only:
            // nothing is created for people who are not on the desk.
            Account::agentWallets()->with('owner')->whereNotIn('owner_id', $agents->pluck('id'))->get()
                ->filter(fn (Account $wallet) => $wallet->owner !== null)
                ->groupBy('owner_id')
                ->filter(fn ($wallets) => $wallets->contains(fn (Account $w) => bccomp($w->balance(), '0', 2) !== 0))
                ->each(function ($wallets, $ownerId) use (&$allWallets, &$every) {
                    $keyed = $wallets->keyBy('currency')->all();
                    $every = array_merge($every, array_values($keyed));
                    $allWallets[$ownerId] = [
                        'user' => $wallets->first()->owner,
                        'wallets' => $keyed,
                    ];
                });

            $handedIn = AccountEntry::handedInFor($every);

            foreach ($allWallets as $agentId => $data) {
                $allWallets[$agentId]['handed_in'] = [];

                foreach ($data['wallets'] as $currency => $wallet) {
                    $allWallets[$agentId]['handed_in'][$currency] = $handedIn[$wallet->id] ?? '0.00';
                }
            }

            $totals = [];
            foreach ($every as $wallet) {
                $totals[$wallet->currency] = bcadd($totals[$wallet->currency] ?? '0.00', $wallet->balance(), 2);
            }

            // Sort by the most recent entry across all of a person's wallets, so the people who
            // moved money lately sit at the top of the table.
            $latestEntry = AccountEntry::whereIn('account_id', collect($every)->pluck('id'))
                ->selectRaw('account_id, MAX(entry_date) as latest')
                ->groupBy('account_id')
                ->pluck('latest', 'account_id');

            uasort($allWallets, function ($a, $b) use ($latestEntry) {
                $aLatest = collect($a['wallets'])->pluck('id')->map(fn ($id) => $latestEntry[$id] ?? null)->filter()->max();
                $bLatest = collect($b['wallets'])->pluck('id')->map(fn ($id) => $latestEntry[$id] ?? null)->filter()->max();
                if ($aLatest === null && $bLatest === null) { return 0; }
                if ($aLatest === null) { return 1; }
                if ($bLatest === null) { return -1; }
                return $bLatest <=> $aLatest;
            });

            return view('wallets.index', [
                'allWallets' => $allWallets,
                'totals' => $totals,
                // Only people still on the desk can be adjusted; the controller refuses the rest.
                'adjustable' => $agents,
            ]);
        }

        $wallets = Account::walletsFor($user);
        $held = collect(array_values($wallets));

        return view('wallets.my', [
            'wallets' => $wallets,
            'handedIn' => AccountEntry::handedInFor($wallets),
            'commission' => $this->commissionTotals($held),
            'movements' => WalletHistory::paginate(WalletHistory::filtered(WalletHistory::lines($user), $request), $request),
            'kinds' => WalletHistory::KINDS,
            'received' => $user->hasRole('super-agent')
                ? CashHandover::where('super_agent_id', $user->id)
                    ->where('status', CashHandover::APPROVED)
                    ->selectRaw('currency, COALESCE(SUM(amount), 0) as total')
                    ->groupBy('currency')
                    ->pluck('total', 'currency')
                    ->all()
                : [],
            // The charges the agents handed up, which sit in the shilling wallet beside his own
            // commission and are the pile that never goes on to China. Counted off the ledger rather
            // than the requests so the figure is the money the wallet actually received, and less any
            // he has since handed further up, because those are no longer in his hand.
            'chargesKept' => $user->hasRole('super-agent')
                ? bcsub(
                    (string) AccountEntry::where('account_id', $wallets['UGX']->id ?? null)->where('type', 'fee_handover_in')->sum('amount'),
                    (string) AccountEntry::where('account_id', $wallets['UGX']->id ?? null)->where('type', 'fee_handover_out')->sum('amount'),
                    2,
                )
                : '0.00',
            'handovers' => CashHandover::with(['agent:id,name', 'superAgent:id,name', 'transactions:id,cash_handover_id,receipt_number,amount,currency,charge_ugx'])
                ->where(fn ($query) => $query->where('agent_id', $user->id)->orWhere('super_agent_id', $user->id))
                ->latest('id')
                ->limit(50)
                ->get(),
        ]);
    }

    public function storeAdjustment(Request $request)
    {
        $validated = $request->validate([
            'agent_id' => 'required|integer|exists:users,id',
            'direction' => 'required|in:credit,debit',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
        ], [
            'agent_id.required' => 'Choose which agent.',
            'amount.min' => 'The amount must be greater than zero.',
            'description.required' => 'Say what this adjustment is for.',
        ]);

        $agent = User::findOrFail($validated['agent_id']);

        if (! $agent->is_active || ! $agent->hasAnyRole(['agent', 'super-agent'])) {
            return back()->withErrors(['agent_id' => 'Choose an active agent or lead agent.']);
        }

        $wallet = Account::walletFor($agent, 'UGX');
        $type = $validated['direction'] === 'credit' ? 'adjustment_in' : 'adjustment_out';
        $before = $wallet->balance();

        try {
            AccountLedger::record(
                $wallet,
                $type,
                (string) $validated['amount'],
                $validated['description'],
            );
        } catch (InsufficientFundsException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            ...ActivityLog::subjectAttributes($wallet),
            'action' => 'wallet_adjusted',
            'description' => $validated['description'],
            'old_value' => json_encode(['balance' => $before]),
            'new_value' => json_encode([
                'balance' => $wallet->balance(),
                'movement' => $type === 'adjustment_in' ? 'Credit' : 'Debit',
                'amount' => number_format((float) $validated['amount'], 2),
                'agent' => $agent->name,
            ]),
        ]);

        $label = $validated['direction'] === 'credit' ? 'credited' : 'debited';

        // The confirmation names the agent who made the movement, because the line on the drawer's
        // own book says "Credited by <name>" and the two have to agree about whose hand moved it.
        // Shillings are counted in notes, so the figure is whole rather than cut at the fen.
        return back()->with('success', auth()->user()->name." {$label} {$agent->name}'s wallet with UGX ".number_format((float) $validated['amount']).'.');
    }

    /**
     * Commission is what the collector keeps out of what they collected, so it lives inside the
     * shilling balance rather than beside it. Shown as its own figure because she needs to be able
     * to split the drawer into office money and her own pay.
     *
     * @param  Collection<int, Account>  $wallets
     * @return array<int, string>
     */
    private function commissionTotals(Collection $wallets): array
    {
        $shilling = $wallets->firstWhere('currency', 'UGX');

        if (! $shilling) {
            return [];
        }

        $earned = (string) AccountEntry::where('account_id', $shilling->id)->where('type', 'agent_fee')->sum('amount');
        $given = (string) AccountEntry::where('account_id', $shilling->id)->where('type', 'agent_fee_reversal')->sum('amount');
        // Charges handed up with the cash are no longer kept: they went over to the lead's drawer in
        // the same hand that took the principal, so counting them here would pay them twice.
        $handedUp = (string) AccountEntry::where('account_id', $shilling->id)->where('type', 'fee_handover_out')->sum('amount');

        return [$shilling->id => bcsub(bcsub($earned, $given, 2), $handedUp, 2)];
    }
}
