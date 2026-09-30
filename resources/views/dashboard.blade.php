@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
    <p class="text-gray-600">Welcome, {{ auth()->user()->name }}</p>
</div>

<div class="bg-green-800 rounded-lg shadow p-6 mb-6">
    <h2 class="text-white text-lg font-semibold mb-4">Today's Summary</h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white/10 rounded-lg p-4">
            <div class="text-green-200 text-sm">Transactions Today</div>
            <div class="text-white text-3xl font-bold mt-1">{{ number_format($stats['today_transactions']) }}</div>
        </div>
        <div class="bg-white/10 rounded-lg p-4">
            <div class="text-green-200 text-sm">Money Sent (USD)</div>
            <div class="text-white text-3xl font-bold mt-1">${{ number_format($stats['today_usd_sent'], 2) }}</div>
        </div>
        <div class="bg-white/10 rounded-lg p-4">
            <div class="text-green-200 text-sm">Revenue Today</div>
            <div class="text-white text-3xl font-bold mt-1">${{ number_format($stats['today_revenue'], 2) }}</div>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
    <a href="{{ route('transactions.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Total Transactions</div>
                <div class="text-3xl font-bold text-green-800 mt-1">{{ number_format($stats['total_transactions']) }}</div>
            </div>
            <svg class="w-10 h-10 text-green-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        </div>
    </a>
    <a href="{{ route('transactions.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Money Sent (USD)</div>
                <div class="text-3xl font-bold text-green-700 mt-1">${{ number_format($stats['total_usd_sent'], 2) }}</div>
            </div>
            <svg class="w-10 h-10 text-green-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('transactions.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Total RMB Sent</div>
                <div class="text-3xl font-bold text-purple-700 mt-1">¥{{ number_format($stats['total_rmb_sent'], 2) }}</div>
            </div>
            <svg class="w-10 h-10 text-purple-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 8h6m-5 0a3 3 0 110 6H9l3 3m-3-6h6m6 1a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('reports.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Total Revenue</div>
                <div class="text-3xl font-bold text-green-700 mt-1">${{ number_format($stats['total_revenue'], 2) }}</div>
            </div>
            <svg class="w-10 h-10 text-green-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
        </div>
    </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <a href="{{ route('transactions.index', ['status' => 'pending']) }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Pending</div>
                <div class="text-3xl font-bold text-yellow-600 mt-1">{{ number_format($stats['pending_transactions']) }}</div>
            </div>
            <svg class="w-10 h-10 text-yellow-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('transactions.index', ['status' => 'completed']) }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Completed</div>
                <div class="text-3xl font-bold text-green-700 mt-1">{{ number_format($stats['completed_transactions']) }}</div>
            </div>
            <svg class="w-10 h-10 text-green-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('transactions.index', ['status' => 'cancelled']) }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Cancelled</div>
                <div class="text-3xl font-bold text-red-600 mt-1">{{ number_format($stats['cancelled_transactions']) }}</div>
            </div>
            <svg class="w-10 h-10 text-red-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('transactions.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Avg Transaction</div>
                <div class="text-3xl font-bold text-blue-700 mt-1">${{ number_format($stats['avg_transaction'], 2) }}</div>
            </div>
            <svg class="w-10 h-10 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        </div>
    </a>
</div>

@role('admin')
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
    <a href="{{ route('users.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Active Users</div>
                <div class="text-3xl font-bold text-indigo-700 mt-1">{{ number_format($stats['total_users']) }}</div>
            </div>
            <svg class="w-10 h-10 text-indigo-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('exchange-rates.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Active Exchange Rates</div>
                <div class="text-3xl font-bold text-teal-700 mt-1">{{ number_format($stats['active_rates']) }}</div>
            </div>
            <svg class="w-10 h-10 text-teal-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
        </div>
    </a>
</div>
@endrole

<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-lg font-semibold text-gray-900">Recent Transactions</h2>
        @role('admin|agent')
        <a href="{{ route('transactions.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">New Transaction</a>
        @endrole
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sender</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receiver</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">RMB</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($recentTransactions as $t)
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-green-800">
                        <a href="{{ route('receipts.show', $t->receipt_number) }}">{{ $t->receipt_number }}</a>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $t->sender_name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $t->receiver_name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $t->currency }} {{ number_format($t->amount, 2) }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">¥{{ number_format($t->rmb_amount, 2) }}</td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 text-xs rounded-full
                            @if($t->status === 'completed') bg-green-100 text-green-800
                            @elseif($t->status === 'pending') bg-yellow-100 text-yellow-800
                            @else bg-red-100 text-red-800 @endif">
                            {{ ucfirst($t->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $t->created_at->format('M d, Y') }}</td>
                    <td class="px-6 py-4 text-sm">
                        <a href="{{ route('receipts.print', $t->receipt_number) }}" target="_blank" class="text-green-600 hover:text-green-800 mr-2">Print</a>
                        <a href="{{ route('receipts.show', $t->receipt_number) }}" class="text-green-600 hover:text-green-800">View</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-8 text-center text-gray-500">No transactions yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
