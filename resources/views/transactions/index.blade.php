@extends('layouts.app')

@section('title', 'Transactions')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <h1 class="text-2xl font-bold text-gray-900">Transactions</h1>
    <div class="flex gap-2">
        @role('admin|superadmin')
        <a href="{{ route('transactions.bulk-import') }}" class="bg-blue-800 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">Bulk Import</a>
        @endrole
        <a href="{{ route('export.transactions', request()->query()) }}" class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
        @role('admin|agent')
        <a href="{{ route('transactions.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">New Transaction</a>
        @endrole
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6 no-print">
    <form method="GET" action="{{ route('transactions.index') }}" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
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
            <label class="block text-sm text-gray-600 mb-1">Currency</label>
            <select name="currency" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                <option value="UGX" {{ request('currency') === 'UGX' ? 'selected' : '' }}>UGX</option>
                <option value="USD" {{ request('currency') === 'USD' ? 'selected' : '' }}>USD</option>
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
            $quickBase = array_filter(request()->only(['search', 'status', 'currency', 'created_by']), fn($v) => $v !== '');
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
                <td class="px-6 py-4 text-sm text-gray-900">UGX {{ number_format((float) $t->charge_ugx) }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs rounded-full
                        @if($t->status === 'completed') bg-green-100 text-green-800
                        @elseif($t->status === 'pending') bg-yellow-100 text-yellow-800
                        @else bg-red-100 text-red-800 @endif">
                        {{ ucfirst($t->status) }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ $t->created_at->format('M d, Y h:i A') }}</td>
                <td data-row-menu-host class="px-6 py-4 text-right relative">
                    <button type="button" onclick="toggleMenu({{ $t->id }})" class="p-1 rounded hover:bg-gray-100">
                        <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                    </button>
                    <div id="menu-{{ $t->id }}" data-row-menu class="hidden absolute right-0 top-8 z-20 bg-white rounded-lg shadow-lg border border-gray-200 py-1 w-44 text-left">
                        <a href="{{ route('receipts.show', $t->receipt_number) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">View Receipt</a>
                        <a href="{{ route('receipts.print', $t->receipt_number) }}" target="_blank" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Print Receipt</a>

                        @role('admin|superadmin')
                        <div class="border-t border-gray-100 my-1"></div>
                        <p class="px-4 py-1 text-xs uppercase text-gray-400">Set Status</p>
                        @foreach(['pending', 'completed', 'cancelled'] as $option)
                        @if($t->status !== $option)
                        <form method="POST" action="{{ route('transactions.update-status', $t) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="{{ $option }}">
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm
                                {{ $option === 'cancelled' ? 'text-red-600 hover:bg-red-50' : 'text-gray-700 hover:bg-gray-50' }}">
                                Mark {{ ucfirst($option) }}
                            </button>
                        </form>
                        @endif
                        @endforeach
                        @endrole
                    </div>
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
