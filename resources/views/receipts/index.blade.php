@extends('layouts.app')

@section('title', 'Receipts')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <h1 class="text-2xl font-bold text-gray-900">Receipts</h1>
    <div class="flex gap-2">
        <a href="{{ route('export.receipts', request()->query()) }}" class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6 no-print">
    <form method="GET" action="{{ route('receipts.index') }}" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[220px]">
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Receipt #, sender, receiver..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">From Date</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To Date</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}"
                class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
        <a href="{{ route('receipts.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
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
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($receipts as $r)
            <tr>
                <td class="px-6 py-4 text-sm font-medium text-green-800">{{ $r->receipt_number }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $r->sender_name }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $r->receiver_name }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $r->principalLabel() }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">¥{{ number_format($r->rmb_amount, 1) }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">UGX {{ number_format((float) $r->charge_ugx) }}</td>
                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $r->created_at->format('Y-m-d h:i A') }}</td>
                <td class="px-6 py-4 text-sm">
                    <a href="{{ route('receipts.show', $r->receipt_number) }}" class="text-green-600 hover:text-green-800 mr-2">View</a>
                    <a href="{{ route('receipts.print', $r->receipt_number) }}" target="_blank" class="text-green-600 hover:text-green-800">Print</a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="px-6 py-8 text-center text-gray-500">No receipts found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $receipts->links() }}
</div>
@endsection
