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
    $bulkTargets = $handovers->filter(fn ($handover) => $canDecide
        && $handover->status === \App\Models\CashHandover::PENDING
        && $handover->super_agent_id === $me->id);
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

<form method="GET" action="{{ route('cash-handovers.index') }}" class="bg-white rounded-lg shadow p-4 mb-5 no-print">
    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
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
        <div class="flex items-end gap-2">
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
            <a href="{{ route('cash-handovers.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-gray-200">
        <span class="text-sm text-gray-600 mr-1">Period:</span>
        @foreach($periods as $key => $label)
            <a href="{{ route('cash-handovers.index', array_merge($keepFilters, ['period' => $key])) }}"
                class="px-3 py-1 text-xs rounded-full {{ ! $typedDates && $activePeriod === $key ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">{{ $label }}</a>
        @endforeach
        @if($dateFrom || $dateTo)
        <span class="text-xs text-gray-600 ml-1">Showing {{ $dateFrom ?: 'the earliest record' }} to {{ $dateTo ?: 'today' }}.</span>
        @endif
    </div>
</form>

<div class="bg-white rounded-lg shadow p-4 mb-5">
    <div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
        <div>
            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Requests</span>
            <span class="block text-xl font-bold text-gray-900 tabular-nums mt-0.5">{{ number_format($totalRequests) }} {{ $totalRequests === 1 ? 'request' : 'requests' }}</span>
        </div>
        @if($totals !== [])
            @foreach($totals as $code => $group)
            <div>
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total {{ $code }}</span>
                <span class="block text-xl font-bold text-gray-900 tabular-nums mt-0.5">{{ \App\Models\Account::format($code, $group['amount']) }}</span>
                <span class="block text-xs text-gray-500 tabular-nums">{{ number_format($group['count']) }} {{ $group['count'] === 1 ? 'request' : 'requests' }}</span>
            </div>
            @endforeach
        @else
            <div>
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Total</span>
                <span class="block text-xl font-bold text-gray-900 tabular-nums mt-0.5">{{ \App\Models\Account::format('UGX', 0) }}</span>
            </div>
        @endif
    </div>
    <p class="text-[13px] text-gray-600 mt-3">
        {{ number_format($totalHandovers) }} of these {{ $totalHandovers === 1 ? 'is' : 'are' }} still waiting. A pending hand in is
        already inside the agent's wallet balance and is not extra to it. The two wallets only move when it is approved.
    </p>
</div>

@if($bulkTargets->isNotEmpty())
<form method="POST" action="{{ route('cash-handovers.bulk') }}" id="bulk-handovers" class="bg-green-50 border border-green-200 rounded-lg p-4 mb-3"
    data-confirm-title="Approve the selected hand ins"
    data-confirm="Each one moves the money out of the agent's wallet into yours. Confirm only the rows you have counted in hand."
    data-confirm-ok="Approve them"
    data-confirm-rows="{{ json_encode([['Ticked', 'input[name=bulk_summary]', 'value']]) }}"
    data-lock data-lock-label="Approving...">
    @csrf
    <input type="hidden" name="action" value="approve">
    {{-- Read back into the confirm card, so the dialog names the rows being moved rather than
         asking about a count nobody checked. The script below keeps it current. --}}
    <input type="hidden" name="bulk_summary" value="">
    <div class="flex flex-wrap gap-x-4 gap-y-2 items-center">
        <span class="text-sm font-semibold text-green-800">{{ number_format($bulkTargets->count()) }} waiting for you here.</span>
        <label class="flex items-center gap-2 text-sm text-green-900">
            <input type="checkbox" id="bulk-all-handovers" class="h-4 w-4 rounded border-gray-300">
            Tick every row
        </label>
        <span id="bulk-tally" class="text-sm text-green-900" aria-live="polite">Nothing ticked yet.</span>
        <button type="submit" id="bulk-approve" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-green-700 disabled:opacity-50">Approve the ticked rows</button>
        <button type="button" data-clear-selection class="text-sm underline text-gray-600">Clear selection</button>
    </div>
</form>
@endif

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="w-full min-w-[1100px] text-sm">
        <thead class="bg-gray-50 text-gray-600">
            <tr>
                @if($bulkTargets->isNotEmpty())
                <th scope="col" class="px-3 py-3 text-left"><span class="text-xs font-semibold uppercase tracking-wide">Select</span></th>
                @endif
                <th scope="col" class="px-4 py-3 text-left tracking-wide whitespace-nowrap">Reference</th>
                <th scope="col" class="px-4 py-3 text-left tracking-wide whitespace-nowrap">Date</th>
                <th scope="col" class="px-4 py-3 text-left tracking-wide whitespace-nowrap">Agent</th>
                <th scope="col" class="px-4 py-3 text-left tracking-wide whitespace-nowrap">Super agent</th>
                <th scope="col" class="px-4 py-3 text-right tracking-wide whitespace-nowrap">Amount</th>
                <th scope="col" class="px-4 py-3 text-left tracking-wide whitespace-nowrap">Status</th>
                <th scope="col" class="px-4 py-3 text-left tracking-wide whitespace-nowrap">Waiting since</th>
                <th scope="col" class="px-4 py-3 text-left tracking-wide whitespace-nowrap">Note</th>
                <th scope="col" class="px-4 py-3 text-left tracking-wide whitespace-nowrap">Reason</th>
                <th scope="col" class="px-4 py-3 text-left tracking-wide whitespace-nowrap">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @forelse($handovers as $handover)
            @php
                $isMineToDecide = $canDecide
                    && $handover->status === \App\Models\CashHandover::PENDING
                    && $handover->super_agent_id === $me->id;

                $handoverRows = [
                    ['From agent', $handover->agent?->name],
                    ['Amount', $handover->figure()],
                    ['Reference', $handover->reference],
                ];
                if ($handover->note) {
                    $handoverRows[] = ['Their note', $handover->note];
                }
            @endphp
            <tr>
                @if($bulkTargets->isNotEmpty())
                <td class="px-3 py-3">
                    @if($isMineToDecide)
                    <input type="checkbox" form="bulk-handovers" name="handover_ids[]" value="{{ $handover->id }}"
                        data-figure="{{ $handover->figure() }}"
                        aria-label="Tick {{ $handover->reference }} to approve it" class="bulk-handover h-4 w-4 rounded border-gray-300">
                    @endif
                </td>
                @endif
                <td class="px-4 py-3 whitespace-nowrap font-medium text-gray-900">{{ $handover->reference }}</td>
                <td class="px-4 py-3 whitespace-nowrap tabular-nums">{{ $handover->created_at->format('M j, Y, g:i A') }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $handover->agent?->name ?: 'Removed agent' }}</td>
                <td class="px-4 py-3 whitespace-nowrap">{{ $handover->superAgent?->name ?: 'No super agent on it' }}</td>
                <td class="px-4 py-3 text-right whitespace-nowrap tabular-nums font-semibold text-gray-900">{{ \App\Models\Account::format($handover->currency, $handover->amount) }}</td>
                <td class="px-4 py-3 whitespace-nowrap">
                    @if($handover->status === \App\Models\CashHandover::APPROVED)
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Approved</span>
                    @elseif($handover->status === \App\Models\CashHandover::DECLINED)
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">Declined</span>
                    @else
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">Pending</span>
                    @endif
                </td>
                <td class="px-4 py-3 whitespace-nowrap tabular-nums text-gray-600">
                    @if($handover->decided_at)
                        Decided {{ $handover->decided_at->format('M j, Y, g:i A') }}
                    @else
                        {{ $handover->created_at->diffForHumans() }}
                    @endif
                </td>
                <td class="px-4 py-3 text-gray-600">{{ $handover->note ?: 'No note' }}</td>
                <td class="px-4 py-3 {{ $handover->status === \App\Models\CashHandover::DECLINED ? 'text-red-700' : 'text-gray-600' }}">
                    @if($handover->decision_note)
                        {{ $handover->decision_note }}
                    @elseif($handover->status === \App\Models\CashHandover::PENDING)
                        Waiting for the super agent
                    @else
                        No reason given
                    @endif
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                    @if($isMineToDecide)
                    <div class="flex flex-wrap items-center gap-2">
                        <form method="POST" action="{{ route('cash-handovers.approve', $handover) }}"
                            data-confirm-title="Confirm you have received this cash"
                            data-confirm="Approving moves the money from {{ $handover->agent?->name }}'s wallet into yours. Confirm only once you have counted the cash."
                            data-confirm-ok="Yes, I have it"
                            data-confirm-sections="{{ json_encode([['rows' => $handoverRows]]) }}"
                            data-lock data-lock-label="Approving...">
                            @csrf
                            <button type="submit" class="bg-green-800 text-white px-3 py-1.5 rounded-md text-xs font-semibold hover:bg-green-700">Approve receipt</button>
                        </form>
                        <form method="POST" action="{{ route('cash-handovers.decline', $handover) }}" class="flex flex-wrap items-center gap-2"
                            data-confirm-title="Send this hand-in back?"
                            data-confirm="The cash stays in {{ $handover->agent?->name }}'s wallet and the agent sees the reason you give."
                            data-confirm-ok="Send it back"
                            data-confirm-danger="1"
                            data-confirm-sections="{{ json_encode([['rows' => $handoverRows]]) }}"
                            data-confirm-rows="{{ json_encode([['Reason', 'input[name=decision_note]', 'value']]) }}"
                            data-lock data-lock-label="Sending back...">
                            @csrf
                            <input type="text" name="decision_note" required maxlength="255" placeholder="Why it is going back"
                                aria-label="Reason for declining {{ $handover->reference }}"
                                class="px-2 py-1.5 border border-gray-300 rounded-md text-xs w-44">
                            <button type="submit" class="bg-white text-red-700 border border-red-300 px-3 py-1.5 rounded-md text-xs font-semibold hover:bg-red-50">Decline</button>
                        </form>
                    </div>
                    @elseif($handover->status === \App\Models\CashHandover::PENDING)
                    <span class="text-xs text-gray-500">Only {{ $handover->superAgent?->name ?: 'the super agent it names' }} can decide this one.</span>
                    @else
                    <span class="text-xs text-gray-500">Closed</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="{{ $bulkTargets->isNotEmpty() ? 11 : 10 }}" class="px-6 py-12 text-center">
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
