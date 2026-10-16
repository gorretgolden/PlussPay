<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\AccountLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WalletController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->can('view-all-wallets')) {
            $agents = User::role('agent')->where('is_active', true)->get();
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

            $handedIn = AccountEntry::handedInFor($every);

            foreach ($allWallets as $agentId => $data) {
                $allWallets[$agentId]['handed_in'] = [];

                foreach ($data['wallets'] as $currency => $wallet) {
                    $allWallets[$agentId]['handed_in'][$currency] = $handedIn[$wallet->id] ?? '0.00';
                }
            }

            return view('wallets.index', compact('allWallets'));
        }

        $wallets = Account::walletsFor($user);
        $held = collect(array_values($wallets));

        return view('wallets.my', [
            'wallets' => $wallets,
            'handedIn' => AccountEntry::handedInFor($wallets),
            'commission' => $this->commissionTotals($held),
            'movements' => $this->movements($user),
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

        if (! $agent->hasRole('agent')) {
            return back()->withErrors(['agent_id' => 'That person is not an agent.']);
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

        return back()->with('success', "{$agent->name}'s wallet {$label} with UGX " . number_format((float) $validated['amount'], 2) . '.');
    }

    /**
     * Collected money only becomes office money when it is carried in, so this is the movement she
     * makes standing at the counter with the cash in her hand. What the office gains, the drawer
     * loses, in one currency and one figure, which is why the amount can never be more than the
     * wallet actually holds: the ledger refuses it and says what is really there.
     */
    public function handIn(Request $request)
    {
        $viewer = $request->user();

        $validated = $request->validate([
            'agent_id' => 'nullable|integer|exists:users,id',
            'currency' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ], [
            'currency.required' => 'Choose which money you are handing in.',
            'amount.required' => 'Enter the amount you are handing in.',
            'amount.min' => 'Enter an amount greater than zero.',
        ]);

        $agent = $viewer;

        if (! empty($validated['agent_id'])) {
            if (! $viewer->can('view-all-wallets')) {
                return back()->withErrors(['agent_id' => 'You cannot hand in cash for another person.']);
            }

            $agent = User::findOrFail($validated['agent_id']);
        }

        if (! $agent->hasRole('agent')) {
            return back()->withErrors(['agent_id' => 'Only an agent holds collected cash.']);
        }

        $currency = strtoupper($validated['currency']);

        if (! in_array($currency, Account::currenciesFor($agent), true)) {
            return back()->withErrors(['currency' => "This desk does not hold {$currency}."]);
        }

        $wallet = Account::walletFor($agent, $currency);
        $before = $wallet->balance();

        $note = $request->input('note');

        try {
            AccountLedger::handInCash($agent, $currency, (string) $validated['amount'], $note ?: null);
        } catch (InsufficientFundsException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        $figure = Account::format($currency, $validated['amount']);

        ActivityLog::create([
            'user_id' => $viewer->id,
            ...ActivityLog::subjectAttributes($wallet),
            'action' => 'cash_handed_in',
            'description' => ($agent->is($viewer) ? $agent->name : $agent->name.' (recorded by '.$viewer->name.')')
                .' handed in '.$figure,
            'old_value' => json_encode(['balance' => $before]),
            'new_value' => json_encode([
                'balance' => $wallet->balance(),
                'amount' => $figure,
                'currency' => $currency,
                'agent' => $agent->name,
                'note' => $note,
            ]),
        ]);

        return back()->with('success', $figure.' handed in to the office. '.$agent->name.' now holds '.Account::format($currency, $wallet->balance()).'.');
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

        return [$shilling->id => bcsub($earned, $given, 2)];
    }

    /**
     * Every movement of this person's own cash, newest first, each line carrying the balance it
     * left behind. Typed out by hand from the ledger in date order and then run backwards, because
     * a running figure only means something when it walks from the oldest line to the newest.
     *
     * @return array<int, array<string, mixed>>
     */
    private function movements(User $user, int $limit = 40): array
    {
        $wallets = array_values(Account::walletsFor($user));

        if ($wallets === []) {
            return [];
        }

        $byWallet = [];

        foreach ($wallets as $wallet) {
            $running = (string) $wallet->opening_balance;
            $lines = [];

            $entries = AccountEntry::where('account_id', $wallet->id)
                ->orderBy('entry_date')
                ->orderBy('id')
                ->get();

            foreach ($entries as $entry) {
                $running = $entry->direction === 'in'
                    ? bcadd($running, (string) $entry->amount, 2)
                    : bcsub($running, (string) $entry->amount, 2);

                $lines[] = [
                    'date' => $entry->entry_date,
                    'currency' => $wallet->currency,
                    'label' => AccountEntry::walletLabel($entry->type),
                    'description' => $entry->description,
                    'reference' => $entry->reference,
                    'in' => $entry->direction === 'in' ? (string) $entry->amount : null,
                    'out' => $entry->direction === 'out' ? (string) $entry->amount : null,
                    'running' => $running,
                ];
            }

            $byWallet = array_merge($byWallet, array_reverse($lines));
        }

        usort($byWallet, fn ($a, $b) => strcmp($b['date']->toDateString(), $a['date']->toDateString()));

        return array_slice($byWallet, 0, $limit);
    }
}
