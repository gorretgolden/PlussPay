@extends('layouts.app')

@section('title', 'Transaction ' . $transaction->receipt_number)

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Transaction: {{ $transaction->receipt_number }}</h1>
        <p class="text-gray-600">{{ $transaction->created_at->format('M d, Y H:i') }}</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('receipts.print', $transaction->receipt_number) }}" target="_blank"
            class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Print Receipt</a>
        <a href="{{ route('transactions.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Back</a>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-3xl">
    <div class="grid grid-cols-2 gap-6 mb-6">
        <div>
            <h3 class="text-sm font-medium text-gray-500 mb-2">Sender</h3>
            <p class="text-gray-900">{{ $transaction->sender_name }}</p>
            <p class="text-gray-600 text-sm">{{ $transaction->sender_contact }}</p>
        </div>
        <div>
            <h3 class="text-sm font-medium text-gray-500 mb-2">Receiver</h3>
            <p class="text-gray-900">{{ $transaction->receiver_name }}</p>
            <p class="text-gray-600 text-sm">{{ $transaction->receiver_contact }}</p>
        </div>
    </div>

    <div class="border-t pt-4 mb-6">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Payment</h3>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">Currency:</span> {{ $transaction->currency }}</div>
            <div><span class="text-gray-500">Amount:</span> {{ $transaction->currency }} {{ number_format($transaction->amount, 2) }}</div>
            <div><span class="text-gray-500">Exchange Rate:</span> {{ $transaction->exchange_rate }}</div>
            <div><span class="text-gray-500">RMB Amount:</span> ¥{{ number_format($transaction->rmb_amount, 2) }}</div>
            <div><span class="text-gray-500">Charge Rate:</span> {{ $transaction->charge_rate }}%</div>
            <div><span class="text-gray-500">Charge Amount:</span> ${{ number_format($transaction->charge_amount, 2) }}</div>
            <div><span class="text-gray-500">USD Equivalent:</span> ${{ number_format($transaction->total_usd_equivalent, 2) }}</div>
            <div>
                <span class="text-gray-500">Status:</span>
                <span class="px-2 py-1 text-xs rounded-full
                    @if($transaction->status === 'completed') bg-green-100 text-green-800
                    @elseif($transaction->status === 'pending') bg-yellow-100 text-yellow-800
                    @else bg-red-100 text-red-800 @endif">
                    {{ ucfirst($transaction->status) }}
                </span>
            </div>
        </div>
    </div>

    @if($transaction->notes)
    <div class="border-t pt-4">
        <h3 class="text-sm font-medium text-gray-500 mb-1">Notes</h3>
        <p class="text-gray-700">{{ $transaction->notes }}</p>
    </div>
    @endif

    @if($transaction->creator)
    <div class="border-t pt-4 mt-4 text-sm text-gray-500">
        Created by: {{ $transaction->creator->name }}
    </div>
    @endif

    @if($transaction->status === 'pending' && auth()->user()->hasRole('admin'))
    <div class="border-t pt-4 mt-4">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Actions</h3>
        <div class="flex gap-3">
            <form method="POST" action="{{ route('transactions.update-status', $transaction) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="completed">
                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700"
                    onclick="return confirm('Approve this transaction?')">Approve</button>
            </form>
            <form method="POST" action="{{ route('transactions.update-status', $transaction) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="cancelled">
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-md text-sm hover:bg-red-700"
                    onclick="return confirm('Reject this transaction?')">Reject</button>
            </form>
        </div>
    </div>
    @endif

    @if($transaction->activityLogs->isNotEmpty())
    <div class="border-t pt-4 mt-4">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Activity Log</h3>
        <div class="space-y-2">
            @foreach($transaction->activityLogs->sortByDesc('created_at') as $log)
            <div class="flex items-start gap-3 text-sm">
                <div class="mt-1 w-2 h-2 rounded-full flex-shrink-0
                    @if($log->action === 'created') bg-blue-500
                    @elseif($log->action === 'approved') bg-green-500
                    @elseif($log->action === 'rejected') bg-red-500
                    @else bg-gray-500 @endif"></div>
                <div>
                    <span class="text-gray-900">{{ $log->description }}</span>
                    <span class="text-gray-500">by {{ $log->user?->name ?? 'System' }}</span>
                    <span class="text-gray-400 text-xs">{{ $log->created_at->diffForHumans() }}</span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endsection
