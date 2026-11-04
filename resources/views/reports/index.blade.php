@extends('layouts.app')

@section('title', 'Reports')

@section('content')
@php
    // The profit columns belong to the same account that owns the Profit cards, so one flag
    // decides which columns a viewer gets instead of repeating the role test everywhere.
    $showProfit = auth()->user()->can('view-profit');

    // The hub carries every table for the reader; each other tab renders only its own section.
    $isHub = $reportType === 'hub';

    // Carried on every link on the page so a filter set once survives the tab switches and the
    // sheet that comes out of the other end.
    $carry = array_filter([
        'type' => $reportType,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'search' => $search !== '' ? $search : null,
        'agent_id' => $agentId,
        'all_time' => $allTime ? 1 : null,
    ]);

    $query = fn (array $over) => route('reports.index', array_filter(array_merge($carry, $over), fn ($v) => $v !== null && $v !== ''));

    // The transfer sheet is the export for every tab except the desk's own pages and the profit
    // report, which has an export of its own next to its cards.
    $canExport = auth()->user()->can('export-reports') && ! ($isDesk && in_array($reportType, ['exchanges', 'wallet', 'handins'], true)) && ! (! $isDesk && $reportType === 'profit');
@endphp
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">{{ $reportTitle }}</h1>
        <p class="text-gray-600">{{ $reportHint }}</p>
    </div>
    @if($canExport)
    <a href="{{ route('reports.export', array_filter($carry, fn ($v) => $v !== null && $v !== '')) }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Export Excel</a>
    @endif
</div>

{{-- Date Filter --}}
<div class="bg-white rounded-lg shadow p-4 mb-6 no-print">
    <form method="GET" action="{{ route('reports.index') }}" class="flex items-end gap-4 flex-wrap">
        <input type="hidden" name="type" value="{{ $reportType }}">
        @if($allTime)<input type="hidden" name="all_time" value="1">@endif
        <div>
            <label class="block text-sm text-gray-600 mb-1">From</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">To</label>
            <input type="date" name="date_to" value="{{ $dateTo }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label for="report-search" class="block text-sm text-gray-600 mb-1">Receipt or name</label>
            <input type="text" id="report-search" name="search" value="{{ $search }}" placeholder="e.g. PS-20261016-0001" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        @unless($isDesk)
        <div>
            <label for="report-agent" class="block text-sm text-gray-600 mb-1">Desk</label>
            <select id="report-agent" name="agent_id" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Every desk</option>
                @foreach($desks as $desk)
                <option value="{{ $desk->id }}" @selected($agentId === $desk->id)>{{ $desk->name }}</option>
                @endforeach
            </select>
        </div>
        @endunless
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Apply</button>
        <a href="{{ route('reports.index', ['type' => $reportType]) }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Reset</a>
        <div class="flex gap-2 ml-auto">
            <a href="{{ $query(['date_from' => today()->toDateString(), 'date_to' => today()->toDateString(), 'all_time' => null]) }}" class="px-3 py-1 text-xs rounded-full {{ ! $allTime && $dateFrom === today()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Today</a>
            <a href="{{ $query(['date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->toDateString(), 'all_time' => null]) }}" class="px-3 py-1 text-xs rounded-full {{ ! $allTime && $dateFrom === now()->startOfMonth()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">This Month</a>
            <a href="{{ $query(['date_from' => now()->subMonth()->startOfMonth()->toDateString(), 'date_to' => now()->subMonth()->endOfMonth()->toDateString(), 'all_time' => null]) }}" class="px-3 py-1 text-xs rounded-full {{ ! $allTime && $dateFrom === now()->subMonth()->startOfMonth()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Last Month</a>
            <a href="{{ $query(['all_time' => 1]) }}" class="px-3 py-1 text-xs rounded-full {{ $allTime ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">All Time</a>
        </div>
    </form>
</div>

@if($isHub)
{{-- Summary Stats --}}
<div id="section-hub" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">Total Transactions</div>
        <div class="text-2xl font-bold text-green-800 mt-1">{{ number_format($stats['total_transactions']) }}</div>
        <div class="text-xs text-gray-400 mt-1">Completed: {{ $stats['completed'] }} | Pending: {{ $stats['pending'] }} | Cancelled: {{ $stats['cancelled'] }}</div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">Money Sent (USD)</div>
        <div class="text-2xl font-bold text-green-700 mt-1">${{ number_format($stats['total_usd_sent'], 2) }}</div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">RMB Still In Uganda</div>
        <div class="text-2xl font-bold text-amber-700 mt-1">¥{{ number_format($stats['rmb_in_uganda'], 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">Not sent to China yet</div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">RMB In China, Unpaid</div>
        <div class="text-2xl font-bold text-amber-700 mt-1">¥{{ number_format($stats['rmb_in_china'], 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">Dollars arrived, receiver not paid</div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">RMB Delivered</div>
        <div class="text-2xl font-bold text-purple-700 mt-1">¥{{ number_format($stats['rmb_paid'], 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">Of ¥{{ number_format($stats['total_rmb_sent'], 2) }} booked</div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">Charges Kept</div>
        <div class="text-2xl font-bold text-green-700 mt-1">UGX {{ number_format($profit['fee_ugx']) }}</div>
        <div class="text-xs text-gray-400 mt-1">USD {{ number_format($profit['fee_usd'], 2) }} | Avg: ${{ number_format($stats['avg_transaction'], 2) }}/tx</div>
    </div>
    @can('view-profit')
    <div class="bg-white rounded-lg shadow p-5 border-l-4 border-purple-500">
        <div class="text-sm text-gray-500">Overall Profit</div>
        <div class="text-2xl font-bold {{ $profit['total_ugx'] < 0 ? 'text-red-700' : 'text-purple-700' }} mt-1">{{ $profit['total_ugx'] < 0 ? 'Loss ' : '' }}UGX {{ number_format(abs($profit['total_ugx'])) }}</div>
        <div class="text-xs text-gray-400 mt-1">&yen;{{ number_format($profit['total_rmb'], 2) }} RMB | USD {{ number_format($profit['total_usd'], 2) }}</div>
    </div>
    @endcan
</div>
@endif

@if($isDesk)
{{-- The desk's own bookings, printed the same way the office prints them. --}}
@if($isHub || $reportType === 'transaction')
<div id="section-transfers" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">My Transfers</h3>
        <p class="text-sm text-gray-500">Every transfer you booked in this period, newest first. The receipt number opens the slip you can print.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Receipt #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Sender</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Receiver</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Money Travels</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">RMB</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Charge (UGX)</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Date</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($transactions->take(100) as $t)
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-green-800 whitespace-nowrap">
                        <a href="{{ route('receipts.show', $t->receipt_number) }}">{{ $t->receipt_number }}</a>
                    </td>
                    <td class="px-4 py-4 text-sm text-gray-900">{{ $t->sender_name }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900">{{ $t->receiver_name }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap">{{ $t->principalLabel() }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap">{{ $t->yuanLabel() }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap">{{ $t->feeLabel() }}</td>
                    <td class="px-4 py-4">
                        <x-progress-badge :transaction="$t" />
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $t->created_at->format('M d, Y h:i A') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-8 text-center text-gray-500">You have not booked a transfer in this period.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transactions->count() > 100)
    <p class="px-6 py-3 text-xs text-gray-500 border-t">The hundred most recent bookings are listed. Narrow the dates to read the earlier ones.</p>
    @endif
</div>
@endif

{{-- Every counter deal this desk did, with the money that went each way. --}}
@if(isset($allowed['exchanges']) && ($isHub || $reportType === 'exchanges'))
<div id="section-exchanges" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">My Exchanges</h3>
            <p class="text-sm text-gray-500">Every buy and sell you recorded at the counter in this period. A voided deal is listed so the callback is visible.</p>
        </div>
        @can('export-exchanges')
        <a href="{{ route('export.exchanges', array_filter(['date_from' => $dateFrom, 'date_to' => $dateTo])) }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700 whitespace-nowrap">Export Excel</a>
        @endcan
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-6">
        <div class="rounded-lg border bg-gray-50 p-4">
            <div class="text-sm text-gray-500">Deals</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ number_format($myExchanges->where('status', \App\Models\CurrencyExchange::ACTIVE)->count()) }}</div>
        </div>
        <div class="rounded-lg border bg-gray-50 p-4">
            <div class="text-sm text-gray-500">Shillings In</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ \App\Models\Account::format('UGX', $myExchanges->where('status', \App\Models\CurrencyExchange::ACTIVE)->where('in_currency', 'UGX')->sum('in_amount')) }}</div>
        </div>
        <div class="rounded-lg border bg-gray-50 p-4">
            <div class="text-sm text-gray-500">Shillings Out</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ \App\Models\Account::format('UGX', $myExchanges->where('status', \App\Models\CurrencyExchange::ACTIVE)->where('out_currency', 'UGX')->sum('out_amount')) }}</div>
        </div>
        <div class="rounded-lg border bg-gray-50 p-4">
            <div class="text-sm text-gray-500">Fees Taken</div>
            <div class="text-2xl font-bold text-gray-900 mt-1">{{ \App\Models\Account::format('UGX', $myExchanges->where('status', \App\Models\CurrencyExchange::ACTIVE)->sum('fee')) }}</div>
        </div>
    </div>
    <div class="overflow-x-auto border-t">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Reference</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Customer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">In</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Out</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Fee</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Rate</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($myExchanges as $x)
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-green-800 whitespace-nowrap">
                        <a href="{{ route('exchanges.show', $x) }}">{{ $x->reference }}</a>
                    </td>
                    <td class="px-4 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $x->exchange_date->format('M d, Y') }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900">{{ $x->person_name ?: '-' }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap">{{ \App\Models\Account::format($x->in_currency, $x->in_amount) }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap">{{ \App\Models\Account::format($x->out_currency, $x->out_amount) }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap">{{ \App\Models\Account::format('UGX', $x->fee) }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap">{{ $x->quotedRate() ?? '-' }}</td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 text-xs rounded-full {{ $x->isVoid() ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' }}">{{ $x->isVoid() ? 'Void' : 'Active' }}</span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-8 text-center text-gray-500">You have not recorded an exchange in this period.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- What this desk kept, counted the three ways she reads it. --}}
@if(isset($allowed['charge']) && ($isHub || $reportType === 'charge'))
<div id="section-charge" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">My Charge Collected</h3>
        <p class="text-sm text-gray-500">Counted on the transfers that completed, because a cancellation gives the charge back.</p>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 p-6">
        @foreach(['day' => 'By Day', 'week' => 'By Week', 'month' => 'By Month'] as $key => $heading)
        <div>
            <div class="text-sm font-semibold text-gray-700 mb-2">{{ $heading }}</div>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="text-left py-2 text-gray-500">Period</th>
                        <th class="text-right py-2 text-gray-500">Txns</th>
                        <th class="text-right py-2 text-gray-500">Charge (UGX)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($myCharge[$key] as $row)
                    <tr>
                        <td class="py-2 text-gray-900">{{ $row['label'] }}</td>
                        <td class="py-2 text-right text-gray-900">{{ number_format($row['count']) }}</td>
                        <td class="py-2 text-right text-gray-900">{{ number_format($row['fee_ugx']) }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="3" class="py-3 text-gray-500">No completed transfer in this period.</td>
                    </tr>
                    @endforelse
                </tbody>
                @if(count($myCharge[$key]))
                <tfoot>
                    <tr class="border-t">
                        <td class="py-2 font-bold text-gray-900">Total</td>
                        <td class="py-2 text-right font-bold text-gray-900">{{ number_format(collect($myCharge[$key])->sum('count')) }}</td>
                        <td class="py-2 text-right font-bold text-gray-900">{{ number_format(collect($myCharge[$key])->sum('fee_ugx')) }}</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- Every cash hand-in this desk sent up, and what happened to it. --}}
@if(isset($allowed['handins']) && ($isHub || $reportType === 'handins'))
<div id="section-handins" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">My Hand-ins</h3>
        <p class="text-sm text-gray-500">Every cash hand-in you sent up in this period, and what happened to it.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Reference</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Date</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Handed Over</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Charge</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Bookings</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Sent To</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Note</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($myHandins as $handover)
                @php $receipts = $handover->receiptNumbers(); @endphp
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-green-800 whitespace-nowrap">{{ $handover->reference }}</td>
                    <td class="px-4 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $handover->created_at->format('M d, Y h:i A') }}</td>
                    <td class="px-4 py-4 text-sm text-right text-gray-900 tabular-nums whitespace-nowrap">{{ \App\Models\Account::format($handover->currency, $handover->amount) }}</td>
                    <td class="px-4 py-4 text-sm text-right text-gray-900 tabular-nums whitespace-nowrap">{{ \App\Models\Account::format('UGX', $handover->charge_ugx) }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900">
                        @if($receipts->isEmpty())
                        <span class="text-gray-400">-</span>
                        @else
                        {{ $receipts->take(3)->implode(', ') }}
                        @if($receipts->count() > 3)
                        <span class="text-gray-400"> +{{ $receipts->count() - 3 }} more</span>
                        @endif
                        @endif
                    </td>
                    <td class="px-4 py-4 text-sm text-gray-900">{{ $handover->superAgent?->name ?? '-' }}</td>
                    <td class="px-4 py-4">
                        <span class="px-2 py-1 text-xs rounded-full
                            @if($handover->status === \App\Models\CashHandover::APPROVED) bg-green-100 text-green-800
                            @elseif($handover->status === \App\Models\CashHandover::DECLINED) bg-red-100 text-red-800
                            @elseif($handover->status === \App\Models\CashHandover::CANCELLED) bg-gray-100 text-gray-600
                            @else bg-yellow-100 text-yellow-800 @endif">
                            {{ ucfirst($handover->status) }}
                        </span>
                        @if($handover->decision_note)
                        <p class="text-xs text-gray-500 mt-1">{{ $handover->decision_note }}</p>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $handover->note ?: '-' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-8 text-center text-gray-500">You have not sent a cash hand-in in this period.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- In hand, handed in, and the part of it that is the desk's own pay. --}}
@if(isset($allowed['wallet']) && ($isHub || $reportType === 'wallet'))
<div id="section-wallet" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200 flex items-start justify-between gap-4">
        <div>
            <h3 class="text-lg font-semibold text-gray-900">My Wallet Summary</h3>
            <p class="text-sm text-gray-500">What you are holding right now, whatever the dates above. Still to hand in is that figure less your own commission.</p>
        </div>
        <a href="{{ route('wallet.index') }}" class="text-sm text-green-800 hover:underline whitespace-nowrap">Cash history and hand in</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 p-6">
        @foreach($myWallet as $w)
        <div class="rounded-lg border border-gray-200 p-5">
            <div class="text-sm text-gray-500 uppercase tracking-wide">{{ $w['currency'] }} Wallet</div>
            <div class="text-3xl font-bold text-gray-900 mt-2 tabular-nums">{{ \App\Models\Account::format($w['currency'], $w['in_hand']) }}</div>
            <x-wallet-updated :at="$w['updated_at']" class="mt-2" />
            <dl class="mt-4 space-y-1 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-600">Commission kept</dt>
                    <dd class="text-gray-900 tabular-nums">{{ $w['currency'] === 'UGX' ? \App\Models\Account::format('UGX', $w['kept']) : 'None, pay is in shillings' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-600">Still to hand in</dt>
                    <dd class="font-semibold text-gray-900 tabular-nums">{{ \App\Models\Account::format($w['currency'], $w['owed']) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-600">Handed in so far</dt>
                    <dd class="text-gray-900 tabular-nums">{{ \App\Models\Account::format($w['currency'], $w['handed_in']) }}</dd>
                </div>
            </dl>
        </div>
        @endforeach
    </div>
</div>
@endif
@endif

@if(isset($allowed['profit']) && ($isHub || $reportType === 'profit'))
<div id="section-profit" class="mb-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">Profit</h3>
                <p class="text-sm text-gray-500">The charge kept plus what the rates earned, added up for the transfers that completed in this period.</p>
            </div>
            <a href="{{ route('export.profit-report', ['date_from' => $dateFrom, 'date_to' => $dateTo]) }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700 whitespace-nowrap">Export Excel</a>
        </div>

        <div class="px-6 pt-5">
            <div class="text-sm font-semibold text-gray-700">Profit in shillings</div>
            <p class="text-xs text-gray-500 mt-1">Each transfer is read at the rates it was booked at, never re-converted at today's rate.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 p-6">
            <div class="rounded-lg border border-green-200 bg-green-50 p-4">
                <div class="text-sm text-green-900">Overall Profit</div>
                <div class="text-2xl font-bold {{ $profit['total_ugx'] < 0 ? 'text-red-700' : 'text-green-800' }} mt-1">{{ $profit['total_ugx'] < 0 ? 'Loss ' : '' }}UGX {{ number_format(abs($profit['total_ugx'])) }}</div>
                <div class="text-xs {{ $profit['total_ugx'] < 0 ? 'text-red-600' : 'text-green-700' }} mt-1">&yen;{{ number_format(abs($profit['total_rmb']), 2) }} RMB, being the same money counted in what we pay out in China | USD {{ number_format(abs($profit['total_usd']), 2) }}</div>
            </div>
            <div class="rounded-lg border bg-gray-50 p-4">
                <div class="text-sm text-gray-500">Charge</div>
                <div class="text-xl font-bold text-gray-900 mt-1">UGX {{ number_format($profit['fee_ugx']) }}</div>
                <div class="text-xs text-gray-500 mt-1">USD {{ number_format($profit['fee_usd'], 2) }}</div>
            </div>
            <div class="rounded-lg border bg-gray-50 p-4">
                <div class="text-sm text-gray-500">Foreign Exchange Profit</div>
                <div class="text-xl font-bold {{ $profit['exchange_ugx'] < 0 ? 'text-red-600' : 'text-gray-900' }} mt-1">{{ $profit['exchange_ugx'] < 0 ? 'Loss ' : '' }}UGX {{ number_format(abs($profit['exchange_ugx'])) }}</div>
                <div class="text-xs {{ $profit['exchange_usd'] < 0 ? 'text-red-600' : 'text-gray-500' }} mt-1">{{ $profit['exchange_usd'] < 0 ? 'Loss ' : '' }}USD {{ number_format(abs($profit['exchange_usd']), 2) }}</div>
            </div>
            <div class="rounded-lg border bg-gray-50 p-4">
                <div class="text-sm text-gray-500">Profit on One Dollar Sent</div>
                <div class="text-xl font-bold text-gray-900 mt-1">UGX {{ number_format($profit['per_dollar_ugx']) }}</div>
                <div class="text-xs text-gray-500 mt-1">for each USD 1 sent</div>
            </div>
        </div>

        @if($unpricedCount > 0)
        <div class="mx-6 mb-4 rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            <p>{{ number_format($unpricedCount) }} completed {{ Str::plural('transfer', $unpricedCount) }} {{ $unpricedCount === 1 ? 'was booked before its buying' : 'were booked before their buying' }} rates were stored, so {{ $unpricedCount === 1 ? 'it adds' : 'they add' }} nothing to the Foreign Exchange Profit above. That figure is short by whatever those transfers really earned, and nothing here is guessed to cover it. Enter the buying rates in System Settings before booking, and every transfer from that day onwards is priced.</p>
            <p class="mt-1 text-xs">
                Affected: {{ $unpricedReceipts->take(10)->implode(', ') }}@if($unpricedCount > 10) and {{ number_format($unpricedCount - 10) }} more @endif
            </p>
        </div>
        @endif
    </div>
</div>
@endif

{{-- Commission Section: the office's own report, one line per desk --}}
@if(isset($allowed['commission']) && ($isHub || $reportType === 'commission'))
<div id="section-commission" class="mb-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">{{ $commission['everyone'] ? 'Commissions Earned' : 'My Commission' }}</h3>
            <p class="text-sm text-gray-500">{{ $commission['everyone'] ? 'The service fee each desk kept from the cash they collected. A charge taken at the office desk stays in the till, so it is not listed here.' : 'The service fee you kept from the cash you collected.' }}</p>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-6">
            <div class="rounded-lg border border-green-200 bg-green-50 p-4">
                <div class="text-sm text-green-900">Net Commission</div>
                <div class="text-2xl font-bold text-green-800 mt-1">UGX {{ number_format($commission['net']) }}</div>
            </div>
            <div class="rounded-lg border bg-gray-50 p-4">
                <div class="text-sm text-gray-500">Earned</div>
                <div class="text-xl font-bold text-gray-900 mt-1">UGX {{ number_format($commission['earned']) }}</div>
            </div>
            <div class="rounded-lg border bg-gray-50 p-4">
                <div class="text-sm text-gray-500">Given Back (Cancellations)</div>
                <div class="text-xl font-bold text-gray-900 mt-1">UGX {{ number_format($commission['reversed']) }}</div>
            </div>
            <div class="rounded-lg border bg-gray-50 p-4">
                <div class="text-sm text-gray-500">Transfers</div>
                <div class="text-xl font-bold text-gray-900 mt-1">{{ number_format($commission['transfers']) }}</div>
            </div>
        </div>

        @if($commission['everyone'])
        <div class="overflow-x-auto border-t">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Person</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Role</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Transfers</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Earned (UGX)</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Given Back (UGX)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Net (UGX)</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    @forelse($commission['rows'] as $row)
                    <tr>
                        <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $row->name }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">{{ $row->role }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((int) $row->transfers) }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $row->earned) }}</td>
                        <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $row->reversed) }}</td>
                        <td class="px-6 py-3 text-sm text-right font-medium text-gray-900">{{ number_format((float) $row->net) }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-6 py-8 text-center text-gray-500">No commission entries in this period.</td>
                    </tr>
                    @endforelse
                </tbody>
                @if($commission['rows']->isNotEmpty())
                <tfoot class="bg-gray-50">
                    <tr>
                        <td class="px-6 py-3 text-sm font-bold text-gray-900" colspan="2">Total</td>
                        <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($commission['transfers']) }}</td>
                        <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($commission['earned']) }}</td>
                        <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($commission['reversed']) }}</td>
                        <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($commission['net']) }}</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
        @endif
    </div>
</div>
@endif

@if($isHub)
{{-- Charts --}}
<div id="section-transaction" class="mb-6">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Daily Transaction Volume</h3>
            <div style="position:relative;height:250px"><canvas id="dailyTransactionsChart"></canvas></div>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Daily Charges Collected</h3>
            <div style="position:relative;height:250px"><canvas id="dailyRevenueChart"></canvas></div>
        </div>
    </div>
</div>

<div id="section-revenue" class="mb-6">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Transaction Status Distribution</h3>
            <div style="position:relative;height:250px"><canvas id="statusChart"></canvas></div>
        </div>
        {{-- The comparison is a chart of every desk against each other, so a desk is not shown
             one bar carrying their own name. --}}
        @if(isset($allowed['revenue']))
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Agent Performance Comparison</h3>
            <div style="position:relative;height:250px"><canvas id="agentChart"></canvas></div>
        </div>
        @endif
    </div>
</div>
@endif

@if($isHub || $reportType === 'revenue')
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    {{-- By Currency --}}
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Breakdown by Currency</h3>
        @if($byCurrency->isEmpty())
            <p class="text-gray-500 text-sm">No completed transactions in this period.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="text-left py-2 text-gray-500">Currency</th>
                        <th class="text-right py-2 text-gray-500">Count</th>
                        <th class="text-right py-2 text-gray-500">Total</th>
                        <th class="text-right py-2 text-gray-500">USD Equiv.</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($byCurrency as $currency => $data)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $currency }}</td>
                        <td class="py-2 text-right">{{ $data['count'] }}</td>
                        <td class="py-2 text-right">{{ $currency }} {{ number_format($data['total'], \App\Models\Transaction::amountDecimals($currency)) }}</td>
                        <td class="py-2 text-right">${{ number_format($data['usd_equivalent'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Status Breakdown --}}
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Status Breakdown</h3>
        <div class="grid grid-cols-3 gap-4">
            <div class="text-center p-4 rounded-lg bg-green-50">
                <div class="text-2xl font-bold text-green-800">{{ $stats['completed'] }}</div>
                <div class="text-sm text-green-700">Completed</div>
                @if($stats['total_transactions'] > 0)
                <div class="text-xs text-green-600 mt-1">{{ number_format(($stats['completed'] / $stats['total_transactions']) * 100, 1) }}%</div>
                @endif
            </div>
            <div class="text-center p-4 rounded-lg bg-yellow-50">
                <div class="text-2xl font-bold text-yellow-700">{{ $stats['pending'] }}</div>
                <div class="text-sm text-yellow-700">Pending</div>
                @if($stats['total_transactions'] > 0)
                <div class="text-xs text-yellow-600 mt-1">{{ number_format(($stats['pending'] / $stats['total_transactions']) * 100, 1) }}%</div>
                @endif
            </div>
            <div class="text-center p-4 rounded-lg bg-red-50">
                <div class="text-2xl font-bold text-red-700">{{ $stats['cancelled'] }}</div>
                <div class="text-sm text-red-700">Cancelled</div>
                @if($stats['total_transactions'] > 0)
                <div class="text-xs text-red-600 mt-1">{{ number_format(($stats['cancelled'] / $stats['total_transactions']) * 100, 1) }}%</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endif

{{-- The office's own list of bookings; a desk reads their list in My Transfers above. --}}
@if(! $isDesk && ($isHub || $reportType === 'transaction'))
<div id="section-transfers-office" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Transfers</h3>
        <p class="text-sm text-gray-500">Every transfer booked in this period, newest first. The receipt number opens the slip you can print.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Receipt #</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Sender</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Receiver</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Money Travels</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">RMB</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Charge (UGX)</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Agent</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Date</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($transactions->take(100) as $t)
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-green-800 whitespace-nowrap">
                        <a href="{{ route('receipts.show', $t->receipt_number) }}">{{ $t->receipt_number }}</a>
                    </td>
                    <td class="px-4 py-4 text-sm text-gray-900">{{ $t->sender_name }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900">{{ $t->receiver_name }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap">{{ $t->principalLabel() }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap">{{ $t->yuanLabel() }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900 whitespace-nowrap">{{ $t->feeLabel() }}</td>
                    <td class="px-4 py-4 text-sm text-gray-900">{{ $t->creator?->name ?? 'Unknown' }}</td>
                    <td class="px-4 py-4">
                        <x-progress-badge :transaction="$t" />
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $t->created_at->format('M d, Y h:i A') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="px-6 py-8 text-center text-gray-500">No transfers in this period.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($transactions->count() > 100)
    <p class="px-6 py-3 text-xs text-gray-500 border-t">The hundred most recent bookings are listed. Narrow the dates to read the earlier ones.</p>
    @endif
</div>
@endif

{{-- One table per day, profit columns for the accounts that own the profit cards --}}
@if($isHub || $reportType === 'daily')
<div id="section-daily" class="bg-white rounded-lg shadow mb-6">
    @if($isDesk)
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">My Daily Summary</h3>
        <p class="text-sm text-gray-500">A line for every day you booked something, with the three states counted apart. The money columns are the transfers that completed.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Day</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Completed</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Pending</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Cancelled</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">USD Sent</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Charge (UGX)</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($myDays as $day => $row)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ \Carbon\Carbon::parse($day)->format('M d, Y') }}</td>
                    <td class="px-4 py-3 text-sm text-right text-green-800">{{ number_format($row['completed']) }}</td>
                    <td class="px-4 py-3 text-sm text-right text-yellow-700">{{ number_format($row['pending']) }}</td>
                    <td class="px-4 py-3 text-sm text-right text-red-700">{{ number_format($row['cancelled']) }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format($row['total_usd'], 2) }}</td>
                    <td class="px-6 py-3 text-sm text-right text-gray-900">{{ number_format($row['fee_ugx']) }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">You have not booked a transfer in this period.</td>
                </tr>
                @endforelse
            </tbody>
            @if($myDays->isNotEmpty())
            <tfoot class="bg-gray-50">
                <tr>
                    <td class="px-6 py-3 text-sm font-bold text-gray-900">Total</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($myDays->sum(fn ($r) => $r['completed'])) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($myDays->sum(fn ($r) => $r['pending'])) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($myDays->sum(fn ($r) => $r['cancelled'])) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($myDays->sum(fn ($r) => $r['total_usd']), 2) }}</td>
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($myDays->sum(fn ($r) => $r['fee_ugx'])) }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
    @else
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Daily Summary</h3>
        <p class="text-sm text-gray-500">One row for every day a transfer completed, with the period totals on the bottom row. A day with no completed transfer has no row.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Day</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Txns</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">USD Sent</th>
                    @if($showProfit)
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Charge (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Exchange Profit (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Overall (UGX)</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Overall (RMB)</th>
                    @else
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Charge (UGX)</th>
                    @endif
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($dailySummary as $day)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ \Carbon\Carbon::parse($day->date)->format('M d, Y') }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ $day->count }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $day->total_usd, 2) }}</td>
                    @if($showProfit)
                    @php $dayTotal = (float) $day->fee_ugx + (float) $day->exchange_ugx; @endphp
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $day->fee_ugx) }}</td>
                    <td class="px-4 py-3 text-sm text-right {{ (float) $day->exchange_ugx < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format((float) $day->exchange_ugx) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-medium {{ $dayTotal < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($dayTotal) }}</td>
                    <td class="px-6 py-3 text-sm text-right text-gray-900">{{ number_format((float) $day->total_rmb, 2) }}</td>
                    @else
                    <td class="px-6 py-3 text-sm text-right text-gray-900">{{ number_format((float) $day->fee_ugx) }}</td>
                    @endif
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $showProfit ? 7 : 4 }}" class="px-6 py-8 text-center text-gray-500">No completed transactions in this period.</td>
                </tr>
                @endforelse
            </tbody>
            @if($dailySummary->isNotEmpty())
            <tfoot class="bg-gray-50">
                <tr>
                    <td class="px-6 py-3 text-sm font-bold text-gray-900">Total</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ $dailySummary->sum('count') }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $dailySummary->sum('total_usd'), 2) }}</td>
                    @if($showProfit)
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($profit['fee_ugx']) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold {{ $profit['exchange_ugx'] < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($profit['exchange_ugx']) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold {{ $profit['total_ugx'] < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($profit['total_ugx']) }}</td>
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($profit['total_rmb'], 2) }}</td>
                    @else
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $dailySummary->sum('fee_ugx')) }}</td>
                    @endif
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
    @endif
</div>
@endif

{{-- One table per desk --}}
@if(isset($allowed['agent']) && ($isHub || $reportType === 'agent'))
<div id="section-agent" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Performance by Agent</h3>
        <p class="text-sm text-gray-500">Every desk that booked a completed transfer in the period. Whoever is not an admin sees their own line only.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Agent</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Txns</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">USD Sent</th>
                    @if($showProfit)
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Charge (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Exchange Profit (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Overall (UGX)</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Overall (RMB)</th>
                    @else
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Charge (UGX)</th>
                    @endif
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($byAgent as $agent)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $agent->creator?->name ?? 'Unknown' }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ $agent->count }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $agent->total_usd, 2) }}</td>
                    @if($showProfit)
                    @php $agentTotal = (float) $agent->fee_ugx + (float) $agent->exchange_ugx; @endphp
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $agent->fee_ugx) }}</td>
                    <td class="px-4 py-3 text-sm text-right {{ (float) $agent->exchange_ugx < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format((float) $agent->exchange_ugx) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-medium {{ $agentTotal < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($agentTotal) }}</td>
                    <td class="px-6 py-3 text-sm text-right text-gray-900">{{ number_format((float) $agent->total_rmb, 2) }}</td>
                    @else
                    <td class="px-6 py-3 text-sm text-right text-gray-900">{{ number_format((float) $agent->fee_ugx) }}</td>
                    @endif
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $showProfit ? 7 : 4 }}" class="px-6 py-8 text-center text-gray-500">No completed transactions in this period.</td>
                </tr>
                @endforelse
            </tbody>
            @if($byAgent->isNotEmpty())
            <tfoot class="bg-gray-50">
                <tr>
                    <td class="px-6 py-3 text-sm font-bold text-gray-900">Total</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ $byAgent->sum('count') }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $byAgent->sum('total_usd'), 2) }}</td>
                    @if($showProfit)
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($profit['fee_ugx']) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold {{ $profit['exchange_ugx'] < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($profit['exchange_ugx']) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold {{ $profit['total_ugx'] < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($profit['total_ugx']) }}</td>
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($profit['total_rmb'], 2) }}</td>
                    @else
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $byAgent->sum('fee_ugx')) }}</td>
                    @endif
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>
@endif

{{-- Customer activity, grouped from the name on each booking --}}
@if(isset($allowed['customer']) && ($isHub || $reportType === 'customer'))
<div id="section-customer" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Customer Activity</h3>
        <p class="text-sm text-gray-500">The sending side of every completed transfer, ranked by the dollars moved. Whoever is not an admin sees only their own senders.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Customer</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Transfers</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">USD Sent</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">RMB Booked</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Fee Paid (UGX)</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Last Transfer</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($byCustomer as $customer)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $customer->sender_name }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ $customer->count }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $customer->total_usd, 2) }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $customer->rmb_booked, 2) }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $customer->fee_ugx) }}</td>
                    <td class="px-6 py-3 text-sm text-right text-gray-900">{{ \Carbon\Carbon::parse($customer->last_transfer)->format('M d, Y') }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">No completed transactions in this period.</td>
                </tr>
                @endforelse
            </tbody>
            @if($byCustomer->isNotEmpty())
            <tfoot class="bg-gray-50">
                <tr>
                    <td class="px-6 py-3 text-sm font-bold text-gray-900">{{ number_format($byCustomer->count()) }} {{ Str::plural('customer', $byCustomer->count()) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ $byCustomer->sum('count') }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $byCustomer->sum('total_usd'), 2) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $byCustomer->sum('rmb_booked'), 2) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $byCustomer->sum('fee_ugx')) }}</td>
                    <td class="px-6 py-3 text-sm text-right text-gray-500"></td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>
@endif

{{-- What each dollar rate carried, which the rate sheet on its own cannot show --}}
@if(isset($allowed['rates']) && ($isHub || $reportType === 'rates'))
<div id="section-rates" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Rates Booked in This Period</h3>
        <p class="text-sm text-gray-500">Completed transfers grouped by the dollar to yuan rate they were booked at, so a rate change can be read against the business it brought. The exchange profit reads zero on transfers booked before a buying rate was recorded.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Rate (USD to RMB)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Txns</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">USD Sent</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">RMB Booked</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Charge (UGX)</th>
                    @if($showProfit)
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Exchange Profit (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Overall (UGX)</th>
                    @endif
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Booked</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($byRate as $rate)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ rtrim(rtrim(number_format((float) $rate->exchange_rate, 4, '.', ','), '0'), '.') }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ $rate->count }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $rate->total_usd, 2) }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $rate->rmb_booked, 2) }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $rate->fee_ugx) }}</td>
                    @if($showProfit)
                    <td class="px-4 py-3 text-sm text-right {{ (float) $rate->exchange_ugx < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format((float) $rate->exchange_ugx) }}</td>
                    @php $rateTotal = (float) $rate->fee_ugx + (float) $rate->exchange_ugx; @endphp
                    <td class="px-4 py-3 text-sm text-right font-medium {{ $rateTotal < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($rateTotal) }}</td>
                    @endif
                    <td class="px-4 py-3 text-sm text-gray-900">{{ \Carbon\Carbon::parse($rate->first_booked)->format('M d, Y') }}@if(\Carbon\Carbon::parse($rate->last_booked)->toDateString() !== \Carbon\Carbon::parse($rate->first_booked)->toDateString()) to {{ \Carbon\Carbon::parse($rate->last_booked)->format('M d, Y') }}@endif</td>
                    <td class="px-6 py-3 text-sm text-gray-900">
                        @if((float) $rate->exchange_rate <= 0)
                            <span class="px-2 py-0.5 rounded-full text-xs bg-red-50 text-red-700">No rate</span>
                        @elseif($rate->is_current)
                            <span class="px-2 py-0.5 rounded-full text-xs bg-green-50 text-green-700">Current</span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Superseded</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $showProfit ? 9 : 7 }}" class="px-6 py-8 text-center text-gray-500">No completed transactions in this period.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    @php($dailyChart = $dailySummary->map(fn ($day) => ['date' => $day->date, 'count' => $day->count, 'total_usd' => $day->total_usd, 'fee_ugx' => $day->fee_ugx]))
    const dailySummary = @json($dailyChart);
    // Only the three fields the chart draws, so no account details travel in the page source.
    const byAgent = @json($byAgent->map(fn ($a) => ['name' => $a->creator?->name ?? 'Unknown', 'count' => (int) $a->count, 'charge' => (float) $a->fee_ugx])->values());
    const stats = @json($stats);
    const has = (id) => document.getElementById(id) !== null;

    if (has('dailyTransactionsChart') && dailySummary.length > 0) {
        const dates = dailySummary.map(d => d.date);
        const counts = dailySummary.map(d => d.count);
        const totals = dailySummary.map(d => d.total_usd);
        const revenues = dailySummary.map(d => d.fee_ugx);

        new Chart(document.getElementById('dailyTransactionsChart'), {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Transactions',
                    data: counts,
                    borderColor: 'rgb(22, 163, 74)',
                    backgroundColor: 'rgba(22, 163, 74, 0.1)',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });

        new Chart(document.getElementById('dailyRevenueChart'), {
            type: 'bar',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Charge (UGX)',
                    data: revenues,
                    backgroundColor: 'rgba(21, 128, 61, 0.8)',
                    borderColor: 'rgb(21, 128, 61)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }

    if (has('statusChart')) {
        new Chart(document.getElementById('statusChart'), {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'Pending', 'Cancelled'],
                datasets: [{
                    data: [stats.completed, stats.pending, stats.cancelled],
                    backgroundColor: [
                        'rgba(22, 163, 74, 0.8)',
                        'rgba(234, 179, 8, 0.8)',
                        'rgba(239, 68, 68, 0.8)'
                    ],
                    borderColor: [
                        'rgb(22, 163, 74)',
                        'rgb(234, 179, 8)',
                        'rgb(239, 68, 68)'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    }

    if (has('agentChart') && byAgent.length > 0) {
        const agentNames = byAgent.map(a => a.name);
        const agentCounts = byAgent.map(a => a.count);
        const agentRevenue = byAgent.map(a => a.charge);

        new Chart(document.getElementById('agentChart'), {
            type: 'bar',
            data: {
                labels: agentNames,
                datasets: [
                    {
                        label: 'Transactions',
                        data: agentCounts,
                        backgroundColor: 'rgba(22, 163, 74, 0.8)',
                        yAxisID: 'y'
                    },
                    {
                        label: 'Charge (UGX)',
                        data: agentRevenue,
                        backgroundColor: 'rgba(147, 51, 234, 0.8)',
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Transactions' } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Charge (UGX)' } }
                }
            }
        });
    }
</script>
@endsection
