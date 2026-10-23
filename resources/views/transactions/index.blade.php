@extends('layouts.app')

@section('title', 'Transactions')

@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold text-gray-900">Transactions</h1>
    <div class="flex flex-wrap gap-2">
        @can('bulk-import-transactions')
        <a href="{{ route('transactions.bulk-import') }}" class="bg-blue-800 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">Bulk Import</a>
        @endcan
        <a href="{{ route('export.transactions', request()->query()) }}" class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
        @can('create-transaction')
        <a href="{{ route('transactions.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">New Transaction</a>
        @endcan
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6 no-print">
    <form method="GET" action="{{ route('transactions.index') }}" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <div>
            <label class="block text-sm text-gray-600 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Receipt #, sender, receiver, confirmation number"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Progress</label>
            <select name="stage" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                <option value="cash" {{ request('stage') === 'cash' ? 'selected' : '' }}>Cash taken</option>
                <option value="hand_in" {{ request('stage') === 'hand_in' ? 'selected' : '' }}>Waiting for hand-in approval</option>
                <option value="ready" {{ request('stage') === 'ready' ? 'selected' : '' }}>Ready to send to China</option>
                <option value="waiting" {{ request('stage') === 'waiting' ? 'selected' : '' }}>Waiting to be paid</option>
                <option value="paid" {{ request('stage') === 'paid' ? 'selected' : '' }}>Paid</option>
                <option value="pending" {{ request('stage') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="cancelled" {{ request('stage') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Currency</label>
            <select name="currency" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                <option value="UGX" {{ request('currency') === 'UGX' ? 'selected' : '' }}>UGX</option>
                <option value="USD" {{ request('currency') === 'USD' ? 'selected' : '' }}>USD</option>
            </select>
        </div>
        @can('view-all-transactions')
        <div>
            <label class="block text-sm text-gray-600 mb-1">Desk</label>
            <select name="country" title="The desk of the hand each transfer was booked into"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Both desks</option>
                @foreach(\App\Models\User::COUNTRIES as $code => $desk)
                <option value="{{ $code }}" {{ request('country') === $code ? 'selected' : '' }}>{{ $desk }}</option>
                @endforeach
            </select>
        </div>
        @endcan
        @can('view-profit')
        <div>
            <label class="block text-sm text-gray-600 mb-1">Dollar Selling Price</label>
            <select name="usd_rate" title="The shillings each dollar was sent at" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                @foreach($usdPrices as $price)
                <option value="{{ $price }}" {{ request('usd_rate') === $price ? 'selected' : '' }}>{{ $price }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Yuan Selling Price</label>
            <select name="rmb_rate" title="The yuan each dollar became in China" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                @foreach($rmbPrices as $price)
                <option value="{{ $price }}" {{ request('rmb_rate') === $price ? 'selected' : '' }}>{{ $price }}</option>
                @endforeach
            </select>
        </div>
        @endcan
        <div>
            <label class="block text-sm text-gray-600 mb-1">Paid how</label>
            <select name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                @foreach(\App\Models\Transaction::PAYMENT_METHODS as $method)
                <option value="{{ $method }}" {{ request('payment_method') === $method ? 'selected' : '' }}>{{ $method }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Created By</label>
            <select name="created_by" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                @foreach($creators as $creator)
                <option value="{{ $creator->id }}" {{ request('created_by') == $creator->id ? 'selected' : '' }}>{{ $creator->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">From Date</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">To Date</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
            <a href="{{ route('transactions.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
        </div>
    </form>
    <div class="flex gap-2 mt-3 pt-3 border-t border-gray-200">
        @php
            $quickBase = array_filter(request()->only(['search', 'stage', 'currency', 'created_by', 'payment_method', 'usd_rate', 'rmb_rate', 'country']), fn($v) => $v !== '');
            $ranges = [
                'Today' => [today()->toDateString(), today()->toDateString()],
                'Yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
                'This Month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
                'Last Month' => [now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()],
            ];
        @endphp
        <span class="text-sm text-gray-500 self-center mr-1">Quick:</span>
        @foreach($ranges as $label => [$from, $to])
        <a href="{{ route('transactions.index', array_merge($quickBase, ['date_from' => $from, 'date_to' => $to])) }}"
            class="px-3 py-1 text-xs rounded-full {{ request('date_from') === $from && request('date_to') === $to ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">{{ $label }}</a>
        @endforeach
    </div>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm mb-6 no-print">
    {{-- Uganda money and China money are read apart, because the two desks hold different notes:
         shillings come in here and yuan go out there. The same filters that cut the list cut this,
         so a desk narrowed to China shows what China took in and nothing else. --}}
    @php
        // The two desks in the order the book is kept in, then any code that should not be there.
        $deskOrder = array_flip(array_keys(\App\Models\User::COUNTRIES));
        $deskRows = $deskTotals->sortKeysUsing(function ($a, $b) use ($deskOrder) {
            return ($deskOrder[$a] ?? 99) <=> ($deskOrder[$b] ?? 99);
        })->map(function ($row, $code) {
            $row->label = \App\Models\User::COUNTRIES[$code] ?? $code;

            return $row;
        })->values();

        $all = [
            'bookings' => $deskRows->sum('bookings'),
            'cash_ugx' => $deskRows->sum('cash_ugx'),
            'cash_usd' => $deskRows->sum('cash_usd'),
            'charges_ugx' => $deskRows->sum('charges_ugx'),
            'rmb_out' => $deskRows->sum('rmb_out'),
        ];

        // The unit is read apart from the figure so the money itself stays the darkest thing in
        // the cell. Yuan is the exception: its symbol sits tight against the number it prices.
        $ugx = fn ($value) => ['UGX', number_format((float) $value)];
        $usd = fn ($value) => ['USD', number_format((float) $value, 1)];
        $cny = fn ($value) => '¥'.number_format((float) $value, 1);

        $totalUgx = $ugx($all['cash_ugx']);
        $totalUsd = $usd($all['cash_usd']);
        $totalCharges = $ugx($all['charges_ugx']);
    @endphp
    <div class="flex items-start justify-between gap-3 px-4 py-4 sm:px-5">
        <div class="flex min-w-0 items-center gap-3">
            <span class="hidden h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-green-50 text-green-800 ring-1 ring-inset ring-green-100 sm:inline-flex" aria-hidden="true">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </span>
            <div class="min-w-0">
                <h2 class="text-base font-semibold text-gray-900">Money by desk</h2>
                <p class="mt-0.5 text-xs text-gray-500">The same filters as the list below.</p>
            </div>
        </div>
        <span class="inline-flex flex-shrink-0 items-center gap-1.5 rounded-full bg-gray-50 px-2.5 py-1 text-[11px] font-medium text-gray-600 ring-1 ring-inset ring-gray-200">
            <span class="h-1.5 w-1.5 rounded-full bg-green-600" aria-hidden="true"></span>
            {{ $deskRows->count() }} {{ \Illuminate\Support\Str::plural('desk', $deskRows->count()) }}
        </span>
    </div>
    @if($deskRows->isEmpty())
        <div class="border-t border-gray-100 px-4 py-10 text-center">
            <svg class="mx-auto h-8 w-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
            <p class="mt-2 text-sm text-gray-500">No transfers on these filters.</p>
        </div>
    @else
        {{-- Wide enough for six columns once the sidebar and page gutters have taken their share. --}}
        <div class="hidden lg:block">
            <table class="w-full">
                <caption class="sr-only">Bookings and money by desk</caption>
                <thead>
                    <tr class="border-y border-gray-100 bg-gray-50/80 text-[11px] uppercase tracking-wide text-gray-500">
                        <th scope="col" class="py-2.5 pl-4 pr-3 text-left font-semibold sm:pl-5">Desk</th>
                        <th scope="col" class="px-3 py-2.5 text-right font-semibold">Bookings</th>
                        <th scope="col" class="px-3 py-2.5 text-right font-semibold" title="Cash handed over in shillings, with the charge still inside it">Shillings</th>
                        <th scope="col" class="px-3 py-2.5 text-right font-semibold" title="Dollars handed over on a dollar booking">Dollars</th>
                        <th scope="col" class="px-3 py-2.5 text-right font-semibold" title="What this desk kept from the money it took in">Charges</th>
                        <th scope="col" class="py-2.5 pl-3 pr-4 text-right font-semibold sm:pr-5" title="What the transfers priced here promise to receivers in China">Yuan to China</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($deskRows as $row)
                    @php
                        $cashUgx = $ugx($row->cash_ugx);
                        $cashUsd = $usd($row->cash_usd);
                        $charges = $ugx($row->charges_ugx);
                    @endphp
                    <tr class="group transition-colors hover:bg-green-50/40">
                        <td class="whitespace-nowrap py-3 pl-4 pr-3 sm:pl-5">
                            <span class="inline-flex items-center gap-2.5">
                                <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-green-50 px-1.5 text-[11px] font-bold text-green-800 ring-1 ring-inset ring-green-100">{{ $row->country }}</span>
                                <span class="text-sm font-semibold text-gray-900">{{ $row->label }}</span>
                            </span>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <span class="inline-flex min-w-[2rem] justify-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 tabular-nums transition-colors group-hover:bg-white">{{ number_format((int) $row->bookings) }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                            <span class="text-[11px] font-medium text-gray-400">{{ $cashUgx[0] }}</span> <span class="text-sm font-semibold text-gray-900">{{ $cashUgx[1] }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                            <span class="text-[11px] font-medium text-gray-400">{{ $cashUsd[0] }}</span> <span class="text-sm font-semibold text-gray-900">{{ $cashUsd[1] }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                            <span class="text-[11px] font-medium text-gray-400">{{ $charges[0] }}</span> <span class="text-sm font-semibold text-gray-900">{{ $charges[1] }}</span>
                        </td>
                        <td class="whitespace-nowrap py-3 pl-3 pr-4 text-right text-sm font-semibold text-gray-900 tabular-nums sm:pr-5">{{ $cny($row->rmb_out) }}</td>
                    </tr>
                    @endforeach
                    @if($deskRows->count() > 1)
                    <tr class="border-t border-green-100 bg-green-50/60">
                        <td class="whitespace-nowrap py-3 pl-4 pr-3 sm:pl-5">
                            <span class="inline-flex items-center gap-2.5">
                                <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-green-800 px-1.5 text-[10px] font-bold tracking-wide text-white">ALL</span>
                                <span class="text-sm font-semibold text-green-900">Both desks</span>
                            </span>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <span class="inline-flex min-w-[2rem] justify-center rounded-full bg-white px-2 py-0.5 text-xs font-semibold text-green-900 tabular-nums ring-1 ring-inset ring-green-200">{{ number_format((int) $all['bookings']) }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                            <span class="text-[11px] font-medium text-gray-500">{{ $totalUgx[0] }}</span> <span class="text-sm font-bold text-gray-900">{{ $totalUgx[1] }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                            <span class="text-[11px] font-medium text-gray-500">{{ $totalUsd[0] }}</span> <span class="text-sm font-bold text-gray-900">{{ $totalUsd[1] }}</span>
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                            <span class="text-[11px] font-medium text-gray-500">{{ $totalCharges[0] }}</span> <span class="text-sm font-bold text-gray-900">{{ $totalCharges[1] }}</span>
                        </td>
                        <td class="whitespace-nowrap py-3 pl-3 pr-4 text-right text-sm font-bold text-gray-900 tabular-nums sm:pr-5">{{ $cny($all['rmb_out']) }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>

        {{-- Narrow screens read each desk as its own card, two figures to a row so a desk fits a
             phone without sideways scrolling. --}}
        <div class="divide-y divide-gray-100 lg:hidden">
            @foreach($deskRows as $row)
            @php
                $cashUgx = $ugx($row->cash_ugx);
                $cashUsd = $usd($row->cash_usd);
                $charges = $ugx($row->charges_ugx);
            @endphp
            <div class="px-4 py-4">
                <div class="flex items-center justify-between gap-3">
                    <span class="inline-flex items-center gap-2.5">
                        <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-green-50 px-1.5 text-[11px] font-bold text-green-800 ring-1 ring-inset ring-green-100">{{ $row->country }}</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $row->label }}</span>
                    </span>
                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-semibold text-gray-600">{{ number_format((int) $row->bookings) }} {{ \Illuminate\Support\Str::plural('booking', (int) $row->bookings) }}</span>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3">
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Shillings</dt>
                        <dd class="mt-0.5 truncate text-sm font-semibold text-gray-900 tabular-nums"><span class="text-[11px] font-medium text-gray-400">{{ $cashUgx[0] }}</span> {{ $cashUgx[1] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Dollars</dt>
                        <dd class="mt-0.5 truncate text-sm font-semibold text-gray-900 tabular-nums"><span class="text-[11px] font-medium text-gray-400">{{ $cashUsd[0] }}</span> {{ $cashUsd[1] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Charges</dt>
                        <dd class="mt-0.5 truncate text-sm font-semibold text-gray-900 tabular-nums"><span class="text-[11px] font-medium text-gray-400">{{ $charges[0] }}</span> {{ $charges[1] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-400">Yuan to China</dt>
                        <dd class="mt-0.5 truncate text-sm font-semibold text-gray-900 tabular-nums">{{ $cny($row->rmb_out) }}</dd>
                    </div>
                </dl>
            </div>
            @endforeach
            @if($deskRows->count() > 1)
            <div class="bg-green-50/60 px-4 py-4">
                <div class="flex items-center justify-between gap-3">
                    <span class="inline-flex items-center gap-2.5">
                        <span class="inline-flex h-7 min-w-7 items-center justify-center rounded-md bg-green-800 px-1.5 text-[10px] font-bold tracking-wide text-white">ALL</span>
                        <span class="text-sm font-semibold text-green-900">Both desks</span>
                    </span>
                    <span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-green-900 ring-1 ring-inset ring-green-200">{{ number_format((int) $all['bookings']) }} {{ \Illuminate\Support\Str::plural('booking', (int) $all['bookings']) }}</span>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3">
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Shillings</dt>
                        <dd class="mt-0.5 truncate text-sm font-bold text-gray-900 tabular-nums"><span class="text-[11px] font-medium text-gray-500">{{ $totalUgx[0] }}</span> {{ $totalUgx[1] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Dollars</dt>
                        <dd class="mt-0.5 truncate text-sm font-bold text-gray-900 tabular-nums"><span class="text-[11px] font-medium text-gray-500">{{ $totalUsd[0] }}</span> {{ $totalUsd[1] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Charges</dt>
                        <dd class="mt-0.5 truncate text-sm font-bold text-gray-900 tabular-nums"><span class="text-[11px] font-medium text-gray-500">{{ $totalCharges[0] }}</span> {{ $totalCharges[1] }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-gray-500">Yuan to China</dt>
                        <dd class="mt-0.5 truncate text-sm font-bold text-gray-900 tabular-nums">{{ $cny($all['rmb_out']) }}</dd>
                    </div>
                </dl>
            </div>
            @endif
        </div>

        <p class="border-t border-gray-100 bg-gray-50 px-4 py-2.5 text-xs text-gray-500 sm:px-5">Cancelled bookings count in Bookings only.</p>
    @endif
</div>

{{-- The cash an agent holds is handed in from this list, by ticking the transfers it came from.
     The request is raised here and decided on the hand-in desk, so the two screens use the same
     words: what is ticked, what it adds up to, and what is left with the lead. --}}
@if($mayHandIn)
    @if($openHandIn)
        @php $namedOnOpen = $openHandIn->receiptNumbers(); @endphp
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 mb-4">
            <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold text-amber-900">
                        Hand-in {{ $openHandIn->reference }} is waiting for {{ $openHandIn->superAgent->name }} to approve it
                    </h2>
                    <p class="mt-1 text-sm text-amber-800">
                        {{ $openHandIn->figure() }} in cash, plus {{ $openHandIn->chargeFigure() }} in charges, named on
                        {{ number_format($namedOnOpen->count()) }} {{ \Illuminate\Support\Str::plural('transfer', $namedOnOpen->count()) }}
                        ({{ $namedOnOpen->implode(', ') }}).
                    </p>
                    <p class="mt-1 text-xs text-amber-700">The money is still in your drawer until he decides. Tick other transfers and send them as a second hand-in once this one is approved or sent back.</p>
                </div>
                <form method="POST" action="{{ route('cash-handovers.cancel', $openHandIn) }}" class="flex flex-shrink-0 flex-wrap items-end gap-2"
                    data-confirm data-confirm-danger="1" data-confirm-title="Call hand-in {{ $openHandIn->reference }} back?"
                    data-confirm="Nothing has moved yet. {{ $openHandIn->figure() }} plus {{ $openHandIn->chargeFigure() }} in charges stay in your drawer, and the {{ $namedOnOpen->count() }} {{ \Illuminate\Support\Str::plural('transfer', $namedOnOpen->count()) }} go back to reading as cash taken."
                    data-confirm-ok="Call it back" data-lock data-lock-label="Cancelling...">
                    @csrf
                    <div>
                        <label for="cancel_reason" class="block text-xs font-medium text-amber-800 mb-1">Why, for the trail <span class="font-normal text-amber-600">(optional)</span></label>
                        <input type="text" id="cancel_reason" name="reason" maxlength="255" placeholder="e.g. One transfer needs correcting first"
                            class="w-full sm:w-56 px-2.5 py-1.5 text-sm border border-amber-300 rounded-md bg-white">
                    </div>
                    <button type="submit" class="rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-red-700 ring-1 ring-inset ring-red-200 hover:bg-red-50">
                        Cancel hand-in
                    </button>
                </form>
            </div>
        </div>
    @endif

    <div class="bg-white rounded-lg shadow p-4 mb-4">
        <form id="hand-in-form" method="POST" action="{{ route('cash-handovers.from-transactions') }}" class="grid grid-cols-1 gap-4 lg:grid-cols-4"
            data-lock data-lock-label="Sending...">
            @csrf
            <div class="lg:col-span-4">
                <h2 class="text-sm font-semibold text-gray-900">Hand the cash in for approval</h2>
                <p class="mt-0.5 text-xs text-gray-500">Tick the transfers below whose money is in your hand, then send those names up to your lead. Tick nothing and this card does nothing.</p>
            </div>
            <div>
                <label for="hand_in_super_agent" class="block text-sm text-gray-600 mb-1">Handing to <span class="text-red-600">*</span></label>
                <select id="hand_in_super_agent" name="super_agent_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                    <option value="">Choose the super agent</option>
                    @foreach($superAgents as $lead)
                    <option value="{{ $lead->id }}" {{ request('super_agent_id') == $lead->id ? 'selected' : '' }}>{{ $lead->name }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">He approves the cash, and his wallets hold it after that.</p>
            </div>
            <div>
                <label for="hand_in_note" class="block text-sm text-gray-600 mb-1">Note <span class="font-normal text-gray-400">(optional)</span></label>
                <input type="text" id="hand_in_note" name="note" maxlength="255" value="{{ request('note') }}"
                    placeholder="e.g. Counter cash up to today" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <p class="mt-1 text-xs text-gray-500">Goes on the request and into the trail.</p>
            </div>
            {{-- The two figures are worked out from the ticks, not typed. They sit here beside the
                 fields rather than at the far end of the page, so what is being promised is read at
                 the moment it is promised. --}}
            <div class="rounded-lg bg-gray-50 p-3 ring-1 ring-inset ring-gray-200">
                <dl class="grid grid-cols-[auto_1fr] items-baseline gap-x-3 gap-y-1.5 text-sm">
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Ticked</dt>
                    <dd id="hand_in_count" class="text-right font-semibold text-gray-900 tabular-nums">0</dd>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Cash to hand in</dt>
                    <dd id="hand_in_principal" class="text-right font-semibold text-gray-900 tabular-nums">UGX 0</dd>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Charges with it</dt>
                    <dd id="hand_in_charges" class="text-right font-semibold text-gray-900 tabular-nums">UGX 0</dd>
                </dl>
                <p class="mt-2 border-t border-gray-200 pt-2 text-[11px] leading-relaxed text-gray-500">
                    Cash to hand in is what the ticked transfers were worth when they were booked, added together. Charges are what they earned you: they ride up with the cash and stay with your lead. Only the cash travels on to China later.
                </p>
            </div>
            <div class="flex flex-col justify-end gap-2">
                <button type="submit" id="hand_in_submit"
                    class="w-full rounded-md bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-500">
                    Hand in for approval
                </button>
                <p id="hand_in_problem" class="text-xs text-gray-500">Tick at least one transfer below.</p>
            </div>
        </form>
    </div>
@endif

<div class="bg-white rounded-lg shadow overflow-x-auto">
    @php
        // A figure that was never priced reads as n/a in gray, and a negative one is a loss
        // in red. The model tags each figure with the tone it should carry.
        $toneClass = ['plain' => 'text-gray-900', 'loss' => 'text-red-600', 'muted' => 'text-gray-400'];

        // The tick column is pinned beside the receipt number, because the number is what a teller
        // reads over the phone and the tick is what they are reading it for. Two pinned columns
        // need their offsets spelled out, so the tick column is held to a fixed width.
        $receiptOffset = $mayHandIn ? 'left-10' : 'left-0';
    @endphp
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                @if($mayHandIn)
                <th class="w-10 px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase sticky left-0 z-20 bg-gray-50 border-r border-gray-200">
                    <label for="hand_in_all" class="sr-only">Tick every transfer on this page that is yours to hand in</label>
                    <input type="checkbox" id="hand_in_all" class="h-4 w-4 rounded border-gray-300 text-green-800 focus:ring-green-600">
                </th>
                @endif
                {{-- The book is wider than any screen, so the number a teller reads out over the phone
                     is pinned to the left edge and stays put while the rest slides under it. --}}
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap sticky {{ $receiptOffset }} z-20 bg-gray-50 border-r border-gray-200">Receipt #</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap" title="The desk holding the money, stamped when the booking was written">Desk</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap" title="The country the money starts in and the country it is sent to">Route</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Sender</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Receiver</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Reason</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap" title="How the money reached this desk, with the confirmation number when there was one">Paid how</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Money Travels</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">RMB</th>
                @can('view-profit')
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap" title="The shillings each dollar was sent at">Dollar Selling Price</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap" title="The yuan each dollar became in China">Yuan Selling Price</th>
                @endcan
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Charge (UGX)</th>
                @can('view-profit')
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Exchange Profit</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Overall Profit</th>
                @endcan
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap" title="How far this transfer has got, from the cash being taken to the receiver being paid">Progress</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Date</th>
                @can('view-profit')
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap" title="Whoever the booking was written into. Their desk is the column beside Receipt #">Booked By</th>
                @endcan
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($transactions as $t)
            @php
                $profit = $t->profitLabels();

                // Whether this row's cash can go up now. Anything already named on a request is left
                // alone, and a booking that never took money in has nothing to hand over. The reason
                // a locked box is locked is told on the box, not guessed from its colour.
                $tickable = $mayHandIn && $t->status === 'completed' && $t->cash_handover_id === null;
                $tickWhy = $mayHandIn
                    ? match (true) {
                        $t->hand_in_status === \App\Models\CashHandover::PENDING => 'Already named on hand-in '
                            .$t->cashHandover?->reference.', which '.$t->cashHandover?->superAgent?->name.' has not decided yet.'
                            .' Cancel that request to hand these in another way.',
                        $t->cash_handover_id !== null => 'Already named on hand-in '.$t->cashHandover?->reference
                            .', which was approved. Its cash is with '.$t->cashHandover?->superAgent?->name.' now.',
                        $t->status === 'cancelled' => 'Cancelled. The money went back to the sender, so there is nothing in your hand for it.',
                        $t->status === 'pending' => 'Still waiting to be approved, so no cash has been taken in for it.',
                        default => 'Tick this to hand its cash in.',
                    }
                    : null;
            @endphp
            <tr>
                @if($mayHandIn)
                <td class="w-10 px-3 py-4 sticky left-0 z-10 bg-white border-r border-gray-200">
                    <label for="hand_in_{{ $t->id }}" class="sr-only">Hand in the cash for {{ $t->receipt_number }}</label>
                    <input type="checkbox" id="hand_in_{{ $t->id }}" name="transaction_ids[]" value="{{ $t->id }}" form="hand-in-form"
                        data-hand-in-tick data-receipt="{{ $t->receipt_number }}" data-currency="{{ strtoupper($t->currency) }}"
                        data-principal="{{ $t->amount }}" data-charge="{{ $t->isToUganda() ? 0 : $t->charge_ugx }}"
                        @if(! $tickable) disabled @endif title="{{ $tickWhy }}"
                        class="h-4 w-4 rounded border-gray-300 text-green-800 focus:ring-green-600 disabled:cursor-not-allowed disabled:opacity-40">
                </td>
                @endif
                <td class="px-2 py-4 text-sm font-medium text-green-800 whitespace-nowrap sticky {{ $receiptOffset }} z-10 bg-white border-r border-gray-200">
                    <a href="{{ route('receipts.show', $t->receipt_number) }}" class="whitespace-nowrap">{{ $t->receipt_number }}</a>
                </td>
                {{-- The desk is read off the transfer, not off the person who booked it, so moving
                     somebody between desks cannot rewrite where their older money was taken. --}}
                <td class="px-2 py-4 text-sm whitespace-nowrap"><span class="text-gray-900">{{ $t->deskLabel() }}</span><span class="text-gray-400"> · {{ $t->deskCode() }}</span></td>
                <td class="px-2 py-4 text-sm whitespace-nowrap"><span class="text-gray-900">{{ $t->originLabel() }}</span><span class="px-1 text-gray-400">→</span><span class="text-gray-600">{{ $t->destinationLabel() }}</span></td>
                <td class="px-2 py-4 text-sm text-gray-900">{{ $t->sender_name }}</td>
                <td class="px-2 py-4 text-sm text-gray-900">{{ $t->receiver_name }}</td>
                <td class="px-2 py-4 text-sm text-gray-700">{{ $t->purpose ?: '-' }}</td>
                <td class="px-2 py-4 text-sm text-gray-900 whitespace-nowrap">
                    {{ $t->payment_method ?: '-' }}@if($t->payment_reference)<span class="text-gray-600"> · {{ $t->payment_reference }}</span>@endif
                </td>
                <td class="px-2 py-4 text-sm text-gray-900 whitespace-nowrap">{{ $t->principalLabel() }}</td>
                <td class="px-2 py-4 text-sm text-gray-900 whitespace-nowrap">¥{{ number_format($t->rmb_amount, 1) }}</td>
                @can('view-profit')
                @php $usdRate = $t->shillingRateCell(); $rmbRate = $t->yuanRateCell(); @endphp
                <td class="px-2 py-4 text-sm whitespace-nowrap tabular-nums {{ $toneClass[$usdRate['tone']] }}" title="{{ $usdRate['title'] }}">UGX {{ $usdRate['text'] }}</td>
                <td class="px-2 py-4 text-sm whitespace-nowrap tabular-nums {{ $toneClass[$rmbRate['tone']] }}" title="{{ $rmbRate['title'] }}">RMB {{ $rmbRate['text'] }}</td>
                @endcan
                <td class="px-2 py-4 text-sm text-gray-900 whitespace-nowrap">UGX {{ number_format((float) $t->charge_ugx) }}</td>
                @can('view-profit')
                <td class="px-2 py-4 text-sm whitespace-nowrap {{ $toneClass[$profit['exchange']['tone']] }}">{{ $profit['exchange']['text'] }}</td>
                <td class="px-2 py-4 text-sm font-medium whitespace-nowrap {{ $toneClass[$profit['total']['tone']] }}">{{ $profit['total']['text'] }}</td>
                @endcan
                <td class="px-2 py-4">
                    <x-progress-badge :transaction="$t" />
                </td>
                <td class="px-2 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $t->created_at->format('M d, Y h:i A') }}</td>
                @can('view-profit')
                <td class="px-2 py-4 text-sm text-gray-700 whitespace-nowrap">
                    {{ $t->creator?->name ?? '-' }}
                </td>
                @endcan
                <td data-row-menu-host class="px-2 py-4 text-right relative">
                    <button type="button" onclick="toggleMenu({{ $t->id }})" class="p-1 rounded hover:bg-gray-100">
                        <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                    </button>
                    <div id="menu-{{ $t->id }}" data-row-menu class="hidden absolute right-0 top-8 z-20 bg-white rounded-lg shadow-lg border border-gray-200 py-1 w-44 text-left">
                        <a href="{{ route('receipts.show', $t->receipt_number) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">View Receipt</a>
                        <a href="{{ route('receipts.print', $t->receipt_number) }}" target="_blank" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Print Receipt</a>

                        {{-- The one-transfer way in: tick this row and open the card above on the spot,
                             rather than sending the teller to another page to do what they are looking at. --}}
                        @if($tickable)
                        <div class="border-t border-gray-100 my-1"></div>
                        <button type="button" data-hand-in-one="{{ $t->id }}"
                            class="w-full text-left px-4 py-2 text-sm text-green-800 hover:bg-green-50">Hand in this cash</button>
                        @endif

                        @can('view-profit')
                        @if($t->status === 'pending')
                        <div class="border-t border-gray-100 my-1"></div>
                        <form method="POST" action="{{ route('transactions.update-status', $t) }}" data-lock data-lock-label="Approving...">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="completed">
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                Approve
                            </button>
                        </form>
                        @endif

                        @if($t->status !== 'cancelled' && ! $t->hasLeftUganda())
                        <div class="border-t border-gray-100 my-1"></div>
                        <button type="button" onclick="openCancelModal(this)"
                            data-action="{{ route('transactions.update-status', $t) }}"
                            data-receipt="{{ $t->receipt_number }} · {{ $t->sender_name }} to {{ $t->receiver_name }}"
                            data-amount="{{ $t->principalLabel() }}"
                            data-fee="{{ $t->feeLabel() }}"
                            data-total="{{ $t->collectedAmount() }}"
                            class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                            Cancel transfer
                        </button>
                        @endif
                        @endcan
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="{{ (auth()->user()->can('view-profit') ? 18 : 13) + ($mayHandIn ? 1 : 0) }}" class="px-6 py-8 text-center text-gray-500">No transactions found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
    @can('view-profit')
    <p class="px-6 py-3 text-xs text-gray-500 border-t">The exchange profit is what the rates came to on the money that travelled, and the overall profit adds the charge to it. Only a completed transfer counts toward profit, and one booked before its rates were stored shows n/a instead of a zero that would read as break-even. The dollar selling price is the shillings each dollar cost the customer, and the yuan selling price is what each dollar became in China. Those two are the prices the customer was quoted and the receiver is paid on; the buying price the profit was worked from stays behind the scenes.</p>
    @endcan
</div>

<div class="mt-4">
    {{ $transactions->links() }}
</div>

@include('transactions.partials.cancel-modal')

@if($mayHandIn)
@push('scripts')
<script>
// The two figures on the card are the ticks added up, worked out here so they move as the ticks
// move. They are only a reading of the list: the request is priced again from the book when it
// arrives, so a stale tab cannot hand in money that is not there.
const handInOpenReference = @json($openHandIn?->reference);
const handInForm = document.getElementById('hand-in-form');
const handInCount = document.getElementById('hand_in_count');
const handInPrincipal = document.getElementById('hand_in_principal');
const handInCharges = document.getElementById('hand_in_charges');
const handInProblem = document.getElementById('hand_in_problem');
const handInSubmit = document.getElementById('hand_in_submit');
const handInLead = document.getElementById('hand_in_super_agent');
const handInAll = document.getElementById('hand_in_all');

function handInTicks() {
    return Array.from(document.querySelectorAll('[data-hand-in-tick]')).filter((box) => !box.disabled);
}

// Shillings are counted out in notes and dollars are cut by a rate, which is why one figure carries
// no decimals and the other carries one. The same rule the receipt prints with.
function handInMoney(code, value) {
    const decimals = code === 'UGX' ? 0 : 1;

    return code + ' ' + value.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

function refreshHandIn() {
    const ticked = handInTicks().filter((box) => box.checked);
    const currencies = [...new Set(ticked.map((box) => box.dataset.currency))];
    const charges = ticked.reduce((sum, box) => sum + Number(box.dataset.charge || 0), 0);

    handInCount.textContent = ticked.length.toLocaleString('en-US');
    handInCharges.textContent = handInMoney('UGX', charges);

    // A hand-in is one wallet to one wallet, so a mixed pile is shown as it is rather than added
    // together into a figure that would mean nothing. Nothing ticked is zero of the money the first
    // tick will bring, not a blank box that looks like the card is still working it out.
    if (currencies.length === 1) {
        const principal = ticked.reduce((sum, box) => sum + Number(box.dataset.principal || 0), 0);
        handInPrincipal.textContent = handInMoney(currencies[0], principal);
    } else {
        handInPrincipal.textContent = currencies.length > 1 ? 'mixed' : handInMoney('UGX', 0);
    }

    let blocker = '';

    if (handInOpenReference) {
        blocker = 'Hand-in ' + handInOpenReference + ' is still waiting to be decided. Approve it, or cancel it above, before another goes up.';
    } else if (ticked.length === 0) {
        blocker = 'Tick at least one transfer below.';
    } else if (currencies.length > 1) {
        blocker = 'One hand-in carries one money. You ticked ' + currencies.join(' and ')
            + ' transfers, so send them as one hand-in per money.';
    } else if (!handInLead.value) {
        blocker = 'Choose the super agent you are handing to.';
    }

    handInProblem.textContent = blocker;
    handInProblem.classList.toggle('text-red-700', !!blocker && ticked.length > 1 && currencies.length > 1);
    handInSubmit.disabled = !!blocker;

    const boxes = handInTicks();
    handInAll.checked = boxes.length > 0 && boxes.every((box) => box.checked);
}

document.addEventListener('change', (event) => {
    if (event.target.matches('[data-hand-in-tick], #hand_in_super_agent')) refreshHandIn();
});

handInAll.addEventListener('change', () => {
    handInTicks().forEach((box) => { box.checked = handInAll.checked; });
    refreshHandIn();
});

// The row menu ticks that one transfer and opens the card on the spot, so the teller never leaves
// the list to do what the list is already showing.
document.querySelectorAll('[data-hand-in-one]').forEach((button) => {
    button.addEventListener('click', () => {
        const box = document.getElementById('hand_in_' + button.dataset.handInOne);

        if (box) {
            box.checked = true;
            refreshHandIn();
        }

        handInForm.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        handInLead.focus();
    });
});

refreshHandIn();
</script>
@endpush
@endif
@endsection
