@extends('layouts.app')

@section('title', 'Transactions')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <h1 class="text-2xl font-bold text-gray-900">Transactions</h1>
    @role('admin|agent')
    <a href="{{ route('transactions.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">New Transaction</a>
    @endrole
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6 no-print">
    <form method="GET" action="{{ route('transactions.index') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm text-gray-600 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Receipt #, sender, receiver"
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
        <span class="text-sm text-gray-500 self-center mr-1">Quick:</span>
        <a href="{{ route('transactions.index', ['date_from' => today()->toDateString(), 'date_to' => today()->toDateString(), 'search' => request('search'), 'status' => request('status')]) }}"
            class="px-3 py-1 text-xs rounded-full {{ request('date_from') === today()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Today</a>
        <a href="{{ route('transactions.index', ['date_from' => today()->subDay()->toDateString(), 'date_to' => today()->subDay()->toDateString(), 'search' => request('search'), 'status' => request('status')]) }}"
            class="px-3 py-1 text-xs rounded-full {{ request('date_from') === today()->subDay()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Yesterday</a>
        <a href="{{ route('transactions.index', ['date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->toDateString(), 'search' => request('search'), 'status' => request('status')]) }}"
            class="px-3 py-1 text-xs rounded-full {{ request('date_from') === now()->startOfMonth()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">This Month</a>
        <a href="{{ route('transactions.index', ['date_from' => now()->subMonth()->startOfMonth()->toDateString(), 'date_to' => now()->subMonth()->endOfMonth()->toDateString(), 'search' => request('search'), 'status' => request('status')]) }}"
            class="px-3 py-1 text-xs rounded-full {{ request('date_from') === now()->subMonth()->startOfMonth()->toDateString() ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">Last Month</a>
    </div>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt #</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sender</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receiver</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">RMB</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Charge</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($transactions as $t)
            <tr>
                <td class="px-6 py-4 text-sm font-medium text-green-800">
                    <a href="{{ route('receipts.show', $t->receipt_number) }}">{{ $t->receipt_number }}</a>
                </td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $t->sender_name }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $t->receiver_name }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $t->currency }} {{ number_format($t->amount, 2) }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">¥{{ number_format($t->rmb_amount, 2) }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">${{ number_format($t->charge_amount, 2) }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs rounded-full
                        @if($t->status === 'completed') bg-green-100 text-green-800
                        @elseif($t->status === 'pending') bg-yellow-100 text-yellow-800
                        @else bg-red-100 text-red-800 @endif">
                        {{ ucfirst($t->status) }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ $t->created_at->format('M d, Y H:i') }}</td>
                <td class="px-6 py-4 text-sm whitespace-nowrap">
                    <a href="{{ route('receipts.show', $t->receipt_number) }}" class="text-green-600 hover:text-green-800 mr-2">View</a>
                    <a href="{{ route('receipts.print', $t->receipt_number) }}" target="_blank" class="text-green-600 hover:text-green-800 mr-2">Print</a>
                    @role('admin')
                    <form method="POST" action="{{ route('transactions.update-status', $t) }}" class="inline">
                        @csrf
                        @method('PATCH')
                        <select name="status" onchange="this.form.submit()" class="text-sm border-gray-300 rounded">
                            <option value="pending" {{ $t->status === 'pending' ? 'selected' : '' }}>Pending</option>
                            <option value="completed" {{ $t->status === 'completed' ? 'selected' : '' }}>Completed</option>
                            <option value="cancelled" {{ $t->status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        </select>
                    </form>
                    @endrole
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" class="px-6 py-8 text-center text-gray-500">No transactions found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $transactions->links() }}
</div>
@endsection
