@extends('layouts.app')

@section('title', $account->name.' Ledger')

@section('content')
@php
    $fmt = fn ($v) => $account->currency === 'UGX' ? number_format((float) $v) : number_format((float) $v, 2);
    $filtered = request()->filled('type') || request()->filled('direction') || request()->filled('search');
@endphp
<div class="mb-6 flex flex-wrap justify-between items-start gap-3">
    <div>
        <p class="text-sm text-gray-500"><a href="{{ route('accounts.index') }}" class="underline hover:text-gray-700">System Accounts</a> / {{ $account->country === 'UG' ? 'Uganda' : 'China' }}</p>
        <h1 class="text-2xl font-bold text-gray-900">{{ $account->name }}</h1>
        <p class="text-gray-600 text-sm">{{ $account->description }}</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('accounts.entries.create', ['account_id' => $account->id]) }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Add Entry</a>
        <a href="{{ route('export.account-ledger', array_merge(request()->query(), ['account_id' => $account->id])) }}"
            class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
    </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Opening</p>
        <p class="text-xl font-semibold text-gray-900">{{ $account->currency }} {{ $fmt($opening) }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Money In</p>
        <p class="text-xl font-semibold text-gray-900">{{ $account->currency }} {{ $fmt($totals['in']) }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Money Out</p>
        <p class="text-xl font-semibold text-gray-900">{{ $account->currency }} {{ $fmt($totals['out']) }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Closing Balance</p>
        <p class="text-xl font-semibold {{ (float) $closing < 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $account->currency }} {{ $fmt($closing) }}</p>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[12rem]">
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Receipt number, reference or description"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Movement</label>
            <select name="type" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Movements</option>
                @foreach(\App\Models\AccountEntry::TYPES as $key => $meta)
                    <option value="{{ $key }}" @selected(request('type') === $key)>{{ $meta['label'] }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Direction</label>
            <select name="direction" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Both</option>
                <option value="in" @selected(request('direction') === 'in')>Money In</option>
                <option value="out" @selected(request('direction') === 'out')>Money Out</option>
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
        <a href="{{ route('accounts.show', $account) }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Movement</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">In</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Out</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">{{ $filtered ? 'Total (filtered)' : 'Balance' }}</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Other Account</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entered By</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($entries as $row)
            @php $entry = $row['entry']; @endphp
            <tr>
                <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $entry->entry_date->format('M d, Y') }}</td>
                <td class="px-4 py-3 text-sm">
                    <span class="px-2 py-1 text-xs rounded-full
                        @if($entry->type === 'reversal') bg-red-50 text-red-700
                        @elseif($entry->direction === 'in') bg-green-50 text-green-800
                        @else bg-gray-100 text-gray-700 @endif">
                        {{ \App\Models\AccountEntry::labelFor($entry->type) }}
                    </span>
                </td>
                <td class="px-4 py-3 text-sm text-right text-gray-900">{{ $entry->direction === 'in' ? $fmt($entry->amount) : '' }}</td>
                <td class="px-4 py-3 text-sm text-right text-gray-900">{{ $entry->direction === 'out' ? $fmt($entry->amount) : '' }}</td>
                <td class="px-4 py-3 text-sm text-right font-medium {{ (float) $row['balance'] < 0 ? 'text-red-700' : 'text-gray-900' }}">{{ $fmt($row['balance']) }}</td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ $entry->counterparty?->name ?? '-' }}</td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    {{ $entry->description }}
                    @if($entry->rate)
                        <span class="block text-xs text-gray-400">at {{ rtrim(rtrim(number_format((float) $entry->rate, 6, '.', ''), '0'), '.') }} {{ $account->currency === 'UGX' || $account->currency === 'RMB' ? 'per 1 USD' : 'per 1 unit' }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-gray-500">{{ $entry->creator?->name ?? 'System' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                    Nothing has been recorded on this account yet.
                    <a href="{{ route('accounts.entries.create', ['account_id' => $account->id]) }}" class="text-green-700 underline">Add the first entry</a>.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $entries->links() }}
</div>
@endsection
