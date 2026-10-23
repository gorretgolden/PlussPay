@extends('layouts.app')

@section('title', 'Transaction ' . $transaction->receipt_number)

@section('content')
@php
    // The money the receiver is handed goes by a different name on each route, and every sentence
    // below has to agree with the one the booking was priced at.
    $handed = match ($transaction->payoutCurrency()) {
        'USD' => 'dollars',
        'UGX' => 'shillings',
        default => 'yuan',
    };
    $dueToBeHanded = $transaction->payoutAmount() !== null;
@endphp
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Transaction: {{ $transaction->receipt_number }}</h1>
        <p class="text-gray-600">{{ $transaction->created_at->format('M d, Y h:i A') }}</p>
        <p class="mt-1 text-sm text-gray-600">From <span class="font-semibold text-gray-900">{{ $transaction->originLabel() }}</span> to <span class="font-semibold text-gray-900">{{ $transaction->destinationLabel() }}</span></p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('receipts.print', $transaction->receipt_number) }}" target="_blank"
            class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Print Receipt</a>
        <a href="{{ route('transactions.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Back</a>
    </div>
</div>

@if($transaction->delivered_at)
<div class="mb-4 flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 px-4 py-3 max-w-3xl">
    <svg class="mt-0.5 h-5 w-5 flex-shrink-0 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <div>
        <p class="text-sm font-semibold text-green-900">Paid in {{ $transaction->destinationLabel() }}</p>
        <p class="mt-0.5 text-sm text-green-800">{{ $transaction->payoutLabel() }} was handed to {{ $transaction->receiver_name }} on {{ $transaction->delivered_at->format('M j, Y g:i A') }}. This transfer is finished on both sides, and the receipt above still prints for the customer.</p>
    </div>
</div>
@endif

<div class="bg-white rounded-lg shadow p-6 max-w-3xl">
    <div class="grid grid-cols-2 gap-6 mb-6">
        <div>
            <h3 class="text-sm font-medium text-gray-500 mb-2">Sender</h3>
            <p class="text-gray-900">{{ $transaction->sender_name }}</p>
            <p class="text-gray-600 text-sm">{{ $transaction->sender_contact }}</p>
        </div>
        <div>
            <h3 class="text-sm font-medium text-gray-500 mb-2">Receiver</h3>
            <p class="text-gray-900">{{ $transaction->receiver_name }}</p>
            <p class="text-gray-600 text-sm">{{ $transaction->receiver_contact }}</p>
        </div>
    </div>

    <div class="border-t pt-4 mb-6">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Payment</h3>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">Origin desk:</span> {{ $transaction->deskLabel() }} ({{ $transaction->deskCode() }})</div>
            <div><span class="text-gray-500">Currency:</span> {{ $transaction->currency }}</div>
            <div><span class="text-gray-500">Money Handed Over:</span> {{ $transaction->collectedAmount() }}</div>
            <div><span class="text-gray-500">Paid How:</span> {{ $transaction->payment_method ?: '-' }}</div>
            <div><span class="text-gray-500">Confirmation Number:</span> {{ $transaction->payment_reference ?: '-' }}</div>
            <div><span class="text-gray-500">Charge Taken Out:</span> UGX {{ number_format((float) $transaction->charge_ugx) }}</div>
            <div><span class="text-gray-500">Money That Travels:</span> {{ $transaction->principalLabel() }}</div>
            {{-- A booking that hands over dollars is never turned through yuan, so it has no yuan
                 price to print. Showing the zero would read as yuan bought for nothing. --}}
            @if((float) $transaction->exchange_rate > 0)
            <div><span class="text-gray-500">Exchange Rate:</span> {{ rtrim(rtrim(number_format((float) $transaction->exchange_rate, 6, '.', ''), '0'), '.') }}</div>
            @endif
            @if($dueToBeHanded)
            <div><span class="text-gray-500">Receiver Is Handed:</span> {{ $transaction->payoutLabel() }}</div>
            @endif
            {{-- The dollar credit is the figure the corridor is settled in. It is the same money as
                 the payout when the receiver is paid in dollars, so it is not printed twice. --}}
            @if($transaction->payoutCurrency() !== 'USD')
            <div><span class="text-gray-500">Dollars Credited:</span> ${{ number_format($transaction->total_usd_equivalent, 1) }}</div>
            @endif
            <div>
                <span class="text-gray-500">Progress:</span>
                <x-progress-badge :transaction="$transaction" />
            </div>
        </div>
    </div>

    @if($stage)
    <div class="border-t pt-4 mb-6">
        <h3 class="text-sm font-medium text-gray-500 mb-1">Where this money is</h3>
        <p class="text-xs text-gray-500 mb-3">{{ $stage['text'] }}.
            @if($transaction->isToUganda())
            The {{ $handed }} a customer is owed are paid in {{ $transaction->destinationLabel() }} from
            the money handed over in {{ $transaction->originLabel() }}.</p>
            @elseif($transaction->payoutCurrency() === 'USD')
            The dollars a customer is owed only reach {{ $transaction->destinationLabel() }} once they
            have been sent across, and the desk there hands them over without any exchange.</p>
            @else
            The {{ $handed }} a customer is owed only reach {{ $transaction->destinationLabel() }} after
            the dollars for them have been sent, and the receiver is paid once the desk there holds
            those dollars.</p>
            @endif
        <ol class="text-sm space-y-2">
            <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <span class="text-gray-600">Money taken in</span>
                <span class="font-medium text-gray-900 tabular-nums">{{ $transaction->collectedAmount() }} on {{ $transaction->created_at->format('M d, Y h:i A') }}</span>
            </li>
            {{-- The cash a booking puts in an agent's hand goes up to a lead before it can cross, so
                 the request is read here as a stage of this transfer rather than a separate book. --}}
            @if($transaction->cashHandover)
            @php $handIn = $transaction->cashHandover; @endphp
            <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <span class="text-gray-600">Cash handed in to {{ $handIn->superAgent?->name ?: 'your super agent' }}</span>
                @if($handIn->status === \App\Models\CashHandover::PENDING)
                <span class="font-medium text-amber-700">Waiting for approval on {{ $handIn->reference }}</span>
                @elseif($handIn->status === \App\Models\CashHandover::APPROVED)
                <span class="font-medium text-gray-900 tabular-nums">Approved on {{ $handIn->decided_at?->format('M d, Y h:i A') }} ({{ $handIn->reference }})</span>
                @else
                <span class="font-medium text-gray-500">{{ $handIn->reference }} is {{ $handIn->status }}</span>
                @endif
            </li>
            @endif
            {{-- Only the way into China crosses as a separate movement: the dollars are sent first and
                 the desk there then pays. Money coming into Uganda is booked and paid at the desk
                 there, so there is no sending leg to show. --}}
            @unless($transaction->isToUganda())
            <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <span class="text-gray-600">Dollars sent to a desk in China</span>
                @if($transaction->settled_at)
                <span class="font-medium text-gray-900 tabular-nums">{{ \App\Models\Account::format('USD', $transaction->total_usd_equivalent) }} to {{ $transaction->settledDesk?->name ?? 'the China desk' }} on {{ $transaction->settled_at->format('M d, Y h:i A') }}</span>
                @else
                <span class="font-medium text-amber-700">Not sent to China yet</span>
                @endif
            </li>
            @endunless
            <li class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <span class="text-gray-600">{{ ucfirst($handed) }} paid to {{ $transaction->receiver_name }}</span>
                @if($transaction->delivered_at)
                <span class="font-medium text-gray-900 tabular-nums">{{ $transaction->payoutLabel() }} on {{ $transaction->delivered_at->format('M d, Y h:i A') }}</span>
                @else
                <span class="font-medium text-amber-700">Not paid yet</span>
                @endif
            </li>
        </ol>
        @canany(['send-to-china','confirm-china-payout'])
        <a href="{{ route('china.index') }}" class="inline-block mt-3 bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Move it at the China Desk</a>
        @endcanany
    </div>
    @endif

    @can('view-profit')
    <div class="border-t pt-4 mb-6">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
            <div>
                <h3 class="text-sm font-medium text-gray-500">Which accounts moved</h3>
                <p class="text-xs text-gray-500 mt-1">The same transfer written in the cash books, so the account that went up is named next to the one that came down.</p>
            </div>
            @if($transaction->status === 'completed' && ! $payoutRecorded && $dueToBeHanded)
            <a href="{{ route('accounts.entries.create', [
                    'account_id' => $payoutAccount->id,
                    'kind' => 'money_out',
                    'amount' => rtrim(rtrim(number_format((float) $transaction->payoutAmount(), 2, '.', ''), '0'), '.'),
                    'reference' => $transaction->receipt_number,
                    'description' => 'Paid '.$transaction->payoutLabel().' to '.$transaction->receiver_name.' for '.$transaction->receipt_number,
                ]) }}"
                class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Record the {{ $transaction->isToUganda() ? 'Uganda' : 'China' }} payout</a>
            @endif
        </div>

        <div class="overflow-x-auto -mx-6">
            <table class="min-w-full divide-y divide-gray-200 px-6">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Movement</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Goes up</th>
                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Goes down</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($legs as $leg)
                    <tr>
                        <td class="px-4 py-2 text-sm text-gray-900 whitespace-nowrap">{{ $leg->entry_date->format('M d, Y') }}</td>
                        <td class="px-4 py-2 text-sm text-gray-900">
                            <a href="{{ route('accounts.show', $leg->account) }}" class="text-green-800 hover:underline">{{ $leg->account?->name ?? 'Deleted account' }}</a>
                        </td>
                        <td class="px-4 py-2 text-sm text-gray-700">{{ \App\Models\AccountEntry::labelFor($leg->type) }}</td>
                        <td class="px-4 py-2 text-sm text-right text-gray-900 whitespace-nowrap">{{ $leg->direction === 'in' ? \App\Models\Account::format($leg->account?->currency, $leg->amount) : '' }}</td>
                        <td class="px-4 py-2 text-sm text-right text-gray-900 whitespace-nowrap">{{ $leg->direction === 'out' ? \App\Models\Account::format($leg->account?->currency, $leg->amount) : '' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-3 text-sm text-gray-500">
                            Nothing has reached the cash books for this transfer yet.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($transaction->status === 'completed' && ! $payoutRecorded && $dueToBeHanded)
        <p class="text-xs text-amber-700 mt-2">
            The {{ $transaction->collectedAmount() }} {{ $transaction->isToUganda() ? 'handed over in '.$transaction->originLabel() : 'collected here' }}
            is in the books, but the {{ $transaction->payoutLabel() }} owed to {{ $transaction->receiver_name }} in
            {{ $transaction->destinationLabel() }} has not been recorded yet. That payout is the line which reduces an account, so until it is written
            the books still show money the desk no longer holds. Use the button above once the payment is actually made.
        </p>
        @endif
    </div>
    @endcan

    @can('view-profit')
    @php
        // A transfer booked before its prices were stored says so out loud instead of printing a
        // zero rate, which would read as a dollar bought for nothing.
        $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        $price = fn ($value, string $unit = '') => (float) $value === 0.0
            ? 'not recorded'
            : trim($unit.' '.$trim($value)).' per 1 USD';
        $profit = $transaction->profitLabels();
        $toneClass = ['plain' => 'text-gray-900', 'loss' => 'text-red-600', 'muted' => 'text-gray-400'];
    @endphp
    <div class="border-t pt-4 mb-6">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Profit (admin only, never shown on a receipt)</h3>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">Dollar selling rate:</span> {{ $price($transaction->rate_sell_ugx, 'UGX') }}</div>
            <div><span class="text-gray-500">Yuan selling rate:</span> {{ $price($transaction->exchange_rate) }}</div>
            <div><span class="text-gray-500">Dollars credited:</span> ${{ number_format($transaction->total_usd_equivalent, 1) }}</div>
            <div><span class="text-gray-500">Paid out at the far end:</span> {{ $dueToBeHanded ? $transaction->payoutLabel() : 'not recorded' }}</div>
            <div><span class="text-gray-500">Foreign exchange profit:</span> <span class="{{ $toneClass[$profit['exchange']['tone']] }}">{{ $profit['exchange']['text'] }}</span></div>
            <div><span class="text-gray-500">Charge:</span> <span class="{{ $toneClass[$profit['charge']['tone']] }}">{{ $profit['charge']['text'] }}</span></div>
            <div class="col-span-2 pt-2 border-t text-base font-semibold">
                <span class="text-gray-500 font-normal">Overall profit:</span>
                <span class="{{ $toneClass[$profit['total']['tone']] }}">{{ $profit['total']['text'] }}</span>
            </div>
        </div>
        @if($profit['exchange']['tone'] === 'muted')
            <p class="text-xs text-amber-700 mt-2">No buying rate was recorded on this transfer, so only the charge counts as profit. The exchange figure is left unpriced rather than guessed.</p>
        @endif
    </div>
    @endcan

    @if($transaction->notes)
    <div class="border-t pt-4">
        <h3 class="text-sm font-medium text-gray-500 mb-1">Notes</h3>
        <p class="text-gray-700">{{ $transaction->notes }}</p>
    </div>
    @endif

    <div class="border-t pt-4 mt-4 text-sm text-gray-500 space-y-1">
        {{-- The desk comes off the transfer, not off the person, so moving somebody between desks
             later cannot rewrite where this money was actually taken. --}}
        @if($transaction->creator)
        <p>Created by <span class="font-medium text-gray-900">{{ $transaction->creator->name }}</span> <span class="text-gray-500">({{ $transaction->deskLabel() }} desk)</span> on {{ $transaction->created_at->format('M d, Y h:i A') }}</p>
        @endif
        <p>Last updated on {{ $transaction->updated_at->format('M d, Y h:i A') }}@if($transaction->editor) by <span class="font-medium text-gray-900">{{ $transaction->editor->name }}</span>@endif</p>
    </div>

    @can('approve-transaction')
    @if($transaction->status !== 'cancelled')
    <div class="border-t pt-4 mt-4">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Actions</h3>
        @if($transaction->hasLeftUganda())
        <p class="text-sm text-gray-600">{{ $transaction->delivered_at
            ? 'The receiver has been paid, so this transfer is closed and cannot be cancelled.'
            : 'The dollars have been sent to '.$transaction->destinationLabel().', so this transfer cannot be cancelled from here. The desk there has to bring the money back first.' }}</p>
        @elseif($transaction->hand_in_status)
        <p class="text-sm text-gray-600">{{ $transaction->receipt_number }} is named on hand-in
            {{ $transaction->cashHandover?->reference }}@if($transaction->cashHandover?->status === \App\Models\CashHandover::PENDING),
            which {{ $transaction->cashHandover?->superAgent?->name }} has not decided yet. Call that request back below
            to cancel this transfer @else, so its cash is already with
            {{ $transaction->cashHandover?->superAgent?->name }}. It has to come back to your hand before this transfer
            can be cancelled @endif.</p>
        @else
        <div class="flex gap-3">
            @if($transaction->status === 'pending')
            <form method="POST" action="{{ route('transactions.update-status', $transaction) }}" data-lock data-lock-label="Approving...">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="completed">
                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Approve</button>
            </form>
            @endif
            <button type="button" onclick="openCancelModal(this)"
                data-action="{{ route('transactions.update-status', $transaction) }}"
                data-receipt="{{ $transaction->receipt_number }} · {{ $transaction->sender_name }} to {{ $transaction->receiver_name }}"
                data-amount="{{ $transaction->principalLabel() }}"
                data-fee="{{ $transaction->feeLabel() }}"
                data-total="{{ $transaction->collectedAmount() }}"
                class="bg-red-600 text-white px-4 py-2 rounded-md text-sm hover:bg-red-700">Cancel transfer</button>
        </div>
        @endif
    </div>
    @endif
    @endcan

    @include('transactions.partials.cancel-modal')

    {{-- The same hand-in the list offers, on one booking: the agent sends this transfer's cash up to
         their lead, who approves it and then decides when the dollars cross. The figures are printed
         from the booking, not typed, because they are already fixed by what was taken in. --}}
    @if($mayHandIn)
        @php
            $mine = $transaction->created_by === auth()->id();
            $handIn = $transaction->cashHandover;
        @endphp
        @if($mine && $transaction->status === 'completed' && ! $handIn)
        <div class="border-t pt-4 mt-4">
            <h3 class="text-sm font-medium text-gray-500 mb-1">Hand this cash in for approval</h3>
            <p class="text-xs text-gray-500 mb-3">Your lead approves the cash and his wallets hold it. Only the money that travels goes on to China later; the charge stays with him.</p>
            <form method="POST" action="{{ route('cash-handovers.from-transactions') }}" class="grid grid-cols-1 gap-4 md:grid-cols-3 md:items-end"
                data-lock data-lock-label="Sending...">
                @csrf
                <input type="hidden" name="transaction_ids[]" value="{{ $transaction->id }}">
                <div>
                    <label for="hand_in_super_agent" class="block text-sm text-gray-600 mb-1">Handing to <span class="text-red-600">*</span></label>
                    <select id="hand_in_super_agent" name="super_agent_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="">Choose the super agent</option>
                        @foreach($superAgents as $lead)
                        <option value="{{ $lead->id }}">{{ $lead->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="hand_in_note" class="block text-sm text-gray-600 mb-1">Note <span class="font-normal text-gray-400">(optional)</span></label>
                    <input type="text" id="hand_in_note" name="note" maxlength="255" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm"
                        placeholder="e.g. Counter cash for today">
                </div>
                <div class="md:justify-self-end">
                    <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm font-semibold hover:bg-green-700">Hand in for approval</button>
                </div>
                <dl class="md:col-span-3 grid grid-cols-[auto_auto_1fr] items-baseline gap-x-3 gap-y-1.5 text-sm rounded-lg bg-gray-50 p-3 ring-1 ring-inset ring-gray-200">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Cash to hand in</dt>
                    <dd class="text-right font-semibold text-gray-900 tabular-nums">{{ $transaction->principalLabel() }}</dd>
                    <dd class="text-[11px] text-gray-500">what this transfer was worth when it was booked.</dd>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Charges with it</dt>
                    <dd class="text-right font-semibold text-gray-900 tabular-nums">{{ $transaction->feeLabel() }}</dd>
                    <dd class="text-[11px] text-gray-500">
                        @if($transaction->isToUganda())
                        a booking into Uganda never collected its charge as cash, so none of it rides up.
                        @else
                        what this transfer earned you; it goes up with the cash and stays with your lead.
                        @endif
                    </dd>
                </dl>
            </form>
        </div>
        @elseif($mine && $handIn?->status === \App\Models\CashHandover::PENDING)
        <div class="border-t pt-4 mt-4">
            <h3 class="text-sm font-medium text-gray-500 mb-1">Call this hand-in back</h3>
            <p class="text-xs text-gray-500 mb-3">Hand-in {{ $handIn->reference }} of {{ $handIn->figure() }}, plus
                {{ $handIn->chargeFigure() }} in charges, is waiting for {{ $handIn->superAgent->name }}. Nothing has moved yet,
                so cancelling it leaves the cash in your drawer and frees this transfer to be cancelled or handed in again.</p>
            <form method="POST" action="{{ route('cash-handovers.cancel', $handIn) }}" class="flex flex-wrap items-end gap-2"
                data-confirm data-confirm-danger="1" data-confirm-title="Call hand-in {{ $handIn->reference }} back?"
                data-confirm="Nothing has moved yet. The cash stays in your drawer and this transfer goes back to reading as cash taken."
                data-confirm-ok="Call it back" data-lock data-lock-label="Cancelling...">
                @csrf
                <div>
                    <label for="cancel_reason" class="block text-xs font-medium text-gray-600 mb-1">Why, for the trail <span class="text-gray-400">(optional)</span></label>
                    <input type="text" id="cancel_reason" name="reason" maxlength="255" class="w-full sm:w-64 px-3 py-2 border border-gray-300 rounded-md text-sm">
                </div>
                <button type="submit" class="rounded-md border border-red-300 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Cancel hand-in</button>
            </form>
        </div>
        @endif
    @endif

    @if($transaction->activityLogs->isNotEmpty())
    <div class="border-t pt-4 mt-4">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Activity Log</h3>
        <div class="space-y-2">
            @foreach($transaction->activityLogs->sortByDesc('created_at') as $log)
            <div class="flex items-start gap-3 text-sm">
                <div class="mt-1 w-2 h-2 rounded-full flex-shrink-0
                    @if($log->action === 'created') bg-blue-500
                    @elseif($log->action === 'approved') bg-green-500
                    @elseif($log->action === 'cancelled') bg-red-500
                    @else bg-gray-500 @endif"></div>
                <div>
                    <span class="text-gray-900">{{ $log->description }}</span>
                    <span class="text-gray-500">by {{ $log->user?->name ?? 'System' }}</span>
                    <span class="text-gray-400 text-xs">{{ $log->created_at->diffForHumans() }}</span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endsection
