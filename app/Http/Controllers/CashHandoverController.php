<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CashHandover;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The link between a minor agent and the super agent who sends the money on. The agent asks, the
 * super agent takes the cash in hand; only then do the two wallets move. Until that moment the
 * books still say the cash is in the agent's drawer, because it is.
 */
class CashHandoverController extends Controller
{
    public function store(Request $request)
    {
        $viewer = $request->user();

        if (! $viewer->hasRole('agent') || $viewer->hasRole('super-agent')) {
            return back()->withErrors(['super_agent_id' => 'Only an agent hands cash in to a super agent.']);
        }

        $validated = $request->validate([
            'super_agent_id' => 'required|integer',
            'currency' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:255',
        ], [
            'super_agent_id.required' => 'Choose the super agent you are handing the cash to.',
            'currency.required' => 'Choose which wallet the cash is coming from.',
            'amount.required' => 'Enter the amount you are handing in.',
            'amount.min' => 'Enter an amount greater than zero.',
        ]);

        $superAgent = User::find($validated['super_agent_id']);

        if (! $superAgent || ! $superAgent->hasRole('super-agent') || ! $superAgent->is_active) {
            return back()->withErrors(['super_agent_id' => 'Choose an active super agent.'])->withInput();
        }

        $currency = strtoupper($validated['currency']);

        if (! in_array($currency, Account::currenciesFor($viewer), true)) {
            return back()->withErrors(['currency' => "Your desk does not hold {$currency}."])->withInput();
        }

        if (! in_array($currency, Account::currenciesFor($superAgent), true)) {
            return back()->withErrors([
                'super_agent_id' => $superAgent->name.' does not hold a '.$currency.' wallet.',
            ])->withInput();
        }

        $wallet = Account::walletFor($viewer, $currency);
        $balance = $wallet->balance();

        // The request is typed against the drawer in front of them, so the most anybody can ask
        // to hand in is what the wallet actually holds at that moment.
        if (bccomp((string) $validated['amount'], (string) $balance, 2) > 0) {
            return back()->withErrors([
                'amount' => 'You cannot hand in more than the wallet holds. The '.$currency.' wallet holds '
                    .Account::format($currency, $balance).'.',
            ])->withInput();
        }

        $handover = CashHandover::request(
            $viewer,
            $superAgent,
            $currency,
            (string) $validated['amount'],
            $request->input('note') ?: null,
        );

        ActivityLog::create([
            'user_id' => $viewer->id,
            ...ActivityLog::subjectAttributes($handover),
            'action' => 'cash_handover_requested',
            'description' => $viewer->name.' asked '.$superAgent->name.' to take '.$handover->figure()
                .' ('.$handover->reference.').',
            'new_value' => json_encode([
                'reference' => $handover->reference,
                'amount' => $handover->figure(),
                'currency' => $currency,
                'agent' => $viewer->name,
                'super_agent' => $superAgent->name,
                'note' => $handover->note,
            ]),
        ]);

        return back()->with('success', 'Hand-in of '.$handover->figure().' sent to '.$superAgent->name
            .' for approval. Reference '.$handover->reference.'.');
    }

    /**
     * The super agent confirms the cash is now in their hand. Only the person the request was
     * addressed to may decide it, and a failed balance check leaves the request pending rather
     * than marking it as taken.
     */
    public function approve(Request $request, CashHandover $cashHandover)
    {
        $viewer = $request->user();

        if ($cashHandover->super_agent_id !== $viewer->id) {
            abort(403, 'This hand-in was sent to another super agent.');
        }

        $validated = $request->validate([
            'decision_note' => 'nullable|string|max:255',
        ]);

        try {
            $moved = $cashHandover->approve($validated['decision_note'] ?? null);
        } catch (InsufficientFundsException $e) {
            return back()->withErrors(['handover' => $e->getMessage().' The request is still pending.']);
        }

        if (! $moved) {
            return back()->withErrors(['handover' => 'That hand-in has already been decided.']);
        }

        ActivityLog::create([
            'user_id' => $viewer->id,
            ...ActivityLog::subjectAttributes($cashHandover),
            'action' => 'cash_handover_approved',
            'description' => $viewer->name.' received '.$cashHandover->figure().' from '
                .$cashHandover->agent->name.' ('.$cashHandover->reference.').',
            'new_value' => json_encode([
                'reference' => $cashHandover->reference,
                'amount' => $cashHandover->figure(),
                'agent' => $cashHandover->agent->name,
                'super_agent' => $viewer->name,
            ]),
        ]);

        return back()->with('success', $cashHandover->figure().' received from '.$cashHandover->agent->name.'.');
    }

    /**
     * The cash never arrived, or the note does not match it. The reason is required because the
     * agent sees it beside the declined request and has to know what to do next.
     */
    public function decline(Request $request, CashHandover $cashHandover)
    {
        $viewer = $request->user();

        if ($cashHandover->super_agent_id !== $viewer->id) {
            abort(403, 'This hand-in was sent to another super agent.');
        }

        $validated = $request->validate([
            'decision_note' => 'required|string|max:255',
        ], [
            'decision_note.required' => 'Say why you are sending this hand-in back.',
        ]);

        if (! $cashHandover->decline($validated['decision_note'])) {
            return back()->withErrors(['handover' => 'That hand-in has already been decided.']);
        }

        ActivityLog::create([
            'user_id' => $viewer->id,
            ...ActivityLog::subjectAttributes($cashHandover),
            'action' => 'cash_handover_declined',
            'description' => $viewer->name.' declined '.$cashHandover->figure().' from '
                .$cashHandover->agent->name.' ('.$cashHandover->reference.'): '.$validated['decision_note'],
            'new_value' => json_encode([
                'reference' => $cashHandover->reference,
                'amount' => $cashHandover->figure(),
                'agent' => $cashHandover->agent->name,
                'reason' => $validated['decision_note'],
            ]),
        ]);

        return back()->with('success', 'Hand-in '.$cashHandover->reference.' declined. The money stays in '
            .$cashHandover->agent->name."'s wallet.");
    }
}
