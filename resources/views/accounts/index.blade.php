@extends('layouts.app')

@section('title', 'System Accounts')

@section('content')
@php
    $fmt = fn ($v, $currency) => $currency === 'UGX' ? number_format((float) $v) : number_format((float) $v, 2);
@endphp
<div class="mb-6 flex flex-wrap justify-between items-center gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">System Accounts</h1>
        <p class="text-gray-600">What each country is holding right now, in its own money</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('accounts.entries.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Add Ledger Entry</a>
        <a href="{{ route('accounts.daily-balances') }}" class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Daily Balances</a>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    @foreach($countries as $code => $country)
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-gray-900">{{ $country['label'] }} total</h2>
            <span class="text-xs text-gray-500">as of {{ \Carbon\Carbon::parse($date)->format('M d, Y') }}</span>
        </div>
        <p class="text-3xl font-bold text-gray-900 mt-2">{{ $country['currency'] }} {{ $fmt($country['total'], $country['currency']) }}</p>
        @if((float) $country['rate'] > 0)
            <p class="text-xs text-gray-500 mt-2">
                Own cash plus {{ $fmt($country['dollars'], 'USD') }} USD at {{ rtrim(rtrim(number_format((float) $country['rate'], 4, '.', ''), '0'), '.') }}
                {{ $country['currency'] }} per dollar, the selling rate in System Settings.
            </p>
        @else
            <p class="text-xs text-amber-700 mt-2">Set the dollar selling rate in System Settings to total the dollars this country is holding.</p>
        @endif
    </div>
    @endforeach
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @foreach($accounts as $account)
    <a href="{{ route('accounts.show', $account) }}"
        class="bg-white rounded-lg shadow p-5 hover:shadow-md transition block {{ ($balances[$account->id] ?? '0') < 0 ? 'ring-1 ring-red-300' : '' }}">
        <div class="flex items-start justify-between gap-2">
            <span class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $account->country === 'UG' ? 'Uganda' : 'China' }}</span>
            <span class="text-xs px-2 py-0.5 rounded-full bg-green-50 text-green-800">{{ $account->currency }}</span>
        </div>
        <p class="text-sm font-medium text-gray-700 mt-2">{{ $account->name }}</p>
        <p class="text-2xl font-bold {{ ($balances[$account->id] ?? '0') < 0 ? 'text-red-700' : 'text-gray-900' }} mt-1">
            {{ $account->currency }} {{ $fmt($balances[$account->id] ?? '0', $account->currency) }}
        </p>
        <p class="text-xs text-gray-500 mt-2">{{ $todayMovements[$account->id] ?? 0 }} movement(s) today</p>
    </a>
    @endforeach
</div>

<div class="bg-white rounded-lg shadow p-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-3">How these accounts are filled</h2>
    <ul class="text-sm text-gray-600 space-y-2 list-disc list-inside">
        <li>A transfer that turns <strong>Completed</strong> puts the money the customer handed over, fee included, into the Uganda account for that currency on its own.</li>
        <li>Cancelling a completed transfer takes the same amount back out and records why.</li>
        <li>Everything else you already track by hand goes in through <strong>Add Ledger Entry</strong>: dollars bought, money sent to China, RMB paid out to recipients, expenses and corrections.</li>
        <li>An exchange writes two lines, one out of each account, so no money can vanish between countries.</li>
    </ul>
</div>
@endsection
