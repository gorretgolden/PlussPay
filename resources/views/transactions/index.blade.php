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
            <label class="block text-sm text-gray-600 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
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
            $quickBase = array_filter(request()->only(['search', 'status', 'currency', 'created_by', 'payment_method', 'usd_rate', 'rmb_rate', 'country']), fn($v) => $v !== '');
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

<div class="bg-white rounded-lg shadow p-4 mb-6 overflow-x-auto no-print">
    <h2 class="text-sm font-semibold text-gray-900 mb-1">Money by desk</h2>
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

        $shillings = fn ($value) => 'UGX '.number_format((float) $value);
        $dollars = fn ($value) => 'USD '.number_format((float) $value, 1);
        $yuan = fn ($value) => '¥'.number_format((float) $value, 1);
    @endphp
    @if($deskRows->isEmpty())
        <p class="text-sm text-gray-500 py-2">No transfers on these filters.</p>
    @else
        <table class="min-w-full mt-1">
            <thead>
                <tr class="text-left text-xs font-medium text-gray-500 uppercase">
                    <th class="py-2 pr-3 whitespace-nowrap font-medium">Desk</th>
                    <th class="py-2 pr-3 text-right whitespace-nowrap font-medium">Bookings</th>
                    <th class="py-2 pr-3 text-right whitespace-nowrap font-medium" title="The shillings this desk is holding, with the charge still inside them">Shillings handed over</th>
                    <th class="py-2 pr-3 text-right whitespace-nowrap font-medium" title="The dollars this desk is holding, forwarded whole by a dollar booking">Dollars handed over</th>
                    <th class="py-2 pr-3 text-right whitespace-nowrap font-medium" title="What this desk kept from the money it took in">Charges earned</th>
                    <th class="py-2 text-right whitespace-nowrap font-medium" title="What the transfers priced here promise to receivers in China">Yuan to China</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 text-sm tabular-nums">
                @foreach($deskRows as $row)
                <tr>
                    <td class="py-2 pr-3 whitespace-nowrap"><span class="text-gray-900 font-medium">{{ $row->label }}</span><span class="text-gray-400"> · {{ $row->country }}</span></td>
                    <td class="py-2 pr-3 text-right text-gray-900">{{ number_format((int) $row->bookings) }}</td>
                    <td class="py-2 pr-3 text-right text-gray-900">{{ $shillings($row->cash_ugx) }}</td>
                    <td class="py-2 pr-3 text-right text-gray-900">{{ $dollars($row->cash_usd) }}</td>
                    <td class="py-2 pr-3 text-right text-gray-900">{{ $shillings($row->charges_ugx) }}</td>
                    <td class="py-2 text-right text-gray-900">{{ $yuan($row->rmb_out) }}</td>
                </tr>
                @endforeach
                @if($deskRows->count() > 1)
                <tr class="border-t-2 border-gray-300 font-medium">
                    <td class="py-2 pr-3 whitespace-nowrap text-gray-900">Both desks</td>
                    <td class="py-2 pr-3 text-right text-gray-900">{{ number_format((int) $all['bookings']) }}</td>
                    <td class="py-2 pr-3 text-right text-gray-900">{{ $shillings($all['cash_ugx']) }}</td>
                    <td class="py-2 pr-3 text-right text-gray-900">{{ $dollars($all['cash_usd']) }}</td>
                    <td class="py-2 pr-3 text-right text-gray-900">{{ $shillings($all['charges_ugx']) }}</td>
                    <td class="py-2 text-right text-gray-900">{{ $yuan($all['rmb_out']) }}</td>
                </tr>
                @endif
            </tbody>
        </table>
        <p class="text-xs text-gray-500 mt-2">A transfer stands under the desk of the hand it was booked into. A cancelled booking gave its money back, so it counts among the bookings and not in the shillings, dollars or yuan. The charge a desk kept sits inside the shillings that were handed over, and arrives alongside the dollars on a dollar booking. The yuan figure is what the transfers priced here promise to receivers, wherever that money has reached so far.</p>
    @endif
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    @php
        // A figure that was never priced reads as n/a in gray, and a negative one is a loss
        // in red. The model tags each figure with the tone it should carry.
        $toneClass = ['plain' => 'text-gray-900', 'loss' => 'text-red-600', 'muted' => 'text-gray-400'];
    @endphp
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                {{-- The book is wider than any screen, so the number a teller reads out over the phone
                     is pinned to the left edge and stays put while the rest slides under it. --}}
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap sticky left-0 z-20 bg-gray-50 border-r border-gray-200">Receipt #</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap" title="Where the transfer originated, read off the hand holding the money">Desk</th>
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
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Status</th>
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Date</th>
                @can('view-profit')
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap" title="Whoever the booking was written into. Their desk is the column beside Receipt #">Booked By</th>
                @endcan
                <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($transactions as $t)
            @php $profit = $t->profitLabels(); @endphp
            <tr>
                <td class="px-2 py-4 text-sm font-medium text-green-800 whitespace-nowrap sticky left-0 z-10 bg-white border-r border-gray-200">
                    <a href="{{ route('receipts.show', $t->receipt_number) }}" class="whitespace-nowrap">{{ $t->receipt_number }}</a>
                </td>
                {{-- The desk is read off the transfer, not off the person who booked it, so moving
                     somebody between desks cannot rewrite where their older money was taken. --}}
                <td class="px-2 py-4 text-sm whitespace-nowrap"><span class="text-gray-900">{{ $t->deskLabel() }}</span><span class="text-gray-400"> · {{ $t->deskCode() }}</span></td>
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
                    <span class="px-2 py-1 text-xs rounded-full
                        @if($t->status === 'completed') bg-green-100 text-green-800
                        @elseif($t->status === 'pending') bg-yellow-100 text-yellow-800
                        @else bg-red-100 text-red-800 @endif">
                        {{ ucfirst($t->status) }}
                    </span>
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

                        @can('view-profit')
                        @if($t->status === 'pending')
                        <div class="border-t border-gray-100 my-1"></div>
                        <form method="POST" action="{{ route('transactions.update-status', $t) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="completed">
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-green-700 hover:bg-green-50">
                                Approve
                            </button>
                        </form>
                        @endif

                        @if($t->status !== 'cancelled')
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
                <td colspan="{{ auth()->user()->can('view-profit') ? 17 : 12 }}" class="px-6 py-8 text-center text-gray-500">No transactions found.</td>
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
@endsection
