@extends('layouts.app')

@section('title', 'Currency Exchanges')

@section('content')
@php
    $fmt = fn (\App\Models\CurrencyExchange $e, string $side) => \App\Models\Account::format(
        $side === 'in' ? $e->in_currency : $e->out_currency,
        $side === 'in' ? $e->in_amount : $e->out_amount
    );
    $filtered = request()->filled('search') || request()->filled('currency') || request()->filled('status')
        || request()->filled('date_from') || request()->filled('date_to');
    // A counter price is not money handed over, so it keeps the places it was set with and gives
    // back the trailing zeros: 3,800 rather than 3,800.00, exactly as the rate card prints it.
    $rate = fn ($value) => rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.');
@endphp
<div class="mb-6 flex flex-wrap justify-between items-start gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Currency Exchanges</h1>
        <p class="text-gray-600 text-sm">Deals done across the counter: money taken in and another currency handed back, priced at the saved rates.</p>
    </div>
    <div class="flex gap-2">
        @can('create-exchange')<a href="{{ route('exchanges.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">New Exchange</a>@endcan
        @can('export-exchanges')<a href="{{ route('export.exchanges', request()->query()) }}" class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>@endcan
    </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Deals</p>
        <p class="text-xl font-semibold text-gray-900">{{ number_format($liveCount) }}@if($voidCount > 0) <span class="text-sm font-normal text-gray-500">(+{{ number_format($voidCount) }} cancelled)</span>@endif</p>
    </div>
    @can('view-profit')
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Margin on {{ $filtered ? 'this filter' : 'all deals' }}</p>
        <p class="text-xl font-semibold {{ $marginTotal < 0 ? 'text-red-700' : 'text-gray-900' }}">UGX {{ number_format((float) $marginTotal) }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Unpriced deals</p>
        <p class="text-xl font-semibold text-gray-900">{{ number_format($unpriced) }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Card used</p>
        <p class="text-sm font-medium text-gray-900 mt-1">
            @if($card)
                UGX {{ $rate($card['usd_buy']) }} / {{ $rate($card['usd_sell']) }} per dollar<br>
                {{ $rate($card['rmb_buy']) }} / {{ $rate($card['rmb_sell']) }} yuan per dollar
            @else
                <span class="text-amber-700">Not complete</span>
            @endif
        </p>
    </div>
    @endcan
</div>

@if($unpriced > 0 && auth()->user()->can('view-profit'))
<div class="rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800 mb-6">
    {{ number_format($unpriced) }} {{ $unpriced === 1 ? 'deal is' : 'deals are' }} priced without a complete rate card, so {{ $unpriced === 1 ? 'it adds' : 'they add' }} nothing to the margin above. That figure is short by whatever those deals really earned, and nothing here is guessed to cover it.
</div>
@endif

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[12rem]">
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Person, reference or note"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Currency</label>
            <select name="currency" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Any currency</option>
                @foreach(\App\Services\ExchangeCalculator::CURRENCIES as $currency)
                    <option value="{{ $currency }}" @selected(request('currency') === $currency)>{{ $currency }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Status</label>
            <select name="status" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Active and cancelled</option>
                <option value="active" @selected(request('status') === 'active')>Active only</option>
                <option value="void" @selected(request('status') === 'void')>Cancelled only</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
        <a href="{{ route('exchanges.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Person</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Taken In</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Handed Over</th>
                @can('view-profit')<th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Margin (UGX)</th>@endcan
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Accounts Moved</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entered By</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($exchanges as $exchange)
            <tr class="{{ $exchange->isVoid() ? 'bg-gray-50 text-gray-400' : '' }}">
                <td class="px-4 py-3 text-sm whitespace-nowrap">{{ $exchange->exchange_date->format('M d, Y') }}</td>
                <td class="px-4 py-3 text-sm">
                    <a href="{{ route('exchanges.show', $exchange) }}" class="text-green-800 hover:underline">{{ $exchange->reference }}</a>
                    @if($exchange->isVoid())
                        <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-red-50 text-red-700">Cancelled</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm">
                    @if($exchange->customer)
                        <a href="{{ route('customers.show', $exchange->customer) }}" class="text-green-800 hover:underline">{{ $exchange->person_name }}</a>
                    @else
                        <span class="text-gray-900">{{ $exchange->person_name }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ $fmt($exchange, 'in') }}</td>
                <td class="px-4 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ $fmt($exchange, 'out') }}</td>
                @can('view-profit')
                <td class="px-4 py-3 text-sm text-right whitespace-nowrap">
                    @if($exchange->margin_ugx === null)
                        <span class="text-amber-700">No rate card</span>
                    @else
                        <span class="{{ (float) $exchange->margin_ugx < 0 ? 'text-red-700' : 'text-gray-900' }}">
                            {{ number_format((float) $exchange->margin_ugx) }}
                        </span>
                    @endif
                </td>
                @endcan
                <td class="px-4 py-3 text-sm text-gray-700">
                    {{ $exchange->inAccount?->name ?? '-' }} up, {{ $exchange->outAccount?->name ?? '-' }} down
                </td>
                <td class="px-4 py-3 text-sm text-gray-500">{{ $exchange->creator?->name ?? 'System' }}</td>
                <td class="px-4 py-3 text-sm text-right relative" data-row-menu-host>
                    <button type="button" onclick="toggleMenu('ex{{ $exchange->id }}')"
                        class="text-gray-500 hover:text-gray-800 border border-gray-300 rounded px-2 py-1 text-xs">More</button>
                    <div id="menu-ex{{ $exchange->id }}" data-row-menu class="hidden absolute right-0 mt-1 w-44 bg-white border border-gray-200 rounded-md shadow-lg text-left z-20">
                        <a href="{{ route('exchanges.show', $exchange) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Open deal</a>
                        <a href="{{ route('exchanges.index', ['search' => $exchange->reference]) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Find this reference</a>
                        @if(! $exchange->isVoid() && auth()->user()->can('void-exchange'))
                        <button type="button" onclick="openVoidModal(this)"
                            data-action="{{ route('exchanges.void', $exchange) }}"
                            data-deal="{{ $exchange->reference }} &middot; {{ $exchange->person_name }}"
                            data-first="{{ $fmt($exchange, 'in') }}"
                            data-second="{{ $fmt($exchange, 'out') }}"
                            class="w-full text-left px-4 py-2 text-sm text-red-700 hover:bg-red-50">Cancel deal</button>
                        @endif
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" class="px-4 py-8 text-center text-gray-500">
                    No currency has been exchanged with a customer yet.
                    <a href="{{ route('exchanges.create') }}" class="text-green-700 underline">Record the first deal</a>.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $exchanges->links() }}</div>

@include('components.void-modal', ['void' => ['subject' => 'exchange']])
@endsection
