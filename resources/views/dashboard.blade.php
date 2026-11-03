@extends('layouts.app')

@section('title', 'Dashboard')

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
    // The desk's name itself is not printed here any more, because the sidebar footer under it
    // already reads "Agent · Uganda". The one exception is a desk whose country was never filled
    // in: the balances below follow Uganda by default, so the board has to say the country is
    // unset rather than leave a reader guessing which till they are looking at.
    $desk = $me->countryLabel();


    // Stored UGX, not a fresh conversion, so these match the Reports page. A shilling figure is
    // always whole because the coins are not in circulation, and a dollar or yuan figure always
    // carries its two decimals. A zero keeps its own currency sign rather than turning into a word:
    // "UGX 0" says the till is empty, "nothing" does not say which till.
    $money = fn ($ugx, $usd, ?int $dec = null) => $usdToUgx
        ? 'UGX ' . number_format($ugx, $dec ?? 0)
        : '$' . number_format($usd, $dec ?? 2);
    $shilling = fn ($value) => $value < 0
        ? 'Loss UGX '.number_format(abs($value), 0)
        : 'UGX '.number_format($value, 0);
    $shillingTone = fn ($value) => $value < 0 ? 'text-red-600' : 'text-gray-900';

    // One card set per window, so no figure is printed in two places on the same page. The figures
    // are split by the side of the corridor they stand on, because "Yuan To Receive In China" only
    // reads next to the other China numbers, and a desk that works one side should be able to read
    // its own half without sorting the rows out itself.
    $panels = [];
    foreach (['today' => 'Today', 'month' => 'This Month', 'all' => 'All Time'] as $key => $label) {
        $t = $windows[$key];
        $groups = [
            'Uganda side' => [
                ['Completed', number_format($t['completed']), 'text-gray-900'],
                ['Sent', $money($t['sent_ugx'], $t['sent_usd']), 'text-gray-900'],
                ['To Be Received', $money($t['pending_ugx'], $t['pending_usd']), 'text-gray-900', 'awaiting payout', $t['latest_at']],
                ['Received In Uganda', \App\Models\Transaction::formatPayouts($deskPayouts[$key]['uganda'], 'UGX'), 'text-gray-900',
                    'paid out from bookings made in China'],
                ['Charge', $money($t['charge_ugx'], $t['charge_usd']), 'text-gray-900'],
            ],
            'China side' => [
                ['Yuan To Receive In China', '¥' . number_format($t['yuan_in_uganda'], 2), 'text-gray-900',
                    'from money held in Uganda'],
                ['Yuan In China, Unpaid', '¥' . number_format($t['yuan_in_china'], 2), 'text-gray-900',
                    $t['yuan_in_china_transfers'] . ' waiting for receiver'],
                ['Yuan Paid In China', '¥' . number_format($t['yuan_paid'], 2), 'text-gray-900',
                    $t['yuan_paid_transfers'] . ' paid to receivers'],
                ['Withdrawn In China', \App\Models\Transaction::formatPayouts($deskPayouts[$key]['china'], 'RMB'), 'text-gray-900',
                    'handed over to receivers in China'],
            ],
        ];
        // The desks the agents answer to get their own row: the slice of the window above that the
        // agents' own hands collected, plus the profit figures. Everyone else never sees them at all.
        // The taken figure names the country those agents stand in, so the two desks' cash stays
        // apart.
        $atTheCounter = [];
        if (isset($agentTaken[$key])) {
            $takenBy = 'Taken By Agents';
            if (count($agentTakenCountries) === 1) {
                $takenBy .= ' In '.$agentTakenCountries[0];
            } elseif (count($agentTakenCountries) > 1) {
                $takenBy .= ' In '.implode(' And ', $agentTakenCountries);
            }
            $atTheCounter[] = [$takenBy, $money($agentTaken[$key], 0), 'text-gray-900', 'principal plus charge collected at the counter'];
        }
        if (isset($profit[$key])) {
            $atTheCounter[] = ['FX profit', $shilling($profit[$key]['exchange']), $shillingTone($profit[$key]['exchange'])];
            $atTheCounter[] = ['Overall profit', $shilling($profit[$key]['total']), $shillingTone($profit[$key]['total'])];
        }
        if ($atTheCounter !== []) {
            $groups['Every desk'] = $atTheCounter;
        }

        $panels[$key] = [
            'label' => $label,
            'groups' => $groups,
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

    // The whole name is greeted, never just its first word, and an account named after nothing but
    // its own role is greeted by that role's display name. That work is done in the controller, as
    // $greetAs, because it has to read the role the same way the badge below does.

    // Kampala is three hours ahead of UTC all year, so the clock is stamped with the zone the desk
    // works in rather than whatever the laptop happens to be set to. The script that keeps it
    // ticking uses the same figure.
    $clockDate = now()->format('D, j M Y');
    $clockTime = now()->format('g:i A');
@endphp
{{-- The board opens with a plain white header: the greeting, the role, the hour and the two
     controls. The page title is kept for a screen reader and the sidebar already says which screen
     this is, so the big word "Dashboard" is gone. The desk's country and the account name are not
     repeated here either, because the sidebar footer under them carries both, word for word. --}}
<div class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm p-3 sm:p-4">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <h1 class="sr-only">Dashboard</h1>
        {{-- The mark follows the same hour bands as the words: a sun for the desk hours, a moon
             for the night shift. It carries no text, so it cannot repeat anything on the page. --}}
        <div class="flex min-w-0 items-center gap-3 mr-auto">
            <span aria-hidden="true" class="w-10 h-10 shrink-0 rounded-full flex items-center justify-center {{ $hour >= 6 && $hour < 18 ? 'bg-amber-100 text-amber-700' : 'bg-indigo-100 text-indigo-700' }}">
                @if($hour >= 6 && $hour < 18)
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4V2m0 20v-2m8-8h2M2 12h2m13.66-5.66l1.41-1.41M4.93 19.07l1.41-1.41m0-11.32L4.93 4.93m14.14 14.14l-1.41-1.41M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                @else
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.5 13.5A8.5 8.5 0 1110.5 3.5a7 7 0 0010 10z"/></svg>
                @endif
            </span>
            <p class="text-gray-900 mt-1">{{ $greeting }}, {{ $greetAs }}.</p>
        </div>
        {{-- The badge is kept even though the sidebar footer names the role too: it is the board's
             statement of what this account may do, and it sits beside the balances it governs. --}}
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 capitalize ring-1 ring-inset ring-green-200">{{ $role }}</span>
        {{-- No seconds: the figure only has to be right to the minute, and a digit running every
             second pulls the eye away from the balances. The zone is printed because every figure
             on this board is kept in Kampala time, whatever the machine reading it is set to. --}}
        <div class="text-right leading-tight">
            <span class="block text-[13px] text-gray-600 tabular-nums" id="liveClock" data-zone-offset="180">{{ $clockDate }} · {{ $clockTime }} EAT</span>
            {{-- Set once the board has been pulled again. Until then the line is empty rather than
                 guessing at a time nobody asked for. --}}
            <span class="block text-[13px] text-gray-400 tabular-nums" id="lastRefreshed"></span>
        </div>
        {{-- Reload sits here; the sidebar already carries Log out. --}}
        <div class="flex items-center gap-2 ml-auto sm:ml-0 no-print">
            @if($bellGate)
            <a href="{{ route('notifications.index') }}"
                aria-label="{{ $bellCount > 0 ? 'What happened, '.number_format($bellCount).' unread' : 'What happened' }}"
                class="relative inline-flex items-center justify-center w-10 h-10 sm:w-auto sm:h-auto px-0 sm:px-3 sm:py-1.5 rounded-full text-xs font-semibold border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
                <svg class="w-4 h-4 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <span class="hidden sm:inline">What happened</span>
                @if($bellCount > 0)
                <span class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold flex items-center justify-center leading-none tabular-nums">{{ $bellCount > 99 ? '99+' : $bellCount }}</span>
                @endif
            </a>
            @endif
            <button type="button" onclick="reloadDashboard(this)" aria-label="Reload this board"
                class="inline-flex items-center justify-center gap-1.5 w-10 h-10 sm:w-auto sm:h-auto px-0 sm:px-3 sm:py-1.5 rounded-full text-xs font-semibold bg-green-800 text-white hover:bg-green-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
                <svg class="w-4 h-4 sm:w-3.5 sm:h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span class="hidden sm:inline">Reload</span>
            </button>
        </div>
    </div>
    @unless($desk)
    <p class="text-[13px] text-gray-600 mt-2">{{ $desk ?: 'Desk country not set' }}. The balances below follow Uganda by default, so set the country on your account to see the right till.</p>
    @endunless
    {{-- Search lives inside the header so it does not take a card of its own. --}}
    <div class="relative mt-3" data-dashboard-search>
        <div class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 focus-within:ring-2 focus-within:ring-green-600 focus-within:bg-white">
            <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" id="dashboardSearch" placeholder="Search the desk"
                class="w-full bg-transparent outline-none text-sm text-gray-900 placeholder:text-gray-400"
                autocomplete="off" spellcheck="false"
                role="combobox" aria-expanded="false" aria-controls="dashboardSearchResults"
                aria-autocomplete="list" aria-label="Search the desk's pages">
            <kbd class="hidden sm:inline-block text-[11px] text-gray-400 border border-gray-200 rounded px-1.5 py-0.5 leading-5">/</kbd>
        </div>
        <div id="dashboardSearchResults" class="hidden absolute left-0 right-0 top-full mt-1.5 z-40 rounded-xl border border-gray-200 bg-white shadow-xl overflow-hidden">
            <ul id="dashboardSearchList" role="listbox" aria-label="Matching pages" class="max-h-72 overflow-y-auto py-1"></ul>
            <p id="dashboardSearchEmpty" class="hidden px-4 py-3 text-sm text-gray-500">Nothing on the desk matches that.</p>
        </div>
    </div>
</div>

@php
    // Shortcuts to the work people come here to do, the first card under the greeting: the actions
    // open the board rather than waiting under the till it describes. Each one is dropped when its
    // ability is missing, so nobody is handed a button that bounces back with a permission error,
    // and a role that books nothing at all gets no card rather than a row of dead links. Every
    // label starts with the verb for what the tile does, and is the words the screen it opens uses,
    // so one thing never carries two names. Sending opens with the corridor already chosen, since
    // which way the money goes is the first thing a desk knows about the booking standing in front
    // of it.
    //
    // A hand only ever sends one way: the cash is handed over where the desk is, so an agent in
    // Uganda sends to China and an agent in China sends to Uganda. Whoever sees the whole book
    // keys in for either desk, which the booking form already lets them do, so the lead agent and
    // the office are offered both roads rather than having to turn the form around by hand.
    //
    // The mark on the send tile is a note with a road leaving it, not a plain right arrow: an
    // arrow alone reads as "next", and this tile is the desk's main job rather than a step.
    $sendToChina = ['create-transaction', 'Send Money to China', 'Cash in Uganda, paid in China', route('transactions.create', ['destination' => \App\Models\Transaction::TO_CHINA]), 'M4 13a2 2 0 012-2h4.5a2 2 0 012 2v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4zM8 14.1a1.2 1.2 0 100 2.6 1.2 1.2 0 000-2.6zM13.5 12.5L20 5.5M15.5 5H20v4.5'];
    $sendToUganda = ['create-transaction', 'Send Money to Uganda', 'Cash in China, paid in Uganda', route('transactions.create', ['destination' => \App\Models\Transaction::TO_UGANDA]), 'M11 13a2 2 0 012-2h4.5a2 2 0 012 2v4a2 2 0 01-2 2h-4.5a2 2 0 01-2-2v-4zM15 14.1a1.2 1.2 0 100 2.6 1.2 1.2 0 000-2.6zM10.5 12.5L4 5.5M8.5 5H4v4.5'];
    $sendActions = $me->can('view-all-transactions')
        ? [$sendToChina, $sendToUganda]
        : [\App\Models\Transaction::destinationForDesk($me->country) === \App\Models\Transaction::TO_UGANDA ? $sendToUganda : $sendToChina];

    // The spending tile is named for the pen its reader holds. A desk that records its own
    // spending is offered the recording; a role that only reads the book is offered the book.
    $canWriteExpense = $me->can('create-expense') && ! $me->can('view-all-expenses');
    $expenseLabel = $canWriteExpense ? 'Record Expense' : 'Expenses';
    $expenseHint = match (true) {
        $canWriteExpense => 'Log what you spent today',
        $me->can('create-expense') && $me->can('view-all-expenses') => 'Record spending, read every entry',
        $me->can('view-all-expenses') => 'Every expense logged',
        default => 'What you spent',
    };

    // Money is withdrawn where the receiver collects it: receivers in China collect at a desk in
    // China, and receivers in Uganda collect at a desk in Uganda. A hand at one desk is offered
    // the queue at its own counter, while whoever sees the whole book is offered both, exactly as
    // the send tiles split. The payout pen clears either queue wherever its own desk stands, so
    // the same ability opens both pages.
    $canWithdraw = $me->can('view-transactions') && $me->can('confirm-china-payout');

    $withdrawGlyph = 'M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v10m0 0l-4-4m4 4l4-4';
    $withdrawChina = ['confirm-china-payout', 'Withdraw Money in China',
        $withdrawalsWaiting > 0
            ? number_format($withdrawalsWaiting).' receiver'.($withdrawalsWaiting === 1 ? '' : 's').' waiting to be paid'
            : 'Pay receivers of money sent from Uganda',
        route('china.index').'#payouts',
        $withdrawGlyph];
    $withdrawUganda = ['confirm-china-payout', 'Withdraw Money in Uganda',
        $ugandaWithdrawalsWaiting > 0
            ? number_format($ugandaWithdrawalsWaiting).' receiver'.($ugandaWithdrawalsWaiting === 1 ? '' : 's').' waiting to be paid'
            : 'Pay receivers of money sent from China',
        route('uganda.index').'#payouts',
        $withdrawGlyph];

    $withdrawActions = $canWithdraw
        ? ($me->can('view-all-transactions')
            ? [$withdrawChina, $withdrawUganda]
            : [($me->country ?: \App\Models\User::DEFAULT_COUNTRY) === 'CN' ? $withdrawChina : $withdrawUganda])
        : [];

    // Approving is one job with one home: the requests page. The shortcut names how many are
    // waiting and opens that page rather than keeping a second copy of the queue on this board.
    $approveAction = $me->hasRole('super-agent') ? [
        'approve-cash-handover',
        'Approve Cash Hand-In',
        $handoverQueue->isNotEmpty()
            ? number_format($handoverQueue->count()).' request'.($handoverQueue->count() === 1 ? '' : 's').' waiting for you'
            : 'Approve the cash agents hand over',
        route('cash-handovers.index'),
        'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    ] : null;

    // Handing cash in happens on the Transactions page, where a transfer's own row opens the
    // modal that raises the request. There is no flat-amount hand-in anymore.

    $quickActions = array_values(array_filter([
        ...$sendActions,
        ...$withdrawActions,
        ...($approveAction ? [$approveAction] : []),
        ['create-exchange', 'Record Exchange', 'Buy or sell notes', route('exchanges.create'), 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
        ['view-expenses', $expenseLabel, $expenseHint, route('expenses.index'), 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ['view-exchange-rates', 'Exchange Rates', "Today's prices", route('exchange-rates.index'), 'M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2zm3-14h4m-4 4h4m-4 4h4'],
        ['create-customer', 'Add Customer', 'Save a person', route('customers.create'), 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        // The lead agent is the one person besides the office who reads every agent's work, so the
        // whole book is a shortcut of its own rather than a trip through the sidebar.
        ['view-all-transactions', 'Transactions', 'Every transfer in the book', route('transactions.index'), 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
    ], fn (array $a) => $me->can($a[0])));
@endphp

<style>
    /* The tab look follows aria-selected alone, so the styling cannot drift from the state. */
    [role="tab"][aria-selected="true"] { background: #fff; color: #111827; box-shadow: 0 1px 2px 0 rgb(0 0 0 / .05); }
    [role="tab"][aria-selected="false"] { color: #4b5563; }
    [role="tab"][aria-selected="false"]:hover { color: #111827; }
    [role="tab"]:focus-visible { outline: 2px solid var(--brand-300, #86efac); outline-offset: 1px; }

    /* A figure takes the headline size whenever its card can hold it on one line and gives up
       only the pixels it needs. Digits and commas in tabular figures measure about half a font
       size each, so dividing the card by the length of the string is the largest size it fits.
       A browser without container queries keeps the 1.5rem above and behaves as it did before. */
    .stat-card { container-type: inline-size; }
    .stat-value { font-size: 1.5rem; font-size: clamp(.875rem, calc(100cqi / (var(--len) * .52)), 1.5rem); }
    /* The wallet's own figure is the one number the board is opened to read, so it is allowed a
       larger headline than the rest, but it gives size back before it ever gives up the line. */
    .balance-value { font-size: 1.5rem; font-size: clamp(1.05rem, calc(100cqi / (var(--len) * .52)), 1.875rem); }
</style>

{{-- The till comes next: the desk reads the counter price and the wallets it has to work with
     before it does anything with them. Account::currenciesFor() orders the wallets by the desk's
     country, so the local currency is always the first card. The controller hands an empty list to
     anyone who reads every wallet instead of holding one, and the row is left out for them.

     A card is one figure, its stamp and its links. The splits that used to stand under the balance
     (awaiting approval, fees, handed in) live on the wallet page and the reports, so the board
     stays clean. The two cards are held to the same height, and each card keeps its links on the
     bottom edge, so a pair reads as one row rather than two blocks of different sizes. --}}
@if($wallets !== [])
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 items-stretch mb-6">
    @foreach($wallets as $currency => $account)
    @php
        $balance = (string) $account->balance();
        $figure = fn (string|int|float $value) => \App\Models\Account::format($currency, $value);
    @endphp
    <div class="flex h-full flex-col bg-white rounded-xl border border-gray-200 shadow-sm p-5">
        {{-- The figure column is a track, not a flex item: a size container has no width of its
             own to measure against, so in a flex row it collapses and every line wraps to one
             word. The grid gives the column its width first and the query then reads it back. --}}
        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-3">
            <div class="stat-card min-w-0">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $currency }} Wallet</div>
                {{-- The balance is the figure the desk is opened to read, so it takes the largest
                     size its own column can hold on one line and gives up only what it must. --}}
                <a href="{{ route('wallet.index') }}" class="balance-value block mt-2 font-bold text-gray-900 tabular-nums whitespace-nowrap hover:underline" style="--len: {{ strlen($figure($balance)) }}">{{ $figure($balance) }}</a>
                <x-wallet-updated :at="$account->lastMovementAt()" class="mt-1" />
                {{-- Approved hand-ins left the drawer, so the money the agent is still answerable
                     for reads them back in. The cash and the charges are named apart, because the
                     request a reader can go and look up shows them on two lines, and one sum with no
                     parts looks like a figure that does not match the book. The line stays off the
                     card until there is a pile to add, so the balance is never printed twice. --}}
                @php
                    $cashHandedIn = (string) ($handoverInsight['approved_cash'][$currency] ?? '0.00');
                    $chargesHandedIn = $currency === 'UGX' ? (string) ($handoverInsight['approved_charges'] ?? '0.00') : '0.00';
                    $heldParts = [];
                    if (bccomp($cashHandedIn, '0', 2) > 0) { $heldParts[] = $figure($cashHandedIn).' handed in'; }
                    if (bccomp($chargesHandedIn, '0', 2) > 0) { $heldParts[] = $figure($chargesHandedIn).' charges'; }
                @endphp
                @if($heldParts !== [])
                @php $heldTotal = bcadd(bcadd((string) $account->balance(), $cashHandedIn, 2), $chargesHandedIn, 2); @endphp
                <p class="text-[13px] mt-2">
                    <span class="font-semibold text-gray-900 tabular-nums">Total held: {{ $figure($heldTotal) }}</span>
                    <span class="text-gray-500 tabular-nums"> (wallet + {{ implode(' + ', $heldParts) }})</span>
                </p>
                @endif
            </div>
            {{-- Decorative only. The line above already names the wallet, so the circle carries a
                 glyph rather than a second spelling of the same currency. --}}
            <span aria-hidden="true" class="w-10 h-10 shrink-0 rounded-full bg-green-100 text-green-800 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            </span>
        </div>

        @if(bccomp($balance, '0', 2) <= 0)
        <p class="text-[13px] text-gray-700 mt-2">No balance yet. @can('adjust-wallets')<a href="{{ route('accounts.entries.create') }}" class="font-semibold text-gray-800 underline underline-offset-2">Add money</a>@endcan</p>
        @endif

        <div class="mt-auto flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-gray-100 pt-3">
            @if($me->hasRole('agent'))
            <a href="{{ route('cash-handovers.index') }}" class="text-[13px] font-semibold text-green-800 hover:underline">History</a>
            @endif
            <a href="{{ route('wallet.index') }}" class="ml-auto text-[13px] font-semibold text-green-800 hover:underline">Open the wallet</a>
        </div>
    </div>
    @endforeach
</div>
@endif

{{-- The price the desk works from, straight under the tills it prices, and above the shortcuts that
     use it. A desk reads what it holds, then what it holds it at, then what it can do with it. --}}
<x-live-rate-card />

{{-- Shortcuts to the work people come here to do, under the till they act on. Each one is dropped
     when its ability is missing, so nobody is handed a button that bounces back with a permission
     error, and a role that books nothing at all gets no card rather than a row of dead links. --}}
@if($quickActions !== [])
<div class="rounded-xl border border-gray-200 bg-white shadow-sm p-4 sm:p-5 mb-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h2>
    {{-- Three across once the board is wide enough to hold the longest label on one line. At 1024
         the sidebar still stands, so a third column leaves each tile 216px and the words "Withdraw
         Money in Uganda" run over the chevron; two wider tiles keep every name readable instead of
         meeting the count asked for at a width where it cannot be shown. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        @foreach($quickActions as $action)
        @php [$ability, $label, $hint, $url, $glyph] = $action; @endphp
        {{-- Every tile is built the same way: a 40px mark, the words that name the screen it opens,
             one line of why, and the chevron that says it goes somewhere. No tile is dressed up as
             more important than another, because the desk picks by the job in front of it. --}}
        <a href="{{ $url }}" title="{{ $label }}" class="group flex items-center gap-3 min-w-0 rounded-lg border border-gray-200 bg-white p-3 transition hover:border-green-300 hover:bg-green-50 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
            <span aria-hidden="true" class="w-10 h-10 shrink-0 rounded-full flex items-center justify-center bg-green-100 text-green-800">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $glyph }}"/></svg>
            </span>
            <span class="min-w-0 flex-1">
                {{-- The name stays on one line and gives up its tail rather than painting over the
                     chevron; the whole label is on the tile itself, so a pointer reads it in full. --}}
                <span class="block text-sm font-semibold text-gray-900 whitespace-nowrap overflow-hidden text-ellipsis">{{ $label }}</span>
                <span class="block text-[13px] text-gray-500 line-clamp-2">{{ $hint }}</span>
            </span>
            <svg class="w-4 h-4 shrink-0 transition-transform group-hover:translate-x-0.5 text-gray-400 group-hover:text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </a>
        @endforeach
    </div>
</div>
@endif

@php
    // The money going out on both sides of the board sits on one row: what this hand has pushed up
    // to the lead agent, and what the desk spent today. Two cards of the same height on a wide
    // board, one under the other when the board narrows.
    $lowerCards = ($handoverInsight ? 1 : 0) + ($chargesCollected !== null ? 1 : 0) + ($me->can('view-expenses') ? 1 : 0);
@endphp
<div class="{{ $lowerCards > 0 ? 'mb-6' : '' }}{{ $lowerCards > 1 ? ' grid grid-cols-1 lg:grid-cols-2 gap-4 items-stretch' : '' }}">
{{-- An insight, not a desk: how much of this hand's cash has gone up to the lead agent, in whose
     hands it is sitting now, and how long ago the last one was sent. Every figure is this person's
     own, read from their account in the controller rather than from anything the address bar
     carries. --}}
@if($handoverInsight)
@php
    $insightLabels = [
        \App\Models\CashHandover::PENDING => 'Pending',
        \App\Models\CashHandover::APPROVED => 'Approved',
        \App\Models\CashHandover::DECLINED => 'Sent back',
    ];
    // The three piles are the three answers the lead agent can give, so each keeps the colour the
    // requests page uses for it: a hand-in still sitting with the lead is the one to chase, an
    // approved one is settled money, a sent-back one needs doing again.
    $insightTones = [
        \App\Models\CashHandover::PENDING => 'border-l-4 border-l-amber-400',
        \App\Models\CashHandover::APPROVED => 'border-l-4 border-l-green-600',
        \App\Models\CashHandover::DECLINED => 'border-l-4 border-l-red-500',
    ];
    $insightDots = [
        \App\Models\CashHandover::PENDING => 'bg-amber-400',
        \App\Models\CashHandover::APPROVED => 'bg-green-600',
        \App\Models\CashHandover::DECLINED => 'bg-red-500',
    ];
    $nilCurrency = array_key_first($wallets) ?? 'UGX';
    $lastSent = $handoverInsight['latest'];
@endphp
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 h-full flex flex-col">
    <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-base font-semibold text-gray-900 mr-auto">Cash You Hand In</h2>
        <a href="{{ route('cash-handovers.index') }}" class="text-sm font-semibold text-green-800 hover:underline">Every hand-in</a>
    </div>
    <ul class="mt-3 grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3 gap-3 flex-1">
        @foreach($handoverInsight['statuses'] as $status => $group)
        @php
            $totals = collect($group['totals'])
                ->filter(fn ($value) => bccomp((string) $value, '0', 2) > 0)
                ->map(fn ($value, string $each) => \App\Models\Account::format($each, $value))
                ->values()
                ->all();
        @endphp
        <li class="rounded-lg border border-gray-200 {{ $insightTones[$status] ?? '' }} p-3">
            <div class="flex items-center gap-1.5 text-[13px] font-semibold uppercase tracking-wide text-gray-500">
                <span aria-hidden="true" class="w-2 h-2 shrink-0 rounded-full {{ $insightDots[$status] ?? 'bg-gray-400' }}"></span>
                <span>{{ $insightLabels[$status] ?? ucfirst($status) }}</span>
            </div>
            <div class="text-xl font-bold text-gray-900 mt-1 tabular-nums whitespace-nowrap">{{ number_format($group['count']) }} {{ $group['count'] === 1 ? 'request' : 'requests' }}</div>
            <div class="text-[13px] text-gray-700 mt-0.5 tabular-nums">{{ $totals === [] ? \App\Models\Account::format($nilCurrency, 0) : implode(' and ', $totals) }}</div>
        </li>
        @endforeach
    </ul>
    @if($lastSent)
    <p class="text-[13px] text-gray-700 mt-3">
        The last one was {{ $lastSent->figure() }}, {{ strtolower($insightLabels[$lastSent->status] ?? $lastSent->status) }} ({{ $lastSent->reference }}).
    </p>
    <x-wallet-updated :at="$lastSent->created_at" label="Sent" class="mt-0.5" />
    @else
    <p class="text-[13px] text-gray-700 mt-3">Nothing handed in yet. Cash you send stays in your balance until the lead agent approves it.</p>
    @endif
</div>
@endif

{{-- The lead's own earnings: the charges on the bookings they took plus the charges the agents
     handed in with their cash, this month, in shillings. The wallet page keeps the history; the
     card names the month's figure on the screen the desk opens first. --}}
@if($chargesCollected !== null)
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 h-full flex flex-col">
    <div class="flex flex-wrap items-center gap-2">
        <h2 class="text-base font-semibold text-gray-900 mr-auto">Charges Collected</h2>
        <a href="{{ route('wallet.index') }}" class="text-sm font-semibold text-green-800 hover:underline">Open the wallet</a>
    </div>
    <div class="text-2xl font-bold text-gray-900 mt-2 tabular-nums whitespace-nowrap">{{ \App\Models\Account::format('UGX', $chargesCollected) }}</div>
    <p class="text-[13px] text-gray-700 mt-1">{{ $feesPeriod }}: your bookings' charges and what the agents handed in.</p>
</div>
@endif

{{-- Money going out on the same day as the money coming in beside it. Each desk keeps its own
     totals, because shillings spent in Kampala and yuan spent in Guangzhou are not one number. A
     day with nothing logged is a fact worth one line, not a box the height of the card next to it. --}}
@can('view-expenses')
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 h-full flex flex-col">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-base font-semibold text-gray-900">Spent Today</h2>
        <a href="{{ route('expenses.index') }}" class="text-sm font-semibold text-green-800 hover:underline">Every entry</a>
    </div>
    @if($spending['desks'] === [])
        <div class="mt-3 flex flex-1 flex-col items-start justify-center gap-3 rounded-lg border border-dashed border-gray-200 px-4 py-5">
            <p class="text-sm text-gray-500">Nothing recorded as spent today.</p>
            @can('create-expense')
            <a href="{{ route('expenses.create') }}" class="inline-flex items-center gap-1.5 rounded-md border border-green-800 px-3 py-1.5 text-[13px] font-semibold text-green-800 hover:bg-green-50">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Record expense
            </a>
            @endcan
        </div>
    @else
    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 flex-1">
        @foreach($spending['desks'] as $spendDesk)
        @php
            $who = collect($spendDesk['people'])->take(3);
            $rest = count($spendDesk['people']) - $who->count();
        @endphp
        <div class="stat-card min-w-0 rounded-lg border border-gray-200 bg-white p-3">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $spendDesk['label'] }}</div>
            @foreach($spendDesk['totals'] as $currency => $amount)
            <div class="stat-value mt-2 font-bold text-gray-900 tabular-nums whitespace-nowrap" style="--len: {{ strlen(\App\Models\Account::format($currency, $amount)) }}">{{ \App\Models\Account::format($currency, $amount) }}</div>
            @endforeach
            <div class="text-xs text-gray-500 mt-2 tabular-nums">
                {{ number_format($spendDesk['count']) }} {{ $spendDesk['count'] === 1 ? 'entry' : 'entries' }} today
                @if($who->isNotEmpty()) · {{ $who->implode(', ') }}@if($rest > 0) and {{ $rest }} more @endif @endif
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>
@endcan
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 mb-6">
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
        {{-- Three windows, one row of figures. The tabs sit right of the title on a wide board and
             take the whole width under it, where three small tabs are too tight to press. --}}
        <div role="tablist" aria-label="Time window" class="inline-flex w-full sm:w-auto gap-1 rounded-lg border border-gray-200 bg-gray-50 p-1">
            @foreach($panels as $key => $panel)
            <button type="button" role="tab" id="tab-{{ $key }}" data-window="{{ $key }}" aria-controls="panel-{{ $key }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}" tabindex="{{ $loop->first ? '0' : '-1' }}" class="flex-1 sm:flex-none rounded-md px-3 py-1.5 text-sm font-medium">{{ $panel['label'] }}</button>
            @endforeach
        </div>
    </div>

    @foreach($panels as $key => $panel)
    <div role="tabpanel" id="panel-{{ $key }}" aria-labelledby="tab-{{ $key }}" @if(! $loop->first) hidden @endif>
        @foreach($panel['groups'] as $groupLabel => $groupCards)
        <div class="{{ $loop->first ? '' : 'mt-6' }}">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">{{ $groupLabel }}</h3>
            {{-- Three across on a desk board, two on a tablet, one on a phone. The card sizes its own
                 number down only as far as it has to, so a long figure or a loss stays on one line at
                 the headline size rather than spilling past the border a fixed size would give it. --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($groupCards as $card)
                @php [$cardLabel, $cardValue, $cardTone, $cardNote, $cardStamp] = array_pad($card, 5, null); @endphp
                <div class="stat-card min-w-0 rounded-lg border border-gray-200 bg-white p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $cardLabel }}</div>
                    <div class="stat-value mt-2 font-bold {{ $cardTone }} tabular-nums whitespace-nowrap" style="--len: {{ strlen($cardValue) }}">{{ $cardValue }}</div>
                    @if($cardNote !== null)
                    <div class="text-xs text-gray-500 mt-1">{{ $cardNote }}</div>
                    @endif
                    @if($cardStamp !== null)
                    <x-wallet-updated :at="$cardStamp" label="Latest booking" class="mt-1 text-xs" />
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
        <p class="text-xs text-gray-500 mt-4 tabular-nums">{{ number_format($panel['pending']) }} pending · {{ number_format($panel['cancelled']) }} cancelled · average transfer {{ $money($panel['avg_ugx'], $panel['avg_usd']) }}</p>
        @if($panel['yuanTotal'] > 0)
        <p class="text-xs text-gray-500 mt-2">The three yuan cards under China side are the same ¥{{ number_format($panel['yuanTotal'], 2) }} of booked yuan split by whose hands it is in: held in Uganda until its
            dollars are sent, with a desk in China until the receiver is paid, then paid. Withdrawn
            In China and Received In Uganda are the money the receivers were actually handed, in the
            currency each one was paid.
            @canany(['send-to-china', 'confirm-china-payout'])
            <a href="{{ route('china.index') }}" class="text-green-800 font-medium hover:underline">Move the money at the China Desk</a>.
            @endcanany
        </p>
        @endif
        @if($panel['unpriced'] > 0)
        <p class="text-xs text-amber-700 mt-2 tabular-nums">{{ number_format($panel['unpriced']) }} without rates</p>
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
    <a href="{{ route('admin.audit-logs', ['tab' => 'login', 'status' => 'failed']) }}" class="bg-amber-50 border border-amber-200 rounded-xl shadow-sm p-5 hover:shadow transition-shadow block">
        <div class="text-xs font-semibold uppercase tracking-wide text-amber-800">Failed sign-ins (24h)</div>
        <div class="text-2xl font-bold text-amber-900 mt-2 tabular-nums">{{ number_format($logAlerts['failed_logins_24h']) }}</div>
        <div class="text-xs text-amber-800 mt-1">Check the Audit Logs for a brute-force pattern.</div>
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

{{-- The two seven-day pictures sit side by side on a desk board. A week with nothing in it is one
     short line, not a box drawn at chart height: an empty frame the size of the figure it is
     missing makes an idle week look like the biggest thing on the screen. --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 min-w-0">
        <h2 class="text-base font-semibold text-gray-900 mb-4">Completed Transfers (Last 7 Days)</h2>
        @if($hasTransfers)
        <div class="relative h-[250px] max-w-full">
            <canvas id="transactionsChart"></canvas>
        </div>
        @else
        <div class="flex min-h-[120px] flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-gray-200 px-4 py-5 text-center">
            <svg class="h-5 w-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M7 15l4-5 3 3 5-7"/></svg>
            <p class="text-sm text-gray-500">No transfers in the last 7 days.</p>
        </div>
        @endif
    </div>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-5 min-w-0">
        @can('view-profit')
        <h2 class="text-base font-semibold text-gray-900 mb-4">Profit (Last 7 Days)</h2>
        @if($hasCharge || $hasExchange)
        <div class="relative h-[250px] max-w-full">
            <canvas id="profitChart"></canvas>
        </div>
        @else
        <div class="flex min-h-[120px] flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-gray-200 px-4 py-5 text-center">
            <svg class="h-5 w-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M7 15l4-5 3 3 5-7"/></svg>
            <p class="text-sm text-gray-500">No charge or FX profit in the last 7 days.</p>
        </div>
        @endif
        @else
        <h2 class="text-base font-semibold text-gray-900 mb-4">Charge Collected (Last 7 Days)</h2>
        @if($hasCharge)
        <div class="relative h-[250px] max-w-full">
            <canvas id="revenueChart"></canvas>
        </div>
        @else
        <div class="flex min-h-[120px] flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-gray-200 px-4 py-5 text-center">
            <svg class="h-5 w-5 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M7 15l4-5 3 3 5-7"/></svg>
            <p class="text-sm text-gray-500">No charge collected in the last 7 days.</p>
        </div>
        @endif
        @endcan
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6">
    <div class="px-4 sm:px-5 py-4 border-b border-gray-200 flex flex-wrap justify-between items-center gap-3">
        {{-- The button that used to sit here booked the same thing as Send Money in Quick
             Actions above, under a second name. One call per action per page. --}}
        <h2 class="text-base font-semibold text-gray-900">Recent Transactions</h2>
        @can('view-transactions')
        <a href="{{ route('transactions.index') }}" class="text-sm font-semibold text-green-800 hover:underline">View all</a>
        @endcan
    </div>
    {{-- A desk board shows the book as rows and keeps the column names on screen while the rows
         run: the list scrolls inside its own box, so a header that stays put is the only thing
         telling a reader which column of money they are looking at. Names that do not fit are cut
         with the whole name carried in the title, so a long sender is never half-shown.
         The cells take 12px of side padding rather than the usual 16 because the date column keeps
         its hour and AM/PM, and at 1280 the eight columns together need every one of those pixels
         to stay inside the box instead of opening a sideways scroll under a board that fits. --}}
    <div class="hidden md:block overflow-auto max-h-[26rem]">
        <table class="w-full min-w-[720px] divide-y divide-gray-200">
            <thead class="bg-gray-50 sticky top-0 z-10">
                <tr>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Receipt #</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Sender</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Receiver</th>
                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Amount</th>
                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Paid out</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Status</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Date</th>
                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wide whitespace-nowrap">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($recentTransactions as $t)
                <tr>
                    <td class="px-3 py-3 text-sm font-medium text-green-800 whitespace-nowrap tabular-nums">
                        <a href="{{ route('receipts.show', $t->receipt_number) }}">{{ $t->receipt_number }}</a>
                    </td>
                    <td class="px-3 py-3 text-sm text-gray-900 max-w-[11rem] truncate" title="{{ $t->sender_name }}">{{ $t->sender_name }}</td>
                    <td class="px-3 py-3 text-sm text-gray-900 max-w-[11rem] truncate" title="{{ $t->receiver_name }}">{{ $t->receiver_name }}</td>
                    <td class="px-3 py-3 text-sm text-right text-gray-900 whitespace-nowrap tabular-nums">{{ $t->principalLabel() }}</td>
                    <td class="px-3 py-3 text-sm text-right text-gray-900 whitespace-nowrap tabular-nums" title="{{ $t->payoutAmount() === null ? 'No handed-over figure on this booking' : 'Handed to the receiver in '.\App\Models\Account::currencyLabel($t->payoutCurrency()) }}">
                        {{ $t->payoutAmount() === null ? '-' : \App\Models\Account::format($t->payoutCurrency(), $t->payoutAmount()) }}
                    </td>
                    <td class="px-3 py-3 whitespace-nowrap">
                        <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full whitespace-nowrap
                            @if($t->status === 'completed') bg-green-100 text-green-800
                            @elseif($t->status === 'pending') bg-amber-100 text-amber-800
                            @else bg-red-100 text-red-800 @endif">
                            {{ ucfirst($t->status) }}
                        </span>
                    </td>
                    <td class="px-3 py-3 text-sm text-gray-500 whitespace-nowrap tabular-nums">{{ $t->created_at->format('M j, Y, g:i A') }}</td>
                    <td class="px-3 py-3 text-sm whitespace-nowrap">
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
    {{-- On a phone the same rows become cards, because eight columns cannot be read at once and a
         sideways scroll across the whole board makes every row feel broken. The money and its stamp
         keep the order the table uses, so one list is being read either way. --}}
    <ul class="md:hidden divide-y divide-gray-200">
        @forelse($recentTransactions as $t)
        <li class="p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <a href="{{ route('receipts.show', $t->receipt_number) }}" class="text-sm font-semibold text-green-800 tabular-nums">{{ $t->receipt_number }}</a>
                <span class="px-2 py-1 text-xs font-medium rounded-full whitespace-nowrap
                    @if($t->status === 'completed') bg-green-100 text-green-800
                    @elseif($t->status === 'pending') bg-amber-100 text-amber-800
                    @else bg-red-100 text-red-800 @endif">
                    {{ ucfirst($t->status) }}
                </span>
            </div>
            <p class="text-sm text-gray-900 mt-2">{{ $t->sender_name }} <span aria-hidden="true">&rarr;</span> {{ $t->receiver_name }}</p>
            <p class="text-sm font-semibold text-gray-900 mt-1 tabular-nums">{{ $t->principalLabel() }}
                <span class="font-normal text-gray-500">&middot; {{ $t->payoutAmount() === null ? '-' : \App\Models\Account::format($t->payoutCurrency(), $t->payoutAmount()) }}</span>
            </p>
            <p class="text-xs text-gray-500 mt-1 tabular-nums">{{ $t->created_at->format('M j, Y, g:i A') }}</p>
            <p class="mt-2 flex gap-4 text-sm">
                <a href="{{ route('receipts.print', $t->receipt_number) }}" target="_blank" class="text-green-600 hover:text-green-800">Print</a>
                <a href="{{ route('receipts.show', $t->receipt_number) }}" class="text-green-600 hover:text-green-800">View</a>
            </p>
        </li>
        @empty
        <li class="p-4 text-sm text-gray-500">
            No transactions yet.
            @can('create-transaction') Book the first one from <a href="{{ route('transactions.create') }}" class="text-green-700 underline">Send Money</a>.@endcan
        </li>
        @endforelse
    </ul>
</div>

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
    //
    // The desk's money is kept in Kampala time, so the clock is too. The zone offset is read off
    // the clock itself rather than taken from the machine, because a laptop set to another region
    // would otherwise print a foreign hour beside figures that are not in it.
    function kampalaNow(from) {
        const stamp = document.getElementById('liveClock');
        const offset = stamp ? parseInt(stamp.dataset.zoneOffset || '180', 10) : 180;
        const base = from || new Date();

        return new Date(base.getTime() + (base.getTimezoneOffset() + offset) * 60000);
    }

    const clockDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const clockMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    function clockWords(now) {
        let h = now.getHours();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;

        return `${h}:${String(now.getMinutes()).padStart(2, '0')} ${ampm} EAT`;
    }

    function updateClock() {
        const stamp = document.getElementById('liveClock');
        if (!stamp) return;

        const now = kampalaNow();
        const time = clockWords(now);
        const text = window.matchMedia('(min-width: 640px)').matches
            ? `${clockDays[now.getDay()]}, ${now.getDate()} ${clockMonths[now.getMonth()]} ${now.getFullYear()} · ${time}`
            : time;

        if (stamp.textContent !== text) stamp.textContent = text;

        // The age on a balance rides the same tick, so the two can never disagree while the page
        // sits open.
        if (window.refreshStamps) window.refreshStamps();
    }
    updateClock();
    setInterval(updateClock, 10000);
    window.addEventListener('resize', updateClock);

    // A reload gives nothing back but a spinner for the moment before the page is gone, so the
    // moment is kept across the reload and said out loud once the fresh figures land.
    const refreshedKey = 'pluspay.dashboard.lastRefreshed';

    function rememberRefresh() {
        try { sessionStorage.setItem(refreshedKey, String(Date.now())); } catch (error) { return; }
    }

    function sayWhenItWasRefreshed() {
        const line = document.getElementById('lastRefreshed');
        if (!line) return;

        let at = null;
        try { at = sessionStorage.getItem(refreshedKey); } catch (error) { return; }
        if (!at) return;

        line.textContent = 'Last refreshed ' + clockWords(kampalaNow(new Date(Number(at))));
    }

    // The button is disabled and spun before the reload so the click is never silent, and a
    // second click cannot queue a second reload. The short pause lets that state paint first.
    function reloadDashboard(button) {
        rememberRefresh();
        button.disabled = true;
        button.classList.add('opacity-60', 'cursor-wait');
        button.querySelector('span').textContent = 'Reloading...';
        // On a phone the word is dropped and only the glyph is shown, so the state has to be
        // carried by the name the button is read under as well.
        button.setAttribute('aria-label', 'Reloading this board');
        button.querySelector('svg').classList.add('animate-spin');
        setTimeout(function () { window.location.reload(); }, 150);
    }

    sayWhenItWasRefreshed();

    // The desk search offers exactly the pages the sidebar shows this account, because the index
    // is read off the sidebar's own links. A role change needs no second list here, and a link a
    // permission gate hides can never be offered by the box.
    (function () {
        const box = document.querySelector('[data-dashboard-search]');
        if (!box) return;

        const input = document.getElementById('dashboardSearch');
        const panel = document.getElementById('dashboardSearchResults');
        const list = document.getElementById('dashboardSearchList');
        const empty = document.getElementById('dashboardSearchEmpty');

        function plain(node) {
            const clone = node.cloneNode(true);
            clone.querySelectorAll('svg, span[class*="rounded-full"]').forEach(function (el) { el.remove(); });
            return clone.textContent.trim().replace(/\s+/g, ' ');
        }

        const pages = Array.from(document.querySelectorAll('#appSidebar nav a[href]'))
            .filter(function (link) { return !link.getAttribute('href').startsWith('#'); })
            .map(function (link) {
                const holder = link.closest('div[id$="Group"]');
                const group = holder && holder.previousElementSibling ? plain(holder.previousElementSibling) : '';
                const label = plain(link);

                return { label: label, group: group, href: link.getAttribute('href') };
            })
            .filter(function (page) { return page.label !== ''; });

        function rank(page, q) {
            const label = page.label.toLowerCase();
            if (label === q) return 0;
            if (label.startsWith(q)) return 1;
            if (label.includes(q)) return 2;
            if (page.group.toLowerCase().includes(q)) return 3;
            return -1;
        }

        let active = -1;

        function paintActive() {
            Array.from(list.children).forEach(function (li, i) {
                li.classList.toggle('bg-green-50', i === active);
            });
        }

        function render() {
            const q = input.value.trim().toLowerCase();

            list.innerHTML = '';
            active = -1;

            let hits = [];
            if (q !== '') {
                hits = pages
                    .map(function (page) { return [rank(page, q), page]; })
                    .filter(function (pair) { return pair[0] >= 0; })
                    .sort(function (a, b) { return a[0] - b[0] || a[1].label.localeCompare(b[1].label); })
                    .map(function (pair) { return pair[1]; })
                    .slice(0, 8);
            }

            hits.forEach(function (page, i) {
                const li = document.createElement('li');
                li.id = 'search-result-' + i;
                li.setAttribute('role', 'option');
                li.dataset.href = page.href;
                li.className = 'px-4 py-2.5 flex items-baseline gap-3 cursor-pointer hover:bg-green-50';

                const name = document.createElement('span');
                name.className = 'text-sm font-medium text-gray-900 truncate';
                name.textContent = page.label;

                const where = document.createElement('span');
                where.className = 'text-xs text-gray-500 ml-auto shrink-0';
                where.textContent = page.group || 'Page';

                li.appendChild(name);
                li.appendChild(where);
                li.addEventListener('click', function () { window.location.href = page.href; });
                list.appendChild(li);
            });

            empty.classList.toggle('hidden', hits.length > 0);
            list.classList.toggle('hidden', hits.length === 0);
            panel.classList.toggle('hidden', q === '');
            input.setAttribute('aria-expanded', q === '' ? 'false' : 'true');
        }

        function go() {
            const items = Array.from(list.children);
            const target = items[active] || items[0];
            if (target && target.dataset.href) window.location.href = target.dataset.href;
        }

        input.addEventListener('input', render);
        input.addEventListener('focus', function () { if (input.value.trim() !== '') panel.classList.remove('hidden'); });
        input.addEventListener('keydown', function (event) {
            const items = Array.from(list.children);

            if (event.key === 'ArrowDown') { event.preventDefault(); active = Math.min(active + 1, items.length - 1); paintActive(); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); active = Math.max(active - 1, 0); paintActive(); }
            else if (event.key === 'Enter') { event.preventDefault(); go(); }
            else if (event.key === 'Escape') { panel.classList.add('hidden'); input.setAttribute('aria-expanded', 'false'); }
        });

        document.addEventListener('click', function (event) {
            if (!box.contains(event.target)) {
                panel.classList.add('hidden');
                input.setAttribute('aria-expanded', 'false');
            }
        });

        // The slash opens the box from anywhere on the board, unless the hands are already
        // typing somewhere.
        document.addEventListener('keydown', function (event) {
            if (event.key !== '/') return;
            const target = event.target;
            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable)) return;
            event.preventDefault();
            input.focus();
            input.select();
        });
    })();

</script>
@endsection
