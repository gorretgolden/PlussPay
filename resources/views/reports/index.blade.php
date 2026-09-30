@extends('layouts.app')

@section('title', 'Reports')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Reports</h1>
        <p class="text-gray-600">Transaction summaries and analytics</p>
    </div>
    <a href="{{ route('reports.export', ['date_from' => $dateFrom, 'date_to' => $dateTo]) }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Export Excel</a>
</div>

{{-- Date Filter --}}
<div class="bg-white rounded-lg shadow p-4 mb-6 no-print">
    <form method="GET" action="{{ route('reports.index') }}" class="flex items-end gap-4 flex-wrap">
        <div>
            <label class="block text-sm text-gray-600 mb-1">From</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">To</label>
            <input type="date" name="date_to" value="{{ $dateTo }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Apply</button>
        <a href="{{ route('reports.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Reset</a>
        <div class="flex gap-2 ml-auto">
            <a href="{{ route('reports.index', ['date_from' => today()->toDateString(), 'date_to' => today()->toDateString()]) }}" class="px-3 py-1 text-xs rounded-full {{ $dateFrom === today()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Today</a>
            <a href="{{ route('reports.index', ['date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->toDateString()]) }}" class="px-3 py-1 text-xs rounded-full {{ $dateFrom === now()->startOfMonth()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">This Month</a>
            <a href="{{ route('reports.index', ['date_from' => now()->subMonth()->startOfMonth()->toDateString(), 'date_to' => now()->subMonth()->endOfMonth()->toDateString()]) }}" class="px-3 py-1 text-xs rounded-full {{ $dateFrom === now()->subMonth()->startOfMonth()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Last Month</a>
        </div>
    </form>
</div>

{{-- Summary Stats --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
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
        <div class="text-sm text-gray-500">RMB Delivered</div>
        <div class="text-2xl font-bold text-purple-700 mt-1">¥{{ number_format($stats['total_rmb_sent'], 2) }}</div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-sm text-gray-500">Revenue (Charges)</div>
        <div class="text-2xl font-bold text-green-700 mt-1">${{ number_format($stats['total_revenue'], 2) }}</div>
        <div class="text-xs text-gray-400 mt-1">Avg: ${{ number_format($stats['avg_transaction'], 2) }}/tx</div>
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
                        <td class="py-2 text-right">{{ $currency }} {{ number_format($data['total'], 2) }}</td>
                        <td class="py-2 text-right">${{ number_format($data['usd_equivalent'], 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- By Agent --}}
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Performance by Agent</h3>
        @if($byAgent->isEmpty())
            <p class="text-gray-500 text-sm">No completed transactions in this period.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b">
                        <th class="text-left py-2 text-gray-500">Agent</th>
                        <th class="text-right py-2 text-gray-500">Transactions</th>
                        <th class="text-right py-2 text-gray-500">USD Sent</th>
                        <th class="text-right py-2 text-gray-500">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($byAgent as $agent)
                    <tr class="border-b">
                        <td class="py-2 font-medium">{{ $agent->creator?->name ?? 'Unknown' }}</td>
                        <td class="py-2 text-right">{{ $agent->count }}</td>
                        <td class="py-2 text-right">${{ number_format($agent->total_usd, 2) }}</td>
                        <td class="py-2 text-right">${{ number_format($agent->total_revenue, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

{{-- Daily Summary --}}
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Daily Summary</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Transactions</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">USD Sent</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Revenue</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($dailySummary as $day)
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ \Carbon\Carbon::parse($day->date)->format('M d, Y') }}</td>
                    <td class="px-6 py-4 text-sm text-right text-gray-900">{{ $day->count }}</td>
                    <td class="px-6 py-4 text-sm text-right text-gray-900">${{ number_format($day->total_usd, 2) }}</td>
                    <td class="px-6 py-4 text-sm text-right text-green-700 font-medium">${{ number_format($day->revenue, 2) }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" class="px-6 py-8 text-center text-gray-500">No transactions in this period.</td>
                </tr>
                @endforelse
            </tbody>
            @if($dailySummary->isNotEmpty())
            <tfoot class="bg-gray-50">
                <tr>
                    <td class="px-6 py-3 text-sm font-bold text-gray-900">Total</td>
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">{{ $dailySummary->sum('count') }}</td>
                    <td class="px-6 py-3 text-sm text-right font-bold text-gray-900">${{ number_format($dailySummary->sum('total_usd'), 2) }}</td>
                    <td class="px-6 py-3 text-sm text-right font-bold text-green-700">${{ number_format($dailySummary->sum('revenue'), 2) }}</td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
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
@endsection
