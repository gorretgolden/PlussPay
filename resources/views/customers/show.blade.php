@extends('layouts.app')

@section('title', $customer->name)

@section('content')
@php
    $live = $exchanges->where('status', \App\Models\CurrencyExchange::ACTIVE);

    // A deal has two sides in two different currencies, so the only figure that adds up across
    // rows is the margin, which is always stated in shillings. Both sides are listed per currency
    // instead of being merged into a total that would mean nothing.
    $handedOver = $live->groupBy('in_currency')->map(fn ($g) => $g->sum('in_amount'));
    $received = $live->groupBy('out_currency')->map(fn ($g) => $g->sum('out_amount'));
    $marginTotal = $live->pluck('margin_ugx')->filter(fn ($v) => $v !== null)->sum();
    $unpriced = $live->whereNull('margin_ugx')->count();

    $sent = $transfers->filter(fn ($t) => mb_strtolower($t->sender_name) === mb_strtolower($customer->name)
        || ($customer->phone && $t->sender_contact === $customer->phone));
    $recv = $transfers->reject(fn ($t) => $sent->contains(fn ($s) => $s->id === $t->id));
@endphp
<div class="mb-6 flex flex-wrap justify-between items-start gap-3">
    <div>
        <p class="text-sm text-gray-500"><a href="{{ route('customers.index') }}" class="underline hover:text-gray-700">Customers</a> / {{ $customer->name }}</p>
        <h1 class="text-2xl font-bold text-gray-900">
            {{ $customer->name }}
            <span class="ml-2 align-middle px-2 py-1 text-xs rounded-full
                @if($customer->type === 'sender') bg-blue-100 text-blue-800
                @elseif($customer->type === 'receiver') bg-purple-100 text-purple-800
                @else bg-green-100 text-green-800 @endif">
                {{ ucfirst($customer->type) }}
            </span>
            @unless($customer->is_active)
                <span class="align-middle px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-700">Inactive</span>
            @endunless
        </h1>
        <p class="text-gray-600 text-sm">
            {{ $customer->phone }}@if($customer->country) &middot; {{ $customer->country }}@endif
            @if($customer->creator) &middot; added by {{ $customer->creator->name }} on {{ $customer->created_at->format('M d, Y') }}@endif
        </p>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="{{ route('exchanges.create', ['customer_id' => $customer->id]) }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Change Money</a>
        <a href="{{ route('transactions.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Send Money</a>
        <a href="{{ route('customers.edit', $customer) }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Edit</a>
    </div>
</div>

@if($customer->notes)
<div class="bg-white rounded-lg shadow p-4 mb-6 text-sm text-gray-700">{{ $customer->notes }}</div>
@endif

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Transfers</p>
        <p class="text-xl font-semibold text-gray-900">{{ number_format($transfers->count()) }}</p>
        <p class="text-xs text-gray-500 mt-1">{{ number_format($sent->count()) }} sent, {{ number_format($recv->count()) }} received</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Times they changed money</p>
        <p class="text-xl font-semibold text-gray-900">{{ number_format($live->count()) }}</p>
        @if($exchanges->count() > $live->count())
            <p class="text-xs text-gray-500 mt-1">{{ number_format($exchanges->count() - $live->count()) }} cancelled</p>
        @endif
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">They handed over</p>
        @forelse($handedOver as $currency => $total)
            <p class="text-sm font-medium text-gray-900">{{ \App\Models\Account::format($currency, $total) }}</p>
        @empty
            <p class="text-sm text-gray-400">Nothing yet</p>
        @endforelse
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">We handed over</p>
        @forelse($received as $currency => $total)
            <p class="text-sm font-medium text-gray-900">{{ \App\Models\Account::format($currency, $total) }}</p>
        @empty
            <p class="text-sm text-gray-400">Nothing yet</p>
        @endforelse
    </div>
</div>

@if($live->count() > 0)
<div class="bg-white rounded-lg shadow p-4 mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="text-sm text-gray-500">Margin on the money changed for {{ $customer->name }}</p>
        <p class="text-xl font-semibold text-gray-900">UGX {{ number_format((float) $marginTotal, 2) }}</p>
    </div>
    @if($unpriced > 0)
        <p class="text-xs text-amber-700 max-w-md">{{ Str::plural('Deal', $unpriced) }} left unpriced because no buying and selling rates were saved at the time, so {{ $unpriced === 1 ? 'it is' : 'they are' }} not counted in the figure above.</p>
    @endif
</div>
@endif

<div class="bg-white rounded-lg shadow overflow-x-auto mb-6">
    <div class="px-6 py-4 border-b flex justify-between items-center">
        <div>
            <h2 class="text-sm font-semibold text-gray-900">Money changed for this customer</h2>
            <p class="text-xs text-gray-500 mt-1">Every deal booked against their name, newest first.</p>
        </div>
        @role('admin|superadmin|accountant|agent')
        <a href="{{ route('exchanges.index', ['customer_id' => $customer->id]) }}" class="text-green-800 text-sm hover:underline">Open the exchange book</a>
        @endrole
    </div>
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">They handed over</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">We handed over</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Margin (UGX)</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Accounts Moved</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entered By</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($exchanges as $exchange)
            <tr class="{{ $exchange->isVoid() ? 'bg-gray-50 text-gray-400' : '' }}">
                <td class="px-4 py-3 text-sm whitespace-nowrap">{{ $exchange->exchange_date->format('M d, Y') }}</td>
                <td class="px-4 py-3 text-sm whitespace-nowrap">
                    <a href="{{ route('exchanges.show', $exchange) }}" class="text-green-800 hover:underline">{{ $exchange->reference }}</a>
                    @if($exchange->isVoid())
                        <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-red-50 text-red-700">Cancelled</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ \App\Models\Account::format($exchange->in_currency, $exchange->in_amount) }}</td>
                <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ \App\Models\Account::format($exchange->out_currency, $exchange->out_amount) }}</td>
                <td class="px-4 py-3 text-sm text-right whitespace-nowrap {{ $exchange->margin_ugx === null ? 'text-amber-700' : ((float) $exchange->margin_ugx < 0 ? 'text-red-700' : 'text-gray-900') }}">
                    {{ $exchange->margin_ugx === null ? 'no rate card' : number_format((float) $exchange->margin_ugx, 2) }}
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ $exchange->inAccount?->name ?? '-' }}, {{ $exchange->outAccount?->name ?? '-' }}</td>
                <td class="px-4 py-3 text-sm text-gray-500">{{ $exchange->creator?->name ?? 'System' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                    No money has been changed for {{ $customer->name }} yet.
                    <a href="{{ route('exchanges.create', ['customer_id' => $customer->id]) }}" class="text-green-700 underline">Record the first deal</a>.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <div class="px-6 py-4 border-b">
        <h2 class="text-sm font-semibold text-gray-900">Transfers in their name</h2>
        <p class="text-xs text-gray-500 mt-1">Matched on the name and number they were booked with, so up to the last 100 rows.</p>
    </div>
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Their side</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Other party</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Charge (UGX)</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($transfers as $transfer)
            @php $isSender = $sent->contains(fn ($s) => $s->id === $transfer->id); @endphp
            <tr>
                <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $transfer->created_at->format('M d, Y') }}</td>
                <td class="px-4 py-3 text-sm whitespace-nowrap">
                    <a href="{{ route('transactions.show', $transfer) }}" class="text-green-800 hover:underline">{{ $transfer->receipt_number }}</a>
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ $isSender ? 'Sending' : 'Receiving' }}</td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ $isSender ? $transfer->receiver_name : $transfer->sender_name }}</td>
                <td class="px-4 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ $transfer->principalLabel() }}</td>
                <td class="px-4 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ number_format((float) $transfer->charge_ugx) }}</td>
                <td class="px-4 py-3 text-sm">
                    <span class="px-2 py-1 text-xs rounded-full
                        @if($transfer->status === 'completed') bg-green-100 text-green-800
                        @elseif($transfer->status === 'pending') bg-yellow-100 text-yellow-800
                        @else bg-red-100 text-red-800 @endif">
                        {{ ucfirst($transfer->status) }}
                    </span>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                    Nothing has been sent or received in this name yet.
                    <a href="{{ route('transactions.create') }}" class="text-green-700 underline">Book a transfer</a>.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
