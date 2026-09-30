@extends('layouts.app')

@section('title', 'Receipts')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <h1 class="text-2xl font-bold text-gray-900">Receipts</h1>
    @role('admin|agent')
    <a href="{{ route('transactions.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">New Transaction</a>
    @endrole
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6 no-print">
    <form method="GET" action="{{ route('receipts.index') }}" class="flex gap-4">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search receipt number..."
            class="flex-1 px-3 py-2 border border-gray-300 rounded-md text-sm">
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Search</button>
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
                <td class="px-6 py-4 text-sm text-gray-900">{{ $r->currency }} {{ number_format($r->amount, 2) }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">¥{{ number_format($r->rmb_amount, 2) }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">${{ number_format($r->charge_amount, 2) }}</td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ $r->created_at->format('M d, Y H:i') }}</td>
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
