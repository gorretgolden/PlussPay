@extends('layouts.app')

@section('title', 'Reports')

@section('content')
@php
    // The profit columns belong to the same account that owns the Profit cards, so one flag
    // decides which columns a viewer gets instead of repeating the role test everywhere.
    $showProfit = auth()->user()->can('view-profit');
@endphp
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">
            @if($reportType === 'transaction') Transaction Report
            @elseif($reportType === 'revenue') Revenue Report
            @elseif($reportType === 'profit') Profit Report
            @elseif($reportType === 'commission') Commission Report
            @elseif($reportType === 'agent') Agent Report
            @elseif($reportType === 'customer') Customer Report
            @elseif($reportType === 'rates') Exchange Rate Report
            @elseif($reportType === 'daily') Daily Summary
            @else Reports Hub
            @endif
        </h1>
        <p class="text-gray-600">
            @if($reportType === 'transaction') Detailed transaction breakdown
            @elseif($reportType === 'revenue') Revenue and charge analysis
            @elseif($reportType === 'profit') The charge kept plus what the rates earned on the money that travelled
            @elseif($reportType === 'commission') What the desk earned on the transfers they booked
            @elseif($reportType === 'agent') Agent performance metrics
            @elseif($reportType === 'customer') Who the money moved for, and how much each one sent
            @elseif($reportType === 'rates') What each dollar rate carried and what it earned
            @elseif($reportType === 'daily') Day-by-day transaction summary
            @else Transaction summaries and analytics
            @endif
        </p>
    </div>
    <a href="{{ route('reports.export', ['date_from' => $dateFrom, 'date_to' => $dateTo, 'type' => $reportType]) }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Export Excel</a>
</div>

{{-- Date Filter --}}
<div class="bg-white rounded-lg shadow p-4 mb-6 no-print">
    <form method="GET" action="{{ route('reports.index') }}" class="flex items-end gap-4 flex-wrap">
        <input type="hidden" name="type" value="{{ $reportType }}">
        <div>
            <label class="block text-sm text-gray-600 mb-1">From</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">To</label>
            <input type="date" name="date_to" value="{{ $dateTo }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Apply</button>
        <a href="{{ route('reports.index', ['type' => $reportType]) }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Reset</a>
        <div class="flex gap-2 ml-auto">
            <a href="{{ route('reports.index', ['type' => $reportType, 'date_from' => today()->toDateString(), 'date_to' => today()->toDateString()]) }}" class="px-3 py-1 text-xs rounded-full {{ $dateFrom === today()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Today</a>
            <a href="{{ route('reports.index', ['type' => $reportType, 'date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->toDateString()]) }}" class="px-3 py-1 text-xs rounded-full {{ $dateFrom === now()->startOfMonth()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">This Month</a>
            <a href="{{ route('reports.index', ['type' => $reportType, 'date_from' => now()->subMonth()->startOfMonth()->toDateString(), 'date_to' => now()->subMonth()->endOfMonth()->toDateString()]) }}" class="px-3 py-1 text-xs rounded-full {{ $dateFrom === now()->subMonth()->startOfMonth()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Last Month</a>
        </div>
    </form>
</div>

{{-- Summary Stats --}}
<div id="section-hub" class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">Total Transactions</div>
        <div class="text-2xl font-bold text-green-800 mt-1">{{ number_format($stats['total_transactions']) }}</div>
        <div class="text-xs text-gray-400 mt-1">Completed: {{ $stats['completed'] }} | Pending: {{ $stats['pending'] }} | Cancelled: {{ $stats['cancelled'] }}</div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">Money Sent (USD)</div>
        <div class="text-2xl font-bold text-green-700 mt-1">${{ number_format($stats['total_usd_sent'], 1) }}</div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">RMB Delivered</div>
        <div class="text-2xl font-bold text-purple-700 mt-1">¥{{ number_format($stats['total_rmb_sent'], 1) }}</div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">Charges Kept</div>
        <div class="text-2xl font-bold text-green-700 mt-1">UGX {{ number_format($profit['fee_ugx']) }}</div>
        <div class="text-xs text-gray-400 mt-1">USD {{ number_format($profit['fee_usd'], 1) }} | Avg: ${{ number_format($stats['avg_transaction'], 1) }}/tx</div>
    </div>
    @can('view-profit')
    <div class="bg-white rounded-lg shadow p-5 border-l-4 border-purple-500">
        <div class="text-sm text-gray-500">Overall Profit</div>
        <div class="text-2xl font-bold {{ $profit['total_ugx'] < 0 ? 'text-red-700' : 'text-purple-700' }} mt-1">{{ $profit['total_ugx'] < 0 ? 'Loss ' : '' }}UGX {{ number_format(abs($profit['total_ugx']), 1) }}</div>
        <div class="text-xs text-gray-400 mt-1">&yen;{{ number_format($profit['total_rmb'], 1) }} RMB | USD {{ number_format($profit['total_usd'], 1) }}</div>
    </div>
    @endcan
</div>

@can('view-profit')
<div id="section-profit" class="mb-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">Profit</h3>
                <p class="text-sm text-gray-500">One profit figure per transfer, added up for the transfers that completed in this period. The charge is what came out of the money handed over. The exchange profit is what the rates came to on the money that travelled: the gap between the shillings taken in and what those dollars cost to buy, together with what the yuan paid out in China cost to settle. The overall profit is the two added, and it is the figure the office reports. The same three figures split by day and by desk are in the Daily Summary and Performance by Agent tables below.</p>
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
                <div class="text-2xl font-bold {{ $profit['total_ugx'] < 0 ? 'text-red-700' : 'text-green-800' }} mt-1">{{ $profit['total_ugx'] < 0 ? 'Loss ' : '' }}UGX {{ number_format(abs($profit['total_ugx']), 1) }}</div>
                <div class="text-xs {{ $profit['total_ugx'] < 0 ? 'text-red-600' : 'text-green-700' }} mt-1">&yen;{{ number_format(abs($profit['total_rmb']), 1) }} RMB, being the same money counted in what we pay out in China</div>
            </div>
            <div class="rounded-lg border bg-gray-50 p-4">
                <div class="text-sm text-gray-500">Charge</div>
                <div class="text-xl font-bold text-gray-900 mt-1">UGX {{ number_format($profit['fee_ugx']) }}</div>
                <div class="text-xs text-gray-500 mt-1">USD {{ number_format($profit['fee_usd'], 1) }}</div>
            </div>
            <div class="rounded-lg border bg-gray-50 p-4">
                <div class="text-sm text-gray-500">Foreign Exchange Profit</div>
                <div class="text-xl font-bold {{ $profit['exchange_ugx'] < 0 ? 'text-red-600' : 'text-gray-900' }} mt-1">{{ $profit['exchange_ugx'] < 0 ? 'Loss ' : '' }}UGX {{ number_format(abs($profit['exchange_ugx']), 1) }}</div>
                <div class="text-xs {{ $profit['exchange_usd'] < 0 ? 'text-red-600' : 'text-gray-500' }} mt-1">{{ $profit['exchange_usd'] < 0 ? 'Loss ' : '' }}USD {{ number_format(abs($profit['exchange_usd']), 1) }}</div>
            </div>
            <div class="rounded-lg border bg-gray-50 p-4">
                <div class="text-sm text-gray-500">Profit on One Dollar Sent</div>
                <div class="text-xl font-bold text-gray-900 mt-1">UGX {{ number_format($profit['per_dollar_ugx'], 1) }}</div>
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
@endcan

{{-- Commission Section: everyone sees their own, admin sees the whole desk --}}
<div id="section-commission" class="mb-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">{{ $commission['everyone'] ? 'Commissions Earned' : 'My Commission' }}</h3>
            <p class="text-sm text-gray-500">{{ $commission['everyone'] ? 'The service fee each agent kept from the cash they collected. A charge taken at the office desk stays in the till, so it is not listed here.' : 'The service fee you kept from the cash you collected.' }} Read from the wallet ledger, so a cancelled transfer gives its commission back here exactly as it does in the wallet.</p>
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
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Person</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Transfers</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Earned (UGX)</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Given Back (UGX)</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Net (UGX)</th>
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
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Agent Performance Comparison</h3>
            <div style="position:relative;height:250px"><canvas id="agentChart"></canvas></div>
        </div>
    </div>
</div>

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
                        <td class="py-2 text-right">{{ $currency }} {{ number_format($data['total'], 1) }}</td>
                        <td class="py-2 text-right">${{ number_format($data['usd_equivalent'], 1) }}</td>
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

{{-- One table per day, profit columns for the accounts that own the profit cards --}}
<div id="section-daily" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Daily Summary</h3>
        <p class="text-sm text-gray-500">One row for every day a transfer completed, with the period totals on the bottom row. A day with no completed transfer has no row.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Day</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Txns</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">USD Sent</th>
                    @if($showProfit)
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Charge (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Exchange Profit (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Overall (UGX)</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Overall (RMB)</th>
                    @else
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Charge (UGX)</th>
                    @endif
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($dailySummary as $day)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ \Carbon\Carbon::parse($day->date)->format('M d, Y') }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ $day->count }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $day->total_usd, 1) }}</td>
                    @if($showProfit)
                    @php $dayTotal = (float) $day->fee_ugx + (float) $day->exchange_ugx; @endphp
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $day->fee_ugx) }}</td>
                    <td class="px-4 py-3 text-sm text-right {{ (float) $day->exchange_ugx < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format((float) $day->exchange_ugx, 1) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-medium {{ $dayTotal < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($dayTotal, 1) }}</td>
                    <td class="px-6 py-3 text-sm text-right text-gray-900">{{ number_format((float) $day->total_rmb, 1) }}</td>
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
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $dailySummary->sum('total_usd'), 1) }}</td>
                    @if($showProfit)
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($profit['fee_ugx']) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold {{ $profit['exchange_ugx'] < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($profit['exchange_ugx'], 1) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold {{ $profit['total_ugx'] < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($profit['total_ugx'], 1) }}</td>
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($profit['total_rmb'], 1) }}</td>
                    @else
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $dailySummary->sum('fee_ugx')) }}</td>
                    @endif
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- One table per desk --}}
<div id="section-agent" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Performance by Agent</h3>
        <p class="text-sm text-gray-500">Every desk that booked a completed transfer in the period. Whoever is not an admin sees their own line only.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agent</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Txns</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">USD Sent</th>
                    @if($showProfit)
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Charge (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Exchange Profit (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Overall (UGX)</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Overall (RMB)</th>
                    @else
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Charge (UGX)</th>
                    @endif
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($byAgent as $agent)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $agent->creator?->name ?? 'Unknown' }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ $agent->count }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $agent->total_usd, 1) }}</td>
                    @if($showProfit)
                    @php $agentTotal = (float) $agent->fee_ugx + (float) $agent->exchange_ugx; @endphp
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $agent->fee_ugx) }}</td>
                    <td class="px-4 py-3 text-sm text-right {{ (float) $agent->exchange_ugx < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format((float) $agent->exchange_ugx, 1) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-medium {{ $agentTotal < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($agentTotal, 1) }}</td>
                    <td class="px-6 py-3 text-sm text-right text-gray-900">{{ number_format((float) $agent->total_rmb, 1) }}</td>
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
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $byAgent->sum('total_usd'), 1) }}</td>
                    @if($showProfit)
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($profit['fee_ugx']) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold {{ $profit['exchange_ugx'] < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($profit['exchange_ugx'], 1) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold {{ $profit['total_ugx'] < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($profit['total_ugx'], 1) }}</td>
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ number_format($profit['total_rmb'], 1) }}</td>
                    @else
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $byAgent->sum('fee_ugx')) }}</td>
                    @endif
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- Customer activity, grouped from the name on each booking --}}
<div id="section-customer" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Customer Activity</h3>
        <p class="text-sm text-gray-500">The sending side of every completed transfer, ranked by the dollars moved. Counted from the name written on the booking, so the same person spelled two ways shows as two customers. Whoever is not an admin sees only their own senders.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Transfers</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">USD Sent</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">RMB Delivered</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Fee Paid (UGX)</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Last Transfer</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($byCustomer as $customer)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ $customer->sender_name }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ $customer->count }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $customer->total_usd, 1) }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $customer->rmb_delivered, 1) }}</td>
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
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $byCustomer->sum('total_usd'), 1) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $byCustomer->sum('rmb_delivered'), 1) }}</td>
                    <td class="px-4 py-3 text-sm text-right font-bold text-gray-900">{{ number_format((float) $byCustomer->sum('fee_ugx')) }}</td>
                    <td class="px-6 py-3 text-sm text-right text-gray-500"></td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

@can('view-profit')
{{-- What each dollar rate carried, which the rate sheet on its own cannot show --}}
<div id="section-rates" class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Rates Booked in This Period</h3>
        <p class="text-sm text-gray-500">Completed transfers grouped by the dollar to yuan rate they were booked at, so a rate change can be read against the business it brought. The exchange profit reads zero on transfers booked before a buying rate was recorded.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rate (USD to RMB)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Txns</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">USD Sent</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">RMB Delivered</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Charge (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Exchange Profit (UGX)</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Overall (UGX)</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Booked</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($byRate as $rate)
                <tr>
                    <td class="px-6 py-3 text-sm font-medium text-gray-900">{{ rtrim(rtrim(number_format((float) $rate->exchange_rate, 4, '.', ''), '0'), '.') }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ $rate->count }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $rate->total_usd, 1) }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $rate->rmb_delivered, 1) }}</td>
                    <td class="px-4 py-3 text-sm text-right text-gray-900">{{ number_format((float) $rate->fee_ugx) }}</td>
                    <td class="px-4 py-3 text-sm text-right {{ (float) $rate->exchange_ugx < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format((float) $rate->exchange_ugx, 1) }}</td>
                    @php $rateTotal = (float) $rate->fee_ugx + (float) $rate->exchange_ugx; @endphp
                    <td class="px-4 py-3 text-sm text-right font-medium {{ $rateTotal < 0 ? 'text-red-600' : 'text-gray-900' }}">{{ number_format($rateTotal, 1) }}</td>
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
                    <td colspan="9" class="px-6 py-8 text-center text-gray-500">No completed transactions in this period.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endcan

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    @php($dailyChart = $dailySummary->map(fn ($day) => ['date' => $day->date, 'count' => $day->count, 'total_usd' => $day->total_usd, 'fee_ugx' => $day->fee_ugx]))
    const dailySummary = @json($dailyChart);
    // Only the three fields the chart draws, so no account details travel in the page source.
    const byAgent = @json($byAgent->map(fn ($a) => ['name' => $a->creator?->name ?? 'Unknown', 'count' => (int) $a->count, 'charge' => (float) $a->fee_ugx])->values());
    const stats = @json($stats);

    if (dailySummary.length > 0) {
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

    if (byAgent.length > 0) {
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
<script>
    const reportType = @json($reportType);
    const sectionMap = { transaction: 'section-transaction', revenue: 'section-revenue', profit: 'section-profit', commission: 'section-commission', agent: 'section-agent', customer: 'section-customer', rates: 'section-rates', daily: 'section-daily' };
    if (sectionMap[reportType]) {
        const el = document.getElementById(sectionMap[reportType]);
        if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
</script>
@endsection
