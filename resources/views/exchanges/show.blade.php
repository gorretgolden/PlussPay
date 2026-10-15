@extends('layouts.app')

@section('title', 'Exchange '.$exchange->reference)

@section('content')
@php
    $in = \App\Models\Account::format($exchange->in_currency, $exchange->in_amount);
    $out = \App\Models\Account::format($exchange->out_currency, $exchange->out_amount);
    $margin = $exchange->margin_ugx === null ? null : (float) $exchange->margin_ugx;
    $card = \App\Services\ExchangeCalculator::card();
    $replacement = \App\Services\ExchangeCalculator::given($card, $exchange->out_currency, (string) $exchange->out_amount);
@endphp
<div class="mb-6 flex flex-wrap justify-between items-start gap-3">
    <div>
        <p class="text-sm text-gray-500"><a href="{{ route('exchanges.index') }}" class="underline hover:text-gray-700">Currency Exchanges</a> / {{ $exchange->reference }}</p>
        <h1 class="text-2xl font-bold text-gray-900">
            {{ $exchange->reference }}
            @if($exchange->isVoid())
                <span class="ml-2 align-middle px-2 py-1 text-xs rounded-full bg-red-50 text-red-700">Cancelled</span>
            @endif
        </h1>
        <p class="text-gray-600 text-sm">
            {{ $exchange->exchange_date->format('M d, Y') }} &middot;
            @if($exchange->customer)
                <a href="{{ route('customers.show', $exchange->customer) }}" class="text-green-800 hover:underline">{{ $exchange->person_name }}</a>
            @else
                {{ $exchange->person_name }}
            @endif
        </p>
    </div>
    @if(!$exchange->isVoid() && auth()->user()->can('void-exchange'))
    <button type="button" onclick="openVoidModal(this)"
        data-action="{{ route('exchanges.void', $exchange) }}"
        data-deal="{{ $exchange->reference }} &middot; {{ $exchange->person_name }}"
        data-first="{{ $in }}"
        data-second="{{ $out }}"
        class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Cancel deal</button>
    @endif
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">They handed over</p>
        <p class="text-xl font-semibold text-gray-900">{{ $in }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">We handed back</p>
        <p class="text-xl font-semibold text-gray-900">{{ $out }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Rate done at</p>
        <p class="text-sm font-medium text-gray-900 mt-1">{{ $exchange->quotedRate() ?? 'Not recorded' }}</p>
    </div>
    @can('view-profit')
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Margin on this deal</p>
        <p class="text-xl font-semibold {{ $margin === null ? 'text-amber-700' : ($margin < 0 ? 'text-red-700' : 'text-gray-900') }}">
            @if($margin === null)
                <span class="text-sm">No rate card</span>
            @else
                UGX {{ number_format($margin, 2) }}
            @endif
        </p>
    </div>
    @endcan
</div>

@can('view-profit')
<div class="bg-white rounded-lg shadow p-6 max-w-3xl mb-6">
    <h2 class="text-sm font-semibold text-gray-900 mb-2">How the margin was got</h2>
    <p class="text-sm text-gray-700">
        The money taken in is worth <span class="font-medium">{{ $in }}</span> at the rate we sell that currency at.
        The money handed over would cost <span class="font-medium">{{ $replacement === null ? 'nothing recorded' : \App\Models\Account::format('UGX', $replacement) }}</span>
        to replace at the rate we buy it back at. The margin is one less the other.
    </p>
    @if($rule)
        <p class="text-xs text-gray-500 mt-2">{{ $rule }}</p>
    @endif
    @if($margin === null)
        <p class="text-xs text-amber-700 mt-2">No buying and selling rates were saved for these currencies, so the margin is left blank rather than guessed.</p>
    @endif
</div>
@endcan

<div class="bg-white rounded-lg shadow overflow-x-auto mb-6">
    <div class="px-6 py-4 border-b">
        <h2 class="text-sm font-semibold text-gray-900">Which accounts moved</h2>
        <p class="text-xs text-gray-500 mt-1">The same deal written in the cash books, so the account that went up is named next to the one that came down.</p>
    </div>
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Movement</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Goes up</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Goes down</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Against</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($legs as $leg)
            <tr>
                <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $leg->entry_date->format('M d, Y') }}</td>
                <td class="px-4 py-3 text-sm text-gray-900">
                    <a href="{{ route('accounts.show', $leg->account) }}" class="text-green-800 hover:underline">{{ $leg->account?->name ?? 'Deleted account' }}</a>
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ \App\Models\AccountEntry::labelFor($leg->type) }}</td>
                <td class="px-4 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ $leg->direction === 'in' ? \App\Models\Account::format($leg->account?->currency, $leg->amount) : '' }}</td>
                <td class="px-4 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ $leg->direction === 'out' ? \App\Models\Account::format($leg->account?->currency, $leg->amount) : '' }}</td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ $leg->counterparty?->name ?? 'Outside the business' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-4 py-6 text-center text-gray-500 text-sm">
                    This deal has no cash-book lines against it. It was recorded before the accounts were set up.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-3xl">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
        <div><span class="text-gray-500">Entered by:</span> {{ $exchange->creator?->name ?? 'System' }}</div>
        <div><span class="text-gray-500">Saved on:</span> {{ $exchange->created_at->format('M d, Y h:i A') }}</div>
        @if($exchange->notes)
        <div class="sm:col-span-2"><span class="text-gray-500">Notes:</span> {{ $exchange->notes }}</div>
        @endif
        @if($exchange->customer)
        <div class="sm:col-span-2">
            <a href="{{ route('exchanges.index', ['customer_id' => $exchange->customer_id]) }}" class="text-green-800 hover:underline">
                Everything exchanged for {{ $exchange->person_name }}
            </a>
        </div>
        @endif
    </div>
</div>

@include('components.void-modal', ['void' => ['subject' => 'exchange']])
@endsection
