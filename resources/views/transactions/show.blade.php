@extends('layouts.app')

@section('title', 'Transaction ' . $transaction->receipt_number)

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Transaction: {{ $transaction->receipt_number }}</h1>
        <p class="text-gray-600">{{ $transaction->created_at->format('M d, Y h:i A') }}</p>
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
            <div><span class="text-gray-500">Exchange Rate:</span> {{ rtrim(rtrim(number_format((float) $transaction->exchange_rate, 6, '.', ''), '0'), '.') }}</div>
            <div><span class="text-gray-500">RMB Amount:</span> ¥{{ number_format($transaction->rmb_amount, 2) }}</div>
            <div><span class="text-gray-500">Service Fee:</span> UGX {{ number_format((float) $transaction->charge_ugx) }}</div>
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

    @role('admin|superadmin|accountant')
    @php
        $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        $marginPerUsd = ($transaction->rate_sell_ugx && $transaction->rate_buy_ugx)
            ? (float) $transaction->rate_sell_ugx - (float) $transaction->rate_buy_ugx
            : null;
        $totalProfitUgx = (float) $transaction->charge_ugx + (float) $transaction->fx_profit_ugx;
        $totalProfitUsd = (float) $transaction->charge_amount + (float) $transaction->fx_profit_usd;
        $totalProfitRmb = $totalProfitUsd * $transaction->yuanRatePerUsd();
    @endphp
    <div class="border-t pt-4 mb-6">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Profit (admin only, never shown on a receipt)</h3>
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div><span class="text-gray-500">Selling rate used:</span> UGX {{ $trim($transaction->rate_sell_ugx) }} per 1 USD</div>
            <div><span class="text-gray-500">Buying rate:</span> UGX {{ $trim($transaction->rate_buy_ugx) }} per 1 USD</div>
            <div><span class="text-gray-500">Profit on each dollar:</span> UGX {{ $marginPerUsd === null ? 'not set' : number_format($marginPerUsd, 2) }}</div>
            <div><span class="text-gray-500">Dollars moved:</span> ${{ number_format($transaction->total_usd_equivalent, 2) }}</div>
            <div><span class="text-gray-500">FX profit:</span> UGX {{ number_format((float) $transaction->fx_profit_ugx) }}</div>
            <div><span class="text-gray-500">Service fee:</span> UGX {{ number_format((float) $transaction->charge_ugx) }}</div>
            <div class="col-span-2 pt-2 border-t text-base font-semibold text-gray-900">
                Total profit: UGX {{ number_format($totalProfitUgx) }}
                <span class="text-sm font-normal text-gray-500">= ${{ number_format($totalProfitUsd, 2) }} (fee ${{ number_format((float) $transaction->charge_amount, 2) }} + FX ${{ number_format((float) $transaction->fx_profit_usd, 2) }})</span>
            </div>
            <div class="col-span-2 text-sm font-semibold text-gray-900">
                Total profit in yuan: &yen;{{ number_format($totalProfitUsd * $transaction->yuanRatePerUsd(), 2) }}
                <span class="text-sm font-normal text-gray-500">at the {{ number_format($transaction->yuanRatePerUsd(), 4) }} yuan this transfer bought per dollar</span>
            </div>
        </div>
        @if($marginPerUsd === null)
            <p class="text-xs text-amber-700 mt-2">No buying rate was recorded on this transfer, so only the service fee counts as profit here. Set both rates in System Settings for new transfers.</p>
        @endif
    </div>
    @endrole

    @if($transaction->notes)
    <div class="border-t pt-4">
        <h3 class="text-sm font-medium text-gray-500 mb-1">Notes</h3>
        <p class="text-gray-700">{{ $transaction->notes }}</p>
    </div>
    @endif

    <div class="border-t pt-4 mt-4 text-sm text-gray-500 space-y-1">
        @if($transaction->creator)
        <p>Created by <span class="font-medium text-gray-900">{{ $transaction->creator->name }}</span>@if($transaction->creator->countryLabel()) <span class="text-gray-500">({{ $transaction->creator->countryLabel() }})</span>@endif on {{ $transaction->created_at->format('M d, Y h:i A') }}</p>
        @endif
        <p>Last updated on {{ $transaction->updated_at->format('M d, Y h:i A') }}@if($transaction->editor) by <span class="font-medium text-gray-900">{{ $transaction->editor->name }}</span>@endif</p>
    </div>

    @role('admin|superadmin')
    @if($transaction->status !== 'cancelled')
    <div class="border-t pt-4 mt-4">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Actions</h3>
        <div class="flex gap-3">
            @if($transaction->status === 'pending')
            <form method="POST" action="{{ route('transactions.update-status', $transaction) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="completed">
                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Approve</button>
            </form>
            @endif
            <button type="button" onclick="openCancelModal(this)"
                data-action="{{ route('transactions.update-status', $transaction) }}"
                data-receipt="{{ $transaction->receipt_number }} · {{ $transaction->sender_name }} to {{ $transaction->receiver_name }}"
                data-amount="{{ $transaction->principalLabel() }}"
                data-fee="{{ $transaction->feeLabel() }}"
                data-total="{{ $transaction->collectedAmount() }}"
                class="bg-red-600 text-white px-4 py-2 rounded-md text-sm hover:bg-red-700">Cancel transfer</button>
        </div>
    </div>
    @endif
    @endrole

    @include('transactions.partials.cancel-modal')

    @if($transaction->activityLogs->isNotEmpty())
    <div class="border-t pt-4 mt-4">
        <h3 class="text-sm font-medium text-gray-500 mb-3">Activity Log</h3>
        <div class="space-y-2">
            @foreach($transaction->activityLogs->sortByDesc('created_at') as $log)
            <div class="flex items-start gap-3 text-sm">
                <div class="mt-1 w-2 h-2 rounded-full flex-shrink-0
                    @if($log->action === 'created') bg-blue-500
                    @elseif($log->action === 'approved') bg-green-500
                    @elseif($log->action === 'cancelled') bg-red-500
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
