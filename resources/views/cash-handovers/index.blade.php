@extends('layouts.app')

@section('title', 'Hand In Cash Requests')

@section('content')
@php
    $me = request()->user();
    $statusLabels = \App\Http\Controllers\CashHandoverController::STATUS_LABELS;
    $periods = \App\Http\Controllers\CashHandoverController::PERIOD_CHOICES;

    // Everything but the period and the dates, so a preset link keeps the search, the status and
    // the currency the desk already picked.
    $keepFilters = array_filter(
        request()->only(['status', 'currency', 'search', 'agent_id']),
        fn ($value) => $value !== null && $value !== ''
    );
    $activePeriod = (string) request('period', 'all');
    $typedDates = request()->filled('date_from') || request()->filled('date_to');

    $totalRequests = $handovers->count();
    $totalHandovers = $handovers->where('status', \App\Models\CashHandover::PENDING)->count();

    // The lead the request names decides it, and the office that sees every wallet can decide it
    // too, because the permission to approve a hand-in is the same one either way.
    $decidable = fn ($handover) => $canDecide
        && $handover->status === \App\Models\CashHandover::PENDING
        && ($handover->super_agent_id === $me->id || $everyone);

    $bulkTargets = $handovers->filter($decidable);
@endphp

<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Hand In Cash Requests</h1>
        <p class="text-gray-600 mt-1">
            {{ $everyone
                ? 'Every request a desk has sent up, and what became of it.'
                : 'What you handed in, and what was handed to you to decide.' }}
        </p>
    </div>
    <a href="{{ route('cash-handovers.export', request()->query()) }}"
        class="border border-gray-300 bg-white text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-50 whitespace-nowrap">
        Export Excel
    </a>
</div>

<form method="GET" action="{{ route('cash-handovers.index') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 mb-6 no-print">
    {{-- The period is the first cut a desk makes, so the presets sit on their own row above the
         finer boxes, and the Filter and Clear buttons close the card on the right like every
         toolbar. A preset link keeps the search, the status and the currency already picked. --}}
    <div class="flex flex-wrap items-center gap-2">
        @foreach($periods as $key => $label)
            <a href="{{ route('cash-handovers.index', array_merge($keepFilters, ['period' => $key])) }}"
                class="px-3 py-1 text-xs rounded-full border {{ ! $typedDates && $activePeriod === $key ? 'bg-green-800 border-green-800 text-white' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' }}">{{ $label }}</a>
        @endforeach
        @if($dateFrom || $dateTo)
        <span class="text-xs text-gray-500 ml-1">Showing {{ $dateFrom ?: 'the earliest record' }} to {{ $dateTo ?: 'today' }}.</span>
        @endif
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mt-4">
        <div>
            <label class="block text-sm text-gray-600 mb-1" for="filter-search">Search</label>
            <input type="text" id="filter-search" name="search" value="{{ request('search') }}"
                placeholder="Reference or agent name" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1" for="filter-status">Status</label>
            <select id="filter-status" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All statuses</option>
                @foreach($statusLabels as $code => $label)
                    <option value="{{ $code }}" @selected(request('status') === $code)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1" for="filter-currency">Currency</label>
            <select id="filter-currency" name="currency" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All currencies</option>
                @foreach(\App\Http\Controllers\CashHandoverController::CURRENCY_CHOICES as $code)
                    <option value="{{ $code }}" @selected(strtoupper((string) request('currency')) === $code)>{{ $code }}</option>
                @endforeach
            </select>
        </div>
        @if($everyone)
        <div>
            <label class="block text-sm text-gray-600 mb-1" for="filter-agent">Agent</label>
            <select id="filter-agent" name="agent_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Every agent</option>
                @foreach($agentChoices as $agent)
                    <option value="{{ $agent->id }}" @selected((string) request('agent_id') === (string) $agent->id)>{{ $agent->name }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div>
            <label class="block text-sm text-gray-600 mb-1" for="filter-from">From date</label>
            <input type="date" id="filter-from" name="date_from" value="{{ request('date_from') }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1" for="filter-to">To date</label>
            <input type="date" id="filter-to" name="date_to" value="{{ request('date_to') }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
    </div>

    <div class="flex items-center justify-end gap-2 mt-4 pt-4 border-t border-gray-100">
        <a href="{{ route('cash-handovers.index') }}" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-200">Clear</a>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
    </div>
</form>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 mb-6">
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <div>
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Requests</span>
            <span class="block text-xl font-bold text-gray-900 tabular-nums mt-0.5">{{ number_format($totalRequests) }} {{ $totalRequests === 1 ? 'request' : 'requests' }}</span>
        </div>
        @if($totals !== [])
            @foreach($totals as $code => $group)
            <div>
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total</span>
                <span class="block text-xl font-bold text-gray-900 tabular-nums mt-0.5">{{ \App\Models\Account::format($code, $group['amount']) }}</span>
                <span class="block text-xs text-gray-500 tabular-nums">{{ number_format($group['count']) }} {{ $group['count'] === 1 ? 'request' : 'requests' }}</span>
            </div>
            @endforeach
            {{-- Charges are counted in shillings whatever money the cash beside them is in, because
                 they are collected here and stay here. --}}
            @if(array_sum(array_map(fn ($group) => (float) $group['charges'], $totals)) > 0)
            <div>
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Charges in these requests</span>
                <span class="block text-xl font-bold text-gray-900 tabular-nums mt-0.5">{{ \App\Models\Account::format('UGX', array_sum(array_map(fn ($group) => (float) $group['charges'], $totals))) }}</span>
                <span class="block text-xs text-gray-500">Stays in Uganda with the lead when each request is approved.</span>
            </div>
            @endif
        @else
            <div>
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total</span>
                <span class="block text-xl font-bold text-gray-900 tabular-nums mt-0.5">{{ \App\Models\Account::format('UGX', 0) }}</span>
            </div>
        @endif
    </div>
    <p class="text-[13px] text-gray-600 mt-4 pt-4 border-t border-gray-100">
        {{ number_format($totalHandovers) }} of these {{ $totalHandovers === 1 ? 'is' : 'are' }} still waiting. A pending hand-in is
        already inside the agent's wallet balance; nothing moves until it is approved.
    </p>
</div>

@if($bulkTargets->isNotEmpty())
<form method="POST" action="{{ route('cash-handovers.bulk') }}" id="bulk-handovers" class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6"
    data-confirm-title="Approve the selected hand ins"
    data-confirm="Each one moves the cash out of the agent's wallet into the wallet of the lead the request names, and that request's charges into the lead's shilling wallet. Confirm only the rows you have counted in hand."
    data-confirm-ok="Approve them"
    data-confirm-rows="{{ json_encode([['Ticked', 'input[name=bulk_summary]', 'value']]) }}"
    data-lock data-lock-label="Approving...">
    @csrf
    <input type="hidden" name="action" value="approve">
    {{-- Read back into the confirm card, so the dialog names the rows being moved rather than
         asking about a count nobody checked. The script below keeps it current. --}}
    <input type="hidden" name="bulk_summary" value="">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
        <span class="inline-flex items-center gap-2 text-sm font-semibold text-green-900">
            <span class="inline-flex items-center justify-center min-w-[1.5rem] h-6 px-1.5 rounded-full bg-green-800 text-white text-xs font-bold tabular-nums">{{ number_format($bulkTargets->count()) }}</span>
            waiting for you here.
        </span>
        <label class="flex items-center gap-2 text-sm text-green-900">
            <input type="checkbox" id="bulk-all-handovers" class="h-4 w-4 rounded border-gray-300">
            Tick every row
        </label>
        <span id="bulk-tally" class="text-sm text-gray-600" aria-live="polite">Nothing ticked yet.</span>
        <span class="flex-1"></span>
        <button type="submit" id="bulk-approve" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-green-700 disabled:opacity-50">Approve the ticked rows</button>
        <button type="button" data-clear-selection class="text-sm underline underline-offset-2 text-gray-600 hover:text-gray-900">Clear selection</button>
    </div>
</form>
@endif

@php
    // The desk is wide, so the columns that only carry a date or a sentence are folded into the cell
    // beside them, and the status sits early where it is always in view. What is left to scroll for
    // is the list of receipts, and the pen stays pinned at the right edge.
    $showLeadColumn = $everyone;
    $columnCount = 7 + ($bulkTargets->isNotEmpty() ? 1 : 0) + ($showLeadColumn ? 1 : 0);
@endphp
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-x-auto">
    <table class="w-full min-w-[1050px] text-sm">
        <thead class="bg-gray-50">
            <tr>
                @if($bulkTargets->isNotEmpty())
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap">Select</th>
                @endif
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap">Request</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap">Status</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap">Agent</th>
                @if($showLeadColumn)
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap">Super agent</th>
                @endif
                <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap" title="The money these transfers are worth, added together. This is the pile that goes on to China when the lead sends it.">Amount</th>
                <th scope="col" class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap" title="What those transfers charged the customers. It rides up with the cash and stays with the lead in Uganda.">Charges (UGX)</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap" title="The bookings whose cash this request carries. Approving them makes each one Ready to send to China.">Transfers</th>
                <th scope="col" class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 whitespace-nowrap sticky right-0 z-20 bg-gray-50 border-l border-gray-200">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse($handovers as $handover)
            @php
                $isMineToDecide = $decidable($handover);
                $canCallBack = $handover->agent_id === $me->id && $handover->status === \App\Models\CashHandover::PENDING;
                $named = $handover->receiptNumbers();
                $namedCount = $named->count();

                $handoverRows = [
                    ['From agent', $handover->agent?->name],
                    ['Amount', $handover->figure()],
                    ['Charges', $handover->chargeFigure()],
                    ['Transfers', $namedCount.' named'],
                    ['Reference', $handover->reference],
                ];
                if ($handover->note) {
                    $handoverRows[] = ['Their note', $handover->note];
                }

                // Sending one back only needs the bare figures; the story is already on the row
                // behind the card.
                $declineRows = [
                    ['Agent', $handover->agent?->name],
                    ['Amount', $handover->figure()],
                    ['Reference', $handover->reference],
                ];
                if ((float) $handover->charge_ugx > 0) {
                    $declineRows[] = ['Charges', $handover->chargeFigure()];
                }
            @endphp
            <tr>
                @if($bulkTargets->isNotEmpty())
                <td class="px-4 py-3">
                    @if($isMineToDecide)
                    <input type="checkbox" form="bulk-handovers" name="handover_ids[]" value="{{ $handover->id }}"
                        data-figure="{{ $handover->figure() }}{{ (float) $handover->charge_ugx > 0 ? ' + '.$handover->chargeFigure().' charges' : '' }}"
                        aria-label="Tick {{ $handover->reference }} to approve it" class="bulk-handover h-4 w-4 rounded border-gray-300">
                    @endif
                </td>
                @endif
                <td class="px-4 py-3">
                    <span class="block font-medium text-gray-900 whitespace-nowrap">{{ $handover->reference }}</span>
                    <span class="block text-xs text-gray-500 tabular-nums mt-0.5">{{ $handover->created_at->format('M j, Y, g:i A') }}</span>
                    @if($handover->note)
                        <span class="block text-xs text-gray-600 mt-1 max-w-[15rem]" title="{{ $handover->note }}">{{ $handover->note }}</span>
                    @endif
                    @if($handover->decision_note)
                        {{-- The same field carries what was said when the request closed, and who said
                             it depends on which hand closed it. --}}
                        <span class="block text-xs mt-1 max-w-[15rem] {{ $handover->status === \App\Models\CashHandover::DECLINED ? 'text-red-700' : 'text-gray-500' }}">
                            {{ $handover->status === \App\Models\CashHandover::APPROVED ? 'Said on approving: ' : ($handover->status === \App\Models\CashHandover::CANCELLED ? 'Called back: ' : 'Sent back: ') }}{{ $handover->decision_note }}
                        </span>
                    @elseif($handover->status === \App\Models\CashHandover::PENDING)
                        <span class="block text-xs mt-1 text-gray-400">No note with it</span>
                    @endif
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    @if($handover->status === \App\Models\CashHandover::APPROVED)
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Approved</span>
                    @elseif($handover->status === \App\Models\CashHandover::DECLINED)
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Declined</span>
                    @elseif($handover->status === \App\Models\CashHandover::CANCELLED)
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">Cancelled by the agent</span>
                    @else
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Pending</span>
                    @endif
                    <span class="block text-xs text-gray-500 mt-0.5">
                        @if($handover->decided_at)
                            Decided {{ $handover->decided_at->format('M j, Y, g:i A') }}
                        @else
                            {{ $handover->created_at->diffForHumans() }}
                        @endif
                    </span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $handover->agent?->name ?: 'Removed agent' }}</td>
                @if($showLeadColumn)
                <td class="px-4 py-3 whitespace-nowrap">{{ $handover->superAgent?->name ?: 'No super agent on it' }}</td>
                @endif
                <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums font-semibold text-gray-900">{{ \App\Models\Account::format($handover->currency, $handover->amount) }}</td>
                <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums {{ (float) $handover->charge_ugx > 0 ? 'text-gray-900' : 'text-gray-400' }}">
                    {{ (float) $handover->charge_ugx > 0 ? $handover->chargeFigure() : 'UGX 0' }}
                </td>
                <td class="px-4 py-3">
                    @if($namedCount === 0)
                        <span class="text-gray-400">No transfer named on it</span>
                    @else
                        <span class="inline-flex min-w-[2rem] justify-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 tabular-nums">{{ number_format($namedCount) }}</span>
                        <span class="block text-xs text-gray-500 mt-1 max-w-[13rem] truncate" title="{{ $named->implode(', ') }}">{{ $named->implode(', ') }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 whitespace-nowrap sticky right-0 z-10 bg-white border-l border-gray-200">
                    @if($isMineToDecide)
                    <div class="space-y-2">
                        <form method="POST" action="{{ route('cash-handovers.approve', $handover) }}"
                            data-confirm-title="Confirm you have received this cash"
                            data-confirm="Approving moves {{ $handover->figure() }} from {{ $handover->agent?->name }}'s wallet into {{ $handover->superAgent?->name }}'s, plus {{ $handover->chargeFigure() }} of charges into the shilling wallet there. The {{ $namedCount }} {{ \Illuminate\Support\Str::plural('transfer', $namedCount) }} then read Ready to send."
                            data-confirm-ok="Yes, I have it"
                            data-confirm-sections="{{ json_encode([['rows' => $handoverRows]]) }}"
                            data-lock data-lock-label="Approving...">
                            @csrf
                            <button type="submit" class="w-full sm:w-auto bg-green-800 text-white px-3 py-1.5 rounded-md text-xs font-semibold hover:bg-green-700">Approve receipt</button>
                        </form>
                        <form method="POST" action="{{ route('cash-handovers.decline', $handover) }}"
                            data-confirm-title="Send this hand-in back?"
                            data-confirm="The cash stays with {{ $handover->agent?->name }} and the transfers go back to Cash taken."
                            data-confirm-ok="Send it back"
                            data-confirm-danger="1"
                            data-confirm-sections="{{ json_encode([['rows' => $declineRows]]) }}"
                            data-confirm-rows="{{ json_encode([['Reason', 'input[name=decision_note]', 'value']]) }}"
                            data-lock data-lock-label="Sending back...">
                            @csrf
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <input type="text" name="decision_note" required maxlength="255" placeholder="Why it goes back"
                                    aria-label="Reason for declining {{ $handover->reference }}"
                                    class="w-28 min-w-0 px-2 py-1.5 border border-gray-300 rounded-md text-xs">
                                <button type="submit" class="bg-white text-red-700 border border-red-300 px-3 py-1.5 rounded-md text-xs font-semibold hover:bg-red-50">Decline</button>
                            </div>
                        </form>
                    </div>
                    @elseif($handover->status === \App\Models\CashHandover::PENDING)
                    <div class="space-y-2">
                        <span class="block text-xs text-gray-500">Only {{ $handover->superAgent?->name ?: 'the super agent it names' }} can decide this one.</span>
                        @if($canCallBack)
                        <form method="POST" action="{{ route('cash-handovers.cancel', $handover) }}"
                            data-confirm-title="Call your own hand-in back?"
                            data-confirm="Nothing has moved yet. {{ $handover->figure() }} plus {{ $handover->chargeFigure() }} in charges stay in your wallet, and the {{ $namedCount }} {{ \Illuminate\Support\Str::plural('transfer', $namedCount) }} go back to reading as cash taken."
                            data-confirm-ok="Call it back" data-confirm-danger="1"
                            data-confirm-sections="{{ json_encode([['rows' => $handoverRows]]) }}"
                            data-lock data-lock-label="Cancelling...">
                            @csrf
                            <div class="mt-1 flex flex-wrap items-center gap-2">
                                <input type="text" name="reason" maxlength="255" placeholder="Why, for the trail"
                                    aria-label="Reason for cancelling {{ $handover->reference }}"
                                    class="w-28 min-w-0 px-2 py-1.5 border border-gray-300 rounded-md text-xs">
                                <button type="submit" class="bg-white text-red-700 border border-red-300 px-3 py-1.5 rounded-md text-xs font-semibold hover:bg-red-50">Cancel my request</button>
                            </div>
                        </form>
                        @endif
                    </div>
                    @else
                    <span class="text-xs text-gray-500">Closed</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="{{ $columnCount }}" class="px-6 py-12 text-center">
                    <p class="font-semibold text-gray-700">No hand in requests for this filter.</p>
                    <p class="text-sm text-gray-500 mt-2">Widen the period, clear the status, or search a reference again.</p>
                    <a href="{{ route('cash-handovers.index') }}" class="text-green-800 underline text-sm inline-block mt-4">Clear the filters</a>
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection

@push('scripts')
{{-- The users desk has its own bulk script that never loads here, so this page counts its own
     ticks. Without it the tick-all box and the clear button would sit on the page doing nothing,
     and the confirm card would ask about a selection it cannot see. --}}
<script>
(function () {
    const boxes = Array.from(document.querySelectorAll('.bulk-handover'));
    if (boxes.length === 0) { return; }

    const every = document.getElementById('bulk-all-handovers');
    const tally = document.getElementById('bulk-tally');
    const summary = document.querySelector('input[name=bulk_summary]');
    const approve = document.getElementById('bulk-approve');

    function refresh() {
        const picked = boxes.filter((box) => box.checked);
        tally.textContent = picked.length === 0
            ? 'Nothing ticked yet.'
            : picked.length + ' of ' + boxes.length + ' ticked: ' + picked.map((box) => box.dataset.figure).join(', ');
        summary.value = tally.textContent;
        approve.disabled = picked.length === 0;
        every.checked = picked.length === boxes.length;
        every.indeterminate = picked.length > 0 && picked.length < boxes.length;
    }

    boxes.forEach((box) => box.addEventListener('change', refresh));

    every.addEventListener('change', function () {
        boxes.forEach((box) => { box.checked = every.checked; });
        refresh();
    });

    document.querySelector('[data-clear-selection]').addEventListener('click', function () {
        boxes.forEach((box) => { box.checked = false; });
        refresh();
    });

    refresh();
})();
</script>
@endpush
