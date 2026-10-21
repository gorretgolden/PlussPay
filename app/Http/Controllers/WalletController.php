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
            'movements' => WalletHistory::paginate(WalletHistory::filtered(WalletHistory::lines($user), $request), $request),
            'kinds' => WalletHistory::KINDS,
            'superAgents' => $user->hasRole('agent') && ! $user->hasRole('super-agent')
                && ($user->country ?: User::DEFAULT_COUNTRY) !== 'CN'
                ? User::role('super-agent')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'received' => $user->hasRole('super-agent')
                ? CashHandover::where('super_agent_id', $user->id)
                    ->where('status', CashHandover::APPROVED)
                    ->selectRaw('currency, COALESCE(SUM(amount), 0) as total')
                    ->groupBy('currency')
                    ->pluck('total', 'currency')
                    ->all()
                : [],
            'handovers' => CashHandover::with(['agent:id,name', 'superAgent:id,name'])
                ->where(fn ($query) => $query->where('agent_id', $user->id)->orWhere('super_agent_id', $user->id))
                ->latest('id')
                ->limit(50)
                ->get(),
            'openHandover' => CashHandover::with('superAgent:id,name')
                ->openFor($user)
                ->latest('id')
                ->first(),
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

        return back()->with('success', "{$agent->name}'s wallet {$label} with UGX " . number_format((float) $validated['amount'], 2) . '.');
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
}
