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
    $desk = $me->countryLabel();

    // Stored UGX, not a fresh conversion, so these match the Reports page. A charge or a profit
    // keeps its shilling cents because that is what gets reconciled; what merely travelled is
    // rounded to the whole shilling.
    $money = fn ($ugx, $usd, int $dec = 2) => $usdToUgx ? 'UGX ' . number_format($ugx, $dec) : '$' . number_format($usd, $dec);
    $shilling = fn ($value) => $value < 0
        ? 'Loss UGX '.number_format(abs($value), 2)
        : 'UGX '.number_format($value, 2);
    $shillingTone = fn ($value) => $value < 0 ? 'text-red-600' : 'text-gray-900';

    // One card set per window, so no figure is printed in two places on the same page.
    $panels = [];
    foreach (['today' => 'Today', 'month' => 'This Month', 'all' => 'All Time'] as $key => $label) {
        $t = $windows[$key];
        $cards = [
            ['Completed', number_format($t['completed']), 'text-gray-900'],
            ['Sent', $money($t['sent_ugx'], $t['sent_usd'], 0), 'text-gray-900'],
            ['Yuan out', '¥' . number_format($t['rmb'], 2), 'text-gray-900'],
            ['Charge', $money($t['charge_ugx'], $t['charge_usd'], 2), 'text-gray-900'],
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
        ];
    }

    $hasTransfers = array_sum($chartData['transactions']) > 0;
    $hasCharge = array_sum($chartData['revenue_ugx']) > 0;
    $hasExchange = isset($chartData['exchange_ugx']) && array_sum($chartData['exchange_ugx']) > 0;

    // The shared Super Admin account is named after its own role, so its first word ("Super")
    // is not a person. When the name only repeats the role, the greeting is left without one.
    $firstName = trim(Str::before($me->name, ' '));
    $who = $firstName === '' || strcasecmp($firstName, $role) === 0 || strcasecmp($firstName, trim(Str::before($role, ' '))) === 0
        ? ''
        : $firstName;
@endphp
<div class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm p-4 sm:p-5">
    <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
        {{-- The mark follows the same hour bands as the words: a sun for the desk hours, a moon
             for the night shift. It carries no text, so it cannot repeat anything on the page. --}}
        <span aria-hidden="true" class="w-11 h-11 shrink-0 rounded-full flex items-center justify-center {{ $hour >= 6 && $hour < 18 ? 'bg-amber-100 text-amber-600' : 'bg-indigo-100 text-indigo-500' }}">
            @if($hour >= 6 && $hour < 18)
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4V2m0 20v-2m8-8h2M2 12h2m13.66-5.66l1.41-1.41M4.93 19.07l1.41-1.41m0-11.32L4.93 4.93m14.14 14.14l-1.41-1.41M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            @else
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.5 13.5A8.5 8.5 0 1110.5 3.5a7 7 0 0010 10z"/></svg>
            @endif
        </span>
        <div class="mr-auto min-w-0">
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Dashboard</h1>
            <p class="text-gray-600 mt-1">{{ $greeting }}{{ $who ? ', '.$who : '' }}.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 capitalize">{{ $role }}</span>
        {{-- No flag emoji: Windows draws them as two bare letters, which reads as a fault. --}}
        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border {{ $desk ? 'bg-green-100 text-green-800 border-green-200' : 'bg-amber-50 text-amber-800 border-amber-200' }}">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            {{ $desk ?: 'Desk country not set' }}
        </span>
        <span class="text-xs text-gray-500" id="liveClock">{{ now()->format('l, F d, Y') }} · {{ now()->format('h:i:s A') }}</span>
        </div>
    </div>
</div>

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
</style>

@php
    // Shortcuts to the work people come here to do, set at the top of the page because booking is
    // the reason the desk opens it; the balances are read after the work is entered. Each one is
    // dropped when its ability is missing, so nobody is handed a button that bounces back with a
    // permission error, and a role that books nothing at all gets no card rather than a row of
    // dead links. Every label is the words the screen it opens uses, so one thing never carries
    // two names.
    $quickActions = array_values(array_filter([
        ['create-transaction', 'Send Money', 'Book a transfer', 'transactions.create', 'M12 4v16m8-8H4'],
        ['create-exchange', 'Record Exchange', 'Buy or sell notes', 'exchanges.create', 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
        ['create-expense', 'Record Expense', 'Money spent here', 'expenses.create', 'M12 3v11m0 0l-4-4m4 4l4-4M4 20h16'],
        ['view-expenses', 'Expenses', 'Every expense logged', 'expenses.index', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ['view-exchange-rates', 'Exchange Rates', "Today's prices", 'exchange-rates.index', 'M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2zm3-14h4m-4 4h4m-4 4h4'],
        ['create-customer', 'Add Customer', 'Save a person', 'customers.create', 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
    ], fn (array $a) => $me->can($a[0])));
@endphp
@if($quickActions !== [])
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-4 sm:p-6 mb-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        @foreach($quickActions as [$ability, $label, $hint, $route, $glyph])
        <a href="{{ route($route) }}" class="flex items-center gap-3 min-w-0 rounded-lg border border-gray-200 p-3 hover:border-green-300 hover:bg-green-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300 focus-visible:ring-offset-1">
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

{{-- Account::currenciesFor() orders the wallets by the desk's country, so the local currency is always the first card. --}}
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
        @endif
        @if($balance <= 0)
        <div class="text-xs text-gray-500 mt-1">No balance yet. @can('adjust-wallets')<span class="font-semibold text-gray-700 underline">Add money</span>@endcan</div>
        @endif
    </a>
    @endforeach
</div>

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
            @foreach($panel['cards'] as [$cardLabel, $cardValue, $cardTone])
            <div class="stat-card min-w-0 rounded-lg border border-gray-200 p-4">
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $cardLabel }}</div>
                <div class="stat-value mt-2 font-bold {{ $cardTone }} tabular-nums whitespace-nowrap" style="--len: {{ strlen($cardValue) }}">{{ $cardValue }}</div>
            </div>
            @endforeach
        </div>
        <p class="text-xs text-gray-500 mt-3">{{ number_format($panel['pending']) }} pending · {{ number_format($panel['cancelled']) }} cancelled · average transfer {{ $money($panel['avg_ugx'], $panel['avg_usd'], 0) }}</p>
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
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap tabular-nums">{{ $t->currency }} {{ number_format($t->amount, 2) }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap tabular-nums">¥{{ number_format($t->rmb_amount, 2) }}</td>
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

    function updateClock() {
        const now = new Date();
        const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        const day = days[now.getDay()];
        const month = months[now.getMonth()];
        const date = now.getDate();
        const year = now.getFullYear();
        let h = now.getHours();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        const m = String(now.getMinutes()).padStart(2, '0');
        const s = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('liveClock').textContent = `${day}, ${month} ${String(date).padStart(2, '0')}, ${year} · ${String(h).padStart(2, '0')}:${m}:${s} ${ampm}`;
    }
    setInterval(updateClock, 1000);
</script>
@endsection
