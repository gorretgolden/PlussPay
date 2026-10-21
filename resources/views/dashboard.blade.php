@extends('layouts.app')

@section('title', 'Dashboard')

{{-- A failed hand-in carries its own summary inside the open dialog, so the page-level banner is
     not repeated above the board. A handover decision made here still uses the banner. --}}
@if($errors->hasAny(['super_agent_id', 'currency', 'amount', 'note']))
@section('inline-validation', true)
@endif

@section('content')
@php
    $me = auth()->user();
    $hour = (int) now()->format('G');
    // Kampala hours: the small band is the night, and a 3 AM reader is not being wished
    // a morning. The hour comes from the app time zone, so only these thresholds matter.
    $greeting = match (true) {
        $hour >= 5 && $hour < 12 => 'Good morning',
        $hour >= 12 && $hour < 18 => 'Good afternoon',
        $hour >= 18 => 'Good evening',
        default => 'Hello',
    };
    $desk = $me->countryLabel();

    // Stored UGX, not a fresh conversion, so these match the Reports page. The charge is typed as
    // a whole shilling figure, so it prints with no decimals. A computed figure carries one
    // decimal, because that is where the fractions of a booking land. What the customer handed
    // over stays a whole shilling.
    $money = fn ($ugx, $usd, int $dec = 2) => $usdToUgx ? 'UGX ' . number_format($ugx, $dec) : '$' . number_format($usd, $dec);
    $shilling = fn ($value) => $value < 0
        ? 'Loss UGX '.number_format(abs($value), 1)
        : 'UGX '.number_format($value, 1);
    $shillingTone = fn ($value) => $value < 0 ? 'text-red-600' : 'text-gray-900';

    // One card set per window, so no figure is printed in two places on the same page.
    $panels = [];
    foreach (['today' => 'Today', 'month' => 'This Month', 'all' => 'All Time'] as $key => $label) {
        $t = $windows[$key];
        $cards = [
            ['Completed', number_format($t['completed']), 'text-gray-900'],
            ['Sent', $money($t['sent_ugx'], $t['sent_usd'], 0), 'text-gray-900'],
            // The yuan a booking promised is one figure, but it is in three different hands
            // depending on how far the money has actually travelled, so it is shown as three cards
            // rather than one total that reads as if China already has it.
            ['Yuan To Receive In China', '¥' . number_format($t['yuan_in_uganda'], 1), 'text-gray-900',
                $t['yuan_in_uganda_transfers'] . ' held in Uganda'],
            ['Yuan In China, Unpaid', '¥' . number_format($t['yuan_in_china'], 1), 'text-gray-900',
                $t['yuan_in_china_transfers'] . ' waiting to be handed over'],
            ['Yuan Paid In China', '¥' . number_format($t['yuan_paid'], 1), 'text-gray-900',
                $t['yuan_paid_transfers'] . ' paid to receivers'],
            ['Charge', $money($t['charge_ugx'], $t['charge_usd'], 0), 'text-gray-900'],
        ];
        if (isset($profit[$key])) {
            $cards[] = ['FX profit', $shilling($profit[$key]['exchange']), $shillingTone($profit[$key]['exchange'])];
            $cards[] = ['Overall profit', $shilling($profit[$key]['total']), $shillingTone($profit[$key]['total'])];
        }

        // Six figures read as two rows of three, four read as one row. The card then sizes its
        // own number down only as far as it has to, so a long loss stays on one line at the
        // headline size rather than spilling past the border the way a fixed size would.
        $panels[$key] = [
            'label' => $label,
            'cards' => $cards,
            'minCol' => count($cards) > 4 ? '300px' : '220px',
            'pending' => $t['pending'],
            'cancelled' => $t['cancelled'],
            'avg_ugx' => $t['avg_ugx_sent'],
            'avg_usd' => $t['avg_usd_sent'],
            'unpriced' => $profit[$key]['unpriced'] ?? 0,
            'yuanTotal' => $t['rmb'],
        ];
    }

    $hasTransfers = array_sum($chartData['transactions']) > 0;
    $hasCharge = array_sum($chartData['revenue_ugx']) > 0;
    $hasExchange = isset($chartData['exchange_ugx']) && array_sum($chartData['exchange_ugx']) > 0;

    // The whole name is greeted, never just its first word: several desks share one role, so
    // "Agent" alone cannot say whose hand the balances below are in, and a shared account named
    // after its own role ("Super Admin") would be greeted as "Super".
    //
    // The role chip stays even when the name repeats the role, because the badge is the header's
    // statement of what this account may do, not a second rendering of the person's name.

    $clockDate = now()->format('D, j M Y');
    $clockTime = now()->format('g:i A');
@endphp
<div class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm p-4 sm:p-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-4 sm:gap-y-3">
        <div class="flex min-w-0 items-center gap-3 sm:mr-auto">
            {{-- The mark follows the same hour bands as the words: a sun for the desk hours, a moon
                 for the night shift. It carries no text, so it cannot repeat anything on the page. --}}
            <span aria-hidden="true" class="w-11 h-11 shrink-0 rounded-full flex items-center justify-center {{ $hour >= 6 && $hour < 18 ? 'bg-amber-50 text-amber-600' : 'bg-indigo-50 text-indigo-600' }}">
                @if($hour >= 6 && $hour < 18)
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4V2m0 20v-2m8-8h2M2 12h2m13.66-5.66l1.41-1.41M4.93 19.07l1.41-1.41m0-11.32L4.93 4.93m14.14 14.14l-1.41-1.41M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                @else
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.5 13.5A8.5 8.5 0 1110.5 3.5a7 7 0 0010 10z"/></svg>
                @endif
            </span>
            <div class="min-w-0">
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Dashboard</h1>
                <p class="text-gray-600 mt-1">{{ $greeting }}, {{ $me->name }}.</p>
            </div>
        </div>
        {{-- One line for the desk's own facts on a wide board, and each part drops onto the next
             line on its own on a phone rather than the whole block being dragged down. --}}
        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 capitalize">{{ $role }}</span>
            {{-- No flag emoji: Windows draws them as two bare letters, which reads as a fault. --}}
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border {{ $desk ? 'bg-green-100 text-green-800 border-green-200' : 'bg-amber-50 text-amber-800 border-amber-200' }}">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                {{ $desk ?: 'Desk country not set' }}
            </span>
            {{-- No seconds: the figure only has to be right to the minute, and a digit running
                 every second pulls the eye away from the balances. The day is left out on a phone,
                 where the board is read many times in one day. --}}
            <span class="text-xs text-gray-500 tabular-nums" id="liveClock">{{ $clockDate }} · {{ $clockTime }}</span>
            {{-- Log out and Reload sit here as well as at the bottom of the sidebar, because the
                 sidebar is hidden on a phone and the clock line is where eyes already land. --}}
            <div class="flex items-center gap-2 ml-auto sm:ml-0 no-print">
                {{-- A phone gets the two glyphs at 40px each with the words dropped; the sign-out is
                     the quieter of the pair and only colours up once it is actually being aimed at. --}}
                <button type="button" onclick="reloadDashboard(this)" aria-label="Reload this board"
                    class="inline-flex items-center justify-center gap-1.5 w-10 h-10 sm:w-auto sm:h-auto px-0 sm:px-3 sm:py-1.5 rounded-full text-xs font-semibold border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
                    <svg class="w-4 h-4 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span class="hidden sm:inline">Reload</span>
                </button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" aria-label="Log out"
                        class="inline-flex items-center justify-center gap-1.5 w-10 h-10 sm:w-auto sm:h-auto px-0 sm:px-3 sm:py-1.5 rounded-full text-xs font-semibold border border-gray-200 bg-white text-gray-500 hover:border-red-300 hover:bg-red-50 hover:text-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
                        <svg class="w-4 h-4 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        <span class="hidden sm:inline">Log out</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

@if($news['unread'] > 0)
{{-- The desk opens this page first, so the six newest things other people did are here rather
     than behind a menu. Each line is the notice's own words and links through to the record it
     is about. The count next to the title is the same one the sidebar carries. --}}
<div class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
    <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-4 sm:px-5 py-3">
        <h2 class="text-base sm:text-lg font-semibold text-gray-900 mr-auto">What Happened</h2>
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-900 tabular-nums">
            {{ number_format($news['unread']) }} to see
        </span>
    </div>
    <ul class="divide-y divide-gray-100">
        @foreach($news['items'] as $notice)
        @php $data = $notice->data; @endphp
        <li class="px-4 sm:px-5 py-3">
            <a href="{{ $data['path'] ?? '#' }}" class="group flex flex-wrap items-baseline gap-x-2 gap-y-1 focus:outline-none">
                <span class="text-sm font-semibold text-green-800 group-hover:text-green-900 group-hover:underline">{{ $data['headline'] ?? 'Something happened' }}</span>
                <span class="text-xs text-gray-500 tabular-nums ml-auto whitespace-nowrap">{{ $notice->created_at->format('M j, g:i A') }}</span>
            </a>
            <p class="text-sm text-gray-700 mt-0.5">{{ $data['body'] ?? '' }}</p>
            <p class="text-xs text-gray-500 mt-0.5">
                {{ $data['actor'] ?? 'The system' }}
                @if(!empty($data['actor_role']))<span class="text-gray-400">({{ $data['actor_role'] }})</span>@endif
            </p>
        </li>
        @endforeach
    </ul>
    <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 bg-gray-50 px-4 sm:px-5 py-3">
        <a href="{{ route('notifications.index') }}" class="text-sm font-semibold text-green-800 hover:text-green-900">
            See all {{ number_format($news['unread']) }}
        </a>
        <form method="POST" action="{{ route('notifications.read-all') }}">
            @csrf
            @method('PATCH')
            <button type="submit" class="text-sm text-gray-600 hover:text-gray-900 underline underline-offset-2">Mark all as read</button>
        </form>
    </div>
</div>
@endif

<style>
    /* The tab look follows aria-selected alone, so the styling cannot drift from the state. */
    [role="tab"][aria-selected="true"] { background: #fff; color: #111827; box-shadow: 0 1px 2px 0 rgb(0 0 0 / .05); }
    [role="tab"][aria-selected="false"] { color: #4b5563; }
    [role="tab"][aria-selected="false"]:hover { color: #111827; }
    [role="tab"]:focus-visible { outline: 2px solid #86efac; outline-offset: 1px; }

    /* A figure takes the headline size whenever its card can hold it on one line and gives up
       only the pixels it needs. Digits and commas in tabular figures measure about half a font
       size each, so dividing the card by the length of the string is the largest size it fits.
       A browser without container queries keeps the 1.5rem above and behaves as it did before. */
    .stat-card { container-type: inline-size; }
    .stat-value { font-size: 1.5rem; font-size: clamp(.875rem, calc(100cqi / (var(--len) * .52)), 1.5rem); }

    /* The hand-in form opens over the board the way a Bootstrap modal does: a dimmed page behind
       a centred card. The dialog scrolls inside itself so a phone in landscape is never left with
       a submit button below the screen edge. */
    .hand-in-modal { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .hand-in-modal[hidden] { display: none; }
    .hand-in-modal-backdrop { position: absolute; inset: 0; background: rgba(17, 24, 39, .55); }
    .hand-in-modal-panel { position: relative; width: 100%; max-width: 40rem; max-height: calc(100vh - 2rem); max-height: calc(100dvh - 2rem); overflow-y: auto; border-radius: .75rem; background: #fff; box-shadow: 0 25px 50px -12px rgb(0 0 0 / .35); }
    .hand-in-modal-panel:focus { outline: none; }
    .hand-in-modal-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; padding: 1rem 1.25rem; border-bottom: 1px solid #e5e7eb; }
    .hand-in-modal-body { padding: 1.25rem; }
    .hand-in-modal-foot { display: flex; flex-direction: column; gap: .5rem; padding: 1rem 1.25rem; border-top: 1px solid #e5e7eb; }
    @media (min-width: 640px) { .hand-in-modal-foot { flex-direction: row; justify-content: flex-end; } }
</style>

@php
    // Shortcuts to the work people come here to do, set at the top of the page because booking is
    // the reason the desk opens it; the balances are read after the work is entered. Each one is
    // dropped when its ability is missing, so nobody is handed a button that bounces back with a
    // permission error, and a role that books nothing at all gets no card rather than a row of
    // dead links. Every label is the words the screen it opens uses, so one thing never carries
    // two names. Sending opens with the corridor already chosen, since which way the money goes is
    // the first thing a desk knows about the booking standing in front of it. A desk only ever
    // sends one way: the cash is handed over where the desk is, so Uganda sends to China and China
    // sends to Uganda. Offering both roads let a Uganda hand "send money to Uganda", which is a
    // payment back to the customer standing at their own counter. The spending book carries its
    // own Record Expense button, so one card opens it rather than two cards competing for the
    // same job.
    $sendToUganda = \App\Models\Transaction::destinationForDesk($me->country) === \App\Models\Transaction::TO_UGANDA;
    $sendAction = $sendToUganda
        ? ['create-transaction', 'Send Money to Uganda', 'Cash in China, paid in Uganda', route('transactions.create', ['destination' => \App\Models\Transaction::TO_UGANDA]), 'M19 12H5m0 0l5-5m-5 5l5 5']
        : ['create-transaction', 'Send Money to China', 'Cash in Uganda, paid in China', route('transactions.create', ['destination' => \App\Models\Transaction::TO_CHINA]), 'M5 12h14m0 0l-5-5m5 5l-5 5'];
    $expenseHint = match (true) {
        $me->can('create-expense') && $me->can('view-all-expenses') => 'Record spending, read every entry',
        $me->can('create-expense') => 'Record spending, read your entries',
        $me->can('view-all-expenses') => 'Every expense logged',
        default => 'What you spent',
    };

    // An ordinary agent hands the desk's cash up to the lead agent. The shortcut opens the same
    // form in a dialog over the board instead of sending them to the wallet page; the wallet page
    // stays as the fallback when the script that opens the dialog is not running. It waits for an
    // active super agent, because without one the form has nobody to send the request to.
    $canHandIn = $superAgents->isNotEmpty() && $wallets !== [];

    $quickActions = array_values(array_filter([
        $sendAction,
        ...($canHandIn ? [[
            'view-wallets', 'Hand Cash In', 'Send cash to your super agent', route('wallet.index').'#hand-in',
            'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
            true,
        ]] : []),
        ['create-exchange', 'Record Exchange', 'Buy or sell notes', route('exchanges.create'), 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
        ['view-expenses', 'Expenses', $expenseHint, route('expenses.index'), 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ['view-exchange-rates', 'Exchange Rates', "Today's prices", route('exchange-rates.index'), 'M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2zm3-14h4m-4 4h4m-4 4h4'],
        ['create-customer', 'Add Customer', 'Save a person', route('customers.create'), 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        // The lead agent is the one person besides the office who reads every agent's work, so the
        // whole book is a shortcut of its own rather than a trip through the sidebar.
        ['view-all-transactions', 'Transactions', 'Every transfer in the book', route('transactions.index'), 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
    ], fn (array $a) => $me->can($a[0])));
@endphp
@if($quickActions !== [])
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-6 mb-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        @foreach($quickActions as $action)
        @php [$ability, $label, $hint, $url, $glyph, $opensHandIn] = array_pad($action, 6, null); @endphp
        <a href="{{ $url }}" @if($opensHandIn) data-hand-in-open aria-controls="hand-in-modal" aria-haspopup="dialog" aria-expanded="false" @endif class="flex items-center gap-3 min-w-0 rounded-lg border border-gray-200 p-3 hover:border-green-300 hover:bg-green-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1{{ count($quickActions) % 2 === 1 && $loop->last ? ' sm:col-span-2' : '' }}">
            <span aria-hidden="true" class="w-9 h-9 shrink-0 rounded-full bg-green-100 text-green-800 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $glyph }}"/></svg>
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-semibold text-gray-900">{{ $label }}</span>
                <span class="block text-xs text-gray-500 truncate">{{ $hint }}</span>
            </span>
        </a>
        @endforeach
    </div>
</div>
@endif

{{-- The super agent's first job of the day: the cash the agents say they are bringing. A request
     moves no money until it is approved here or on the wallet page, so the queue is the decision
     itself rather than a reminder of one. The card stays on the dashboard while the queue is empty
     so the desk can see where the money will arrive before the first agent ever sends any. --}}
@if($me->hasRole('super-agent'))
<div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 overflow-hidden">
    <div class="flex flex-wrap items-center gap-2 border-b border-gray-200 px-4 sm:px-5 py-3">
        <h2 class="text-lg font-semibold text-gray-900 mr-auto">Cash Handed To You</h2>
        @if($handoverQueue->isNotEmpty())
        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-yellow-100 text-yellow-900 tabular-nums">
            {{ number_format($handoverQueue->count()) }} waiting
        </span>
        @endif
        <a href="{{ route('wallet.index') }}" class="text-sm font-semibold text-green-800 hover:underline">Open the wallet</a>
    </div>
    @if($handoverQueue->isNotEmpty())
    <ul class="divide-y divide-gray-100">
        @foreach($handoverQueue as $handover)
        <li class="px-4 sm:px-5 py-3">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="text-sm font-semibold text-gray-900">{{ $handover->agent?->name }}</span>
                <span class="text-sm font-bold text-gray-900 tabular-nums">{{ $handover->figure() }}</span>
                <span class="text-xs text-gray-500 tabular-nums ml-auto whitespace-nowrap">{{ $handover->created_at->format('M j, g:i A') }}</span>
            </div>
            <p class="text-xs text-gray-500 mt-0.5">
                {{ $handover->reference }}
                @if($handover->note)<span class="text-gray-400"> · </span>{{ $handover->note }}@endif
            </p>
            <div class="flex flex-wrap items-center gap-2 mt-2">
                <form method="POST" action="{{ route('cash-handovers.approve', $handover) }}">
                    @csrf
                    <button type="submit" class="bg-green-800 text-white px-3 py-1.5 rounded-md text-xs font-semibold hover:bg-green-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
                        Approve receipt
                    </button>
                </form>
                <form method="POST" action="{{ route('cash-handovers.decline', $handover) }}" class="flex flex-wrap items-center gap-2">
                    @csrf
                    <input type="text" name="decision_note" required maxlength="255" placeholder="Why it is going back"
                        aria-label="Reason for declining {{ $handover->reference }}"
                        class="px-2 py-1.5 border border-gray-300 rounded-md text-xs w-44 focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <button type="submit" class="bg-white text-red-700 border border-red-300 px-3 py-1.5 rounded-md text-xs font-semibold hover:bg-red-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
                        Decline
                    </button>
                </form>
            </div>
        </li>
        @endforeach
    </ul>
    @endif
    @if($handoverQueue->isEmpty() && $handoverHistory->isEmpty())
    <div class="px-4 sm:px-5 py-4">
        <p class="text-sm text-gray-600">No cash has been handed to you yet. When an agent sends cash in, it waits here until you approve it.</p>
    </div>
    @endif
    @if($handoverHistory->isNotEmpty())
    <div class="border-t border-gray-200 bg-gray-50 px-4 sm:px-5 py-3">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Decided lately</div>
        <ul class="space-y-1">
            @foreach($handoverHistory as $handover)
            <li class="flex flex-wrap items-baseline gap-x-2 text-sm">
                <span class="font-medium {{ $handover->status === \App\Models\CashHandover::APPROVED ? 'text-green-800' : 'text-red-700' }}">{{ $handover->status === \App\Models\CashHandover::APPROVED ? 'Received' : 'Declined' }}</span>
                <span class="text-gray-900 tabular-nums">{{ $handover->figure() }}</span>
                <span class="text-gray-500">from {{ $handover->agent?->name }}</span>
                <span class="text-xs text-gray-500 ml-auto tabular-nums whitespace-nowrap">{{ $handover->decided_at?->format('M j, g:i A') }}</span>
            </li>
            @endforeach
        </ul>
    </div>
    @endif
</div>
@endif

{{-- Account::currenciesFor() orders the wallets by the desk's country, so the local currency is always the first card.
     The controller hands an empty list to anyone who reads every wallet instead of holding one, and the row is left out. --}}
@if($wallets !== [])
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
    @foreach($wallets as $currency => $account)
    @php
        $balance = (float) $account->balance();
        $glyph = ['UGX' => 'USh', 'RMB' => '¥', 'USD' => '$'][$currency] ?? $currency;
        $shown = $currency === 'UGX'
            ? 'UGX ' . number_format($balance, 0)
            : $glyph . number_format($balance, 2);
    @endphp
    <a href="{{ route('wallet.index') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 hover:shadow transition-shadow block">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $currency }} Wallet</div>
                <div class="text-2xl font-bold text-gray-900 mt-2 tabular-nums whitespace-nowrap">{{ $shown }}</div>
            </div>
            <span aria-hidden="true" class="w-10 h-10 shrink-0 rounded-full bg-green-100 text-green-800 flex items-center justify-center text-sm font-bold">{{ $glyph }}</span>
        </div>
        @if($currency === 'UGX')
        <div class="text-xs text-gray-500 mt-2">Charge kept this month: UGX {{ number_format($commissionMonth) }}</div>
        @endif
        @if(auth()->user()->hasRole('agent'))
        <div class="text-xs text-gray-500 mt-1">
            In your hand until you hand it in.
            @if(bccomp((string) ($handedIn[$account->id] ?? '0'), '0', 2) > 0)
                Handed in so far: {{ \App\Models\Account::format($currency, $handedIn[$account->id]) }}.
            @endif
        </div>
        @if((float) ($pendingHandIn[$currency] ?? 0) > 0)
        <div class="text-xs text-amber-700 mt-1">{{ \App\Models\Account::format($currency, $pendingHandIn[$currency]) }} waiting for the super agent's approval.</div>
        @endif
        @endif
        @if($me->hasRole('super-agent'))
        <div class="text-xs text-gray-500 mt-1">Cash handed to you by the agents. Send it on from the China desk.</div>
        @endif
        @if($balance <= 0)
        <div class="text-xs text-gray-500 mt-1">No balance yet. @can('adjust-wallets')<span class="font-semibold text-gray-700 underline">Add money</span>@endcan</div>
        @endif
    </a>
    @endforeach
</div>
@endif

{{-- Money going out on the same day as the money coming in above it. Each desk keeps its own
     totals, because shillings spent in Kampala and yuan spent in Guangzhou are not one number. --}}
@can('view-expenses')
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-6 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h2 class="text-lg font-semibold text-gray-900">Spent today</h2>
        <a href="{{ route('expenses.index') }}" class="text-sm text-green-800 hover:underline">Open the spending book</a>
    </div>
    @if($spending['desks'] === [])
        <p class="text-sm text-gray-500">Nothing has been recorded as spent today.</p>
    @else
    <div class="grid grid-cols-[repeat(auto-fit,minmax(min(15rem,100%),1fr))] gap-4">
        @foreach($spending['desks'] as $desk)
        @php
            $who = collect($desk['people'])->take(3);
            $rest = count($desk['people']) - $who->count();
        @endphp
        <div class="stat-card min-w-0 rounded-lg border border-gray-200 p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $desk['label'] }}</div>
            @foreach($desk['totals'] as $currency => $amount)
            <div class="text-2xl font-bold text-gray-900 mt-2 tabular-nums whitespace-nowrap">{{ \App\Models\Account::format($currency, $amount) }}</div>
            @endforeach
            <div class="text-xs text-gray-500 mt-2">
                {{ number_format($desk['count']) }} {{ $desk['count'] === 1 ? 'entry' : 'entries' }} today
                @if($who->isNotEmpty()) · {{ $who->implode(', ') }}@if($rest > 0) and {{ $rest }} more @endif @endif
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endcan

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-6 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center gap-2">
            Overview
            @if(isset($profit['all']))
            <span class="relative inline-flex group" tabindex="0" aria-describedby="profit-note">
                <svg class="w-4 h-4 text-gray-400 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h3m-3 0a3 3 0 116 0 3 3 0 01-6 0zm0 0v3m0 0a9 9 0 110-12 9 9 0 010 12z"/></svg>
                <span role="tooltip" id="profit-note" class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-2 w-60 -translate-x-1/2 rounded-md bg-gray-900 px-3 py-2 text-left text-xs font-normal leading-snug text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100 group-focus:opacity-100">Overall profit is the charge plus FX profit. Transfers booked before their rates were stored count as 0.</span>
            </span>
            @endif
        </h2>
        <div role="tablist" aria-label="Time window" class="inline-flex gap-1 rounded-lg border border-gray-200 bg-gray-50 p-1">
            @foreach($panels as $key => $panel)
            <button type="button" role="tab" id="tab-{{ $key }}" data-window="{{ $key }}" aria-controls="panel-{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" class="rounded-md px-3 py-1.5 text-sm font-medium">{{ $panel['label'] }}</button>
            @endforeach
        </div>
    </div>

    @foreach($panels as $key => $panel)
    <div role="tabpanel" id="panel-{{ $key }}" aria-labelledby="tab-{{ $key }}" @if(! $loop->first) hidden @endif>
        <div class="grid grid-cols-[repeat(auto-fit,minmax(min({{ $panel['minCol'] }},100%),1fr))] gap-4">
            @foreach($panel['cards'] as $card)
            @php [$cardLabel, $cardValue, $cardTone, $cardNote] = array_pad($card, 4, null); @endphp
            <div class="stat-card min-w-0 rounded-lg border border-gray-200 p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $cardLabel }}</div>
                <div class="stat-value mt-2 font-bold {{ $cardTone }} tabular-nums whitespace-nowrap" style="--len: {{ strlen($cardValue) }}">{{ $cardValue }}</div>
                @if($cardNote !== null)
                <div class="text-xs text-gray-500 mt-1">{{ $cardNote }}</div>
                @endif
            </div>
            @endforeach
        </div>
        <p class="text-xs text-gray-500 mt-3">{{ number_format($panel['pending']) }} pending · {{ number_format($panel['cancelled']) }} cancelled · average transfer {{ $money($panel['avg_ugx'], $panel['avg_usd'], 0) }}</p>
        @if($panel['yuanTotal'] > 0)
        <p class="text-xs text-gray-500 mt-2">The three yuan cards are the same ¥{{ number_format($panel['yuanTotal'], 1) }} of booked yuan split by whose hands it is in: held in Uganda until its
            dollars are sent, with a desk in China until the receiver is paid, then paid.
            @canany(['send-to-china', 'confirm-china-payout'])
            <a href="{{ route('china.index') }}" class="text-green-800 font-medium hover:underline">Move the money at the China Desk</a>.
            @endcanany
        </p>
        @endif
        @if($panel['unpriced'] > 0)
        <p class="text-xs text-amber-700 mt-2">{{ number_format($panel['unpriced']) }} without rates</p>
        @endif
    </div>
    @endforeach
</div>

@can('view-users')
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
    <a href="{{ route('users.index') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 hover:shadow transition-shadow block">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Active Users</div>
                <div class="text-2xl font-bold text-gray-900 mt-2 tabular-nums">{{ number_format($stats['total_users']) }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ number_format($stats['total_agents']) }} agents</div>
            </div>
            <span aria-hidden="true" class="w-10 h-10 shrink-0 rounded-full bg-green-100 text-green-800 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </span>
        </div>
    </a>
    <a href="{{ route('exchange-rates.index') }}" class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 hover:shadow transition-shadow block">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Rates</div>
                <div class="text-2xl font-bold text-gray-900 mt-2 tabular-nums">{{ number_format($stats['active_rates']) }}</div>
                <div class="text-xs text-gray-500 mt-1">Live currency pairs</div>
            </div>
            <span aria-hidden="true" class="w-10 h-10 shrink-0 rounded-full bg-green-100 text-green-800 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
            </span>
        </div>
    </a>
</div>

@if(($logAlerts['failed_logins_24h'] ?? 0) > 0 || ($logAlerts['unresolved_errors'] ?? 0) > 0)
<div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
    @if(($logAlerts['failed_logins_24h'] ?? 0) > 0)
    <a href="{{ route('admin.login-logs', ['status' => 'failed']) }}" class="bg-amber-50 border border-amber-200 rounded-xl shadow-sm p-5 hover:shadow transition-shadow block">
        <div class="text-xs font-semibold uppercase tracking-wide text-amber-800">Failed sign-ins (24h)</div>
        <div class="text-2xl font-bold text-amber-900 mt-2 tabular-nums">{{ number_format($logAlerts['failed_logins_24h']) }}</div>
        <div class="text-xs text-amber-800 mt-1">Check the Login Logs for a brute-force pattern.</div>
    </a>
    @endif
    @can('view-system-logs')
    @if(($logAlerts['unresolved_errors'] ?? 0) > 0)
    <a href="{{ route('admin.system-logs', ['resolved' => '0']) }}" class="bg-red-50 border border-red-200 rounded-xl shadow-sm p-5 hover:shadow transition-shadow block">
        <div class="text-xs font-semibold uppercase tracking-wide text-red-800">Unresolved system errors</div>
        <div class="text-2xl font-bold text-red-900 mt-2 tabular-nums">{{ number_format($logAlerts['unresolved_errors']) }}</div>
        <div class="text-xs text-red-800 mt-1">Open System Errors to read and mark them resolved.</div>
    </a>
    @endif
    @endcan
</div>
@endif
@endcan

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 min-w-0">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Completed Transfers (Last 7 Days)</h2>
        @if($hasTransfers)
        <div class="relative h-[250px] max-w-full">
            <canvas id="transactionsChart"></canvas>
        </div>
        @else
        <div class="h-[250px] flex items-center justify-center rounded-lg border border-dashed border-gray-200 text-sm text-gray-500">No transfers in the last 7 days.</div>
        @endif
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 min-w-0">
        @can('view-profit')
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Profit (Last 7 Days)</h2>
        @if($hasCharge || $hasExchange)
        <div class="relative h-[250px] max-w-full">
            <canvas id="profitChart"></canvas>
        </div>
        @else
        <div class="h-[250px] flex items-center justify-center rounded-lg border border-dashed border-gray-200 text-sm text-gray-500">No charge or FX profit in the last 7 days.</div>
        @endif
        @else
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Charge Collected (Last 7 Days)</h2>
        @if($hasCharge)
        <div class="relative h-[250px] max-w-full">
            <canvas id="revenueChart"></canvas>
        </div>
        @else
        <div class="h-[250px] flex items-center justify-center rounded-lg border border-dashed border-gray-200 text-sm text-gray-500">No charge collected in the last 7 days.</div>
        @endif
        @endcan
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-200 flex flex-wrap justify-between items-center gap-3">
        {{-- The button that used to sit here booked the same thing as Send Money in Quick
             Actions above, under a second name. One call per action per page. --}}
        <h2 class="text-lg font-semibold text-gray-900">Recent Transactions</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full min-w-[760px] divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Receipt #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Sender</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Receiver</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Amount</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">RMB</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($recentTransactions as $t)
                <tr>
                    <td class="px-4 py-4 text-sm font-medium text-green-800 whitespace-nowrap">
                        <a href="{{ route('receipts.show', $t->receipt_number) }}">{{ $t->receipt_number }}</a>
                    </td>
                    <td class="px-4 py-4 text-sm text-gray-900">{{ $t->sender_name }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900">{{ $t->receiver_name }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap tabular-nums">{{ $t->principalLabel() }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap tabular-nums">¥{{ number_format($t->rmb_amount, 1) }}</td>
                    <td class="px-4 py-4">
                        <span class="px-2 py-1 text-xs rounded-full whitespace-nowrap
                            @if($t->status === 'completed') bg-green-100 text-green-800
                            @elseif($t->status === 'pending') bg-yellow-100 text-yellow-800
                            @else bg-red-100 text-red-800 @endif">
                            {{ ucfirst($t->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $t->created_at->format('Y-m-d h:i A') }}</td>
                    <td class="px-4 py-4 text-sm whitespace-nowrap">
                        <a href="{{ route('receipts.print', $t->receipt_number) }}" target="_blank" class="text-green-600 hover:text-green-800 mr-2">Print</a>
                        <a href="{{ route('receipts.show', $t->receipt_number) }}" class="text-green-600 hover:text-green-800">View</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                        No transactions yet.
                        @can('create-transaction') Book the first one from <a href="{{ route('transactions.create') }}" class="text-green-700 underline">Send Money</a>.@endcan
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($canHandIn)
@php
    $chosen = old('currency', array_key_first($wallets) ?? '');
    $heldNow = (float) (($wallets[$chosen] ?? null)?->balance() ?? 0);
    // Handing in the whole bag is the usual case, so the box opens at what the chosen wallet
    // holds. A figure typed before a failed submit always wins over the guess.
    $prefill = old('amount', $heldNow > 0 ? (string) round($heldNow, 2) : '');
    // A failed submission comes back to this page, so the dialog renders open with its field
    // errors visible rather than waiting to be found behind the shortcut.
    $handInOpen = $errors->hasAny(['super_agent_id', 'currency', 'amount', 'note']);
    $handInFields = ['super_agent_id' => ['Super agent', 'hand-in-super-agent'], 'currency' => ['Wallet', 'hand-in-currency'], 'amount' => ['Amount', 'hand-in-amount'], 'note' => ['Note', 'hand-in-note']];
    $handInFailed = array_filter($handInFields, fn ($field) => $errors->has($field), ARRAY_FILTER_USE_KEY);
@endphp
{{-- The form the shortcut used to open on the wallet page, now a dialog over the board so the
     request is made without leaving the till. The shortcut keeps its wallet link as the fallback
     for any browser where the small script below does not run. --}}
<div id="hand-in-modal" class="hand-in-modal no-print" @if(! $handInOpen) hidden @endif>
    <div class="hand-in-modal-backdrop" data-hand-in-dismiss aria-hidden="true"></div>
    <div class="hand-in-modal-panel" role="dialog" aria-modal="true" aria-labelledby="hand-in-title" tabindex="-1">
        <form method="POST" action="{{ route('cash-handovers.store') }}">
            @csrf
            <div class="hand-in-modal-head">
                <h2 id="hand-in-title" class="text-lg font-semibold text-gray-900">Hand cash in to your super agent</h2>
                <button type="button" data-hand-in-dismiss aria-label="Close"
                    class="shrink-0 -mr-1 -mt-1 inline-flex h-8 w-8 items-center justify-center rounded-md text-2xl leading-none text-gray-500 hover:bg-gray-100 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="hand-in-modal-body">
                <p class="text-sm text-gray-600">The money stays in your wallet until the super agent approves it. The cash only changes hands when they approve. Follow the decision on <a href="{{ route('wallet.index') }}" class="font-medium text-green-800 underline underline-offset-4">My Wallet</a>.</p>

                {{-- The dialog covers the page, so the failed field is named and linked here rather
                     than in a banner at the top of the board. --}}
                @if($handInFailed)
                <div data-error-summary role="alert" tabindex="-1" class="mt-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-gray-800 focus:outline-none">
                    <p class="font-medium text-red-800">Nothing was sent. Check {{ count($handInFailed) === 1 ? 'this field' : 'these fields' }}.</p>
                    <ul class="mt-1 space-y-0.5">
                        @foreach($handInFailed as $field => [$label, $anchor])
                        <li><a href="#{{ $anchor }}">{{ $label }}</a>: {{ $errors->first($field) }}</li>
                        @endforeach
                    </ul>
                </div>
                @endif

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label for="hand-in-super-agent" class="block text-sm font-medium text-gray-700 mb-1">Super agent</label>
                        <select id="hand-in-super-agent" name="super_agent_id" required @if($errors->has('super_agent_id')) aria-invalid="true" @endif class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500{{ $errors->has('super_agent_id') ? ' field-invalid' : '' }}">
                            @foreach($superAgents as $superAgent)
                            <option value="{{ $superAgent->id }}" @selected((string) old('super_agent_id') === (string) $superAgent->id)>{{ $superAgent->name }}</option>
                            @endforeach
                        </select>
                        @error('super_agent_id') <p class="field-error text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="hand-in-currency" class="block text-sm font-medium text-gray-700 mb-1">Wallet</label>
                        <select id="hand-in-currency" name="currency" required @if($errors->has('currency')) aria-invalid="true" @endif class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500{{ $errors->has('currency') ? ' field-invalid' : '' }}">
                            @foreach($wallets as $currency => $account)
                            <option value="{{ $currency }}" data-balance="{{ (string) round((float) $account->balance(), 2) }}" @selected($chosen === $currency)>{{ $currency }} ({{ \App\Models\Account::format($currency, $account->balance()) }} in hand)</option>
                            @endforeach
                        </select>
                        @error('currency') <p class="field-error text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="hand-in-amount" class="block text-sm font-medium text-gray-700 mb-1">Amount</label>
                        <input type="number" id="hand-in-amount" name="amount" step="0.01" min="0.01" max="{{ $heldNow > 0 ? $heldNow : '' }}" required value="{{ $prefill }}"
                            aria-describedby="hand-in-amount-hint" @if($errors->has('amount')) aria-invalid="true" @endif
                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500{{ $errors->has('amount') ? ' field-invalid' : '' }}">
                        <p id="hand-in-amount-hint" class="text-xs text-gray-400 mt-1">Cannot be more than the {{ $chosen }} wallet holds right now.</p>
                        @error('amount') <p class="field-error text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-4">
                    <label for="hand-in-note" class="block text-sm font-medium text-gray-700 mb-1">Note, optional</label>
                    <input type="text" id="hand-in-note" name="note" maxlength="255" value="{{ old('note') }}" placeholder="e.g. Full day's collections"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500{{ $errors->has('note') ? ' field-invalid' : '' }}">
                    @error('note') <p class="field-error text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="hand-in-modal-foot">
                <button type="button" data-hand-in-dismiss class="w-full sm:w-auto rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300">Cancel</button>
                <button type="submit" class="w-full sm:w-auto bg-green-800 text-white px-5 py-2 rounded-md text-sm font-medium hover:bg-green-700 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300">Send for approval</button>
            </div>
        </form>
    </div>
</div>
@endif

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const chartData = @json($chartData);
    const brand = 'rgb(22, 101, 52)';

    document.querySelectorAll('[role="tab"][data-window]').forEach((tab) => {
        const tabs = Array.from(document.querySelectorAll('[role="tab"][data-window]'));

        function pick(next, focus) {
            tabs.forEach((t) => {
                const on = t === next;
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
                document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
            });
            if (focus) next.focus();
        }

        tab.addEventListener('click', () => pick(tab));
        tab.addEventListener('keydown', (event) => {
            const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];
            if (!step) return;
            event.preventDefault();
            pick(tabs[(tabs.indexOf(tab) + step + tabs.length) % tabs.length], true);
        });
    });

    if (document.getElementById('transactionsChart')) {
        new Chart(document.getElementById('transactionsChart'), {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Completed',
                    data: chartData.transactions,
                    borderColor: brand,
                    backgroundColor: 'rgba(22, 101, 52, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 } }
                }
            }
        });
    }

    if (document.getElementById('revenueChart')) {
        new Chart(document.getElementById('revenueChart'), {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Charge',
                    data: chartData.revenue_ugx,
                    backgroundColor: 'rgba(22, 101, 52, 0.8)',
                    borderColor: brand,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    }

    @can('view-profit')
    if (document.getElementById('profitChart')) {
        new Chart(document.getElementById('profitChart'), {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Charge',
                        data: chartData.revenue_ugx,
                        backgroundColor: 'rgba(22, 101, 52, 0.8)'
                    },
                    {
                        label: 'FX profit',
                        data: chartData.exchange_ugx,
                        backgroundColor: 'rgba(37, 99, 235, 0.8)'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (item) {
                                return item.dataset.label + ': UGX ' + item.parsed.y.toLocaleString('en', {maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true }
                }
            }
        });
    }
    @endcan

    // The stamp is right to the minute, so it is redrawn every ten seconds and never shows a
    // running second: a digit that changes each second pulls the eye off the balances. The day is
    // carried beside the time on a wide board and dropped on a phone, where the board is opened
    // many times in the same day.
    function updateClock() {
        const stamp = document.getElementById('liveClock');
        if (!stamp) return;

        const now = new Date();
        const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        let h = now.getHours();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        const time = `${h}:${String(now.getMinutes()).padStart(2, '0')} ${ampm}`;
        const text = window.matchMedia('(min-width: 640px)').matches
            ? `${days[now.getDay()]}, ${now.getDate()} ${months[now.getMonth()]} ${now.getFullYear()} · ${time}`
            : time;

        if (stamp.textContent !== text) stamp.textContent = text;
    }
    updateClock();
    setInterval(updateClock, 10000);
    window.addEventListener('resize', updateClock);

    // The button is disabled and spun before the reload so the click is never silent, and a
    // second click cannot queue a second reload. The short pause lets that state paint first.
    function reloadDashboard(button) {
        button.disabled = true;
        button.classList.add('opacity-60', 'cursor-wait');
        button.querySelector('span').textContent = 'Reloading...';
        // On a phone the word is dropped and only the glyph is shown, so the state has to be
        // carried by the name the button is read under as well.
        button.setAttribute('aria-label', 'Reloading this board');
        button.querySelector('svg').classList.add('animate-spin');
        setTimeout(function () { window.location.reload(); }, 150);
    }

    (function () {
        var modal = document.getElementById('hand-in-modal');
        var opener = document.querySelector('[data-hand-in-open]');

        if (!modal || !opener) { return; }

        var panel = modal.querySelector('.hand-in-modal-panel');
        var previousOverflow = '';
        var lastFocus = opener;

        // A failed submission names the field in the summary, so focus starts there; a fresh open
        // starts on the dialog itself rather than a select, which would throw the phone's picker
        // up before the person has read what is being asked.
        function focusStart() {
            var summary = modal.querySelector('[data-error-summary]');
            (summary || panel).focus({ preventScroll: true });
        }

        function openDialog() {
            if (!modal.hidden) { return; }

            lastFocus = document.activeElement && document.activeElement !== document.body
                ? document.activeElement
                : opener;

            modal.hidden = false;
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            opener.setAttribute('aria-expanded', 'true');
            focusStart();
        }

        function closeDialog() {
            if (modal.hidden) { return; }

            modal.hidden = true;
            document.body.style.overflow = previousOverflow;
            opener.setAttribute('aria-expanded', 'false');
            lastFocus.focus({ preventScroll: true });
        }

        // A failed submission comes back with the dialog already open and the page behind it
        // still locked, so the errors are not skipped past by a scroll at the top of the board.
        if (!modal.hidden) {
            lastFocus = opener;
            previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            opener.setAttribute('aria-expanded', 'true');
            focusStart();
        }

        opener.addEventListener('click', function (event) {
            // Leave modified clicks alone: the wallet page is still the fallback the link names.
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) { return; }
            event.preventDefault();
            openDialog();
        });

        modal.addEventListener('click', function (event) {
            if (event.target.closest('[data-hand-in-dismiss]')) { closeDialog(); }
        });

        modal.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeDialog();
                return;
            }

            if (event.key !== 'Tab') { return; }

            // aria-modal hides the board from a screen reader, but the keyboard still walks out
            // of the panel without this, so Tab wraps between the first and last control.
            var items = Array.prototype.filter.call(
                panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'),
                function (item) { return item.offsetParent !== null; }
            );

            if (items.length === 0) { event.preventDefault(); return; }

            var at = items.indexOf(document.activeElement);

            if (event.shiftKey && at <= 0) {
                event.preventDefault();
                items[items.length - 1].focus();
            } else if (!event.shiftKey && at === items.length - 1) {
                event.preventDefault();
                items[0].focus();
            }
        });

        var money = document.getElementById('hand-in-currency');
        var amount = document.getElementById('hand-in-amount');
        var hint = document.getElementById('hand-in-amount-hint');

        if (!money || !amount) { return; }

        var held = function () { return money.options[money.selectedIndex].dataset.balance; };
        var showHeld = function () {
            if (hint) { hint.textContent = 'Cannot be more than the ' + money.value + ' wallet holds right now.'; }
        };

        // The amount follows the wallet it names until the person takes over the typing,
        // exactly as the same form does on the wallet page.
        money.dataset.held = held();
        amount.max = held();
        money.addEventListener('change', function () {
            if (amount.value === '' || amount.value === money.dataset.held) {
                amount.value = held() === '0' ? '' : held();
            }
            money.dataset.held = held();
            amount.max = held();
            showHeld();
        });
    })();
</script>
@endsection
