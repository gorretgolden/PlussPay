@extends('layouts.app')

@section('title', 'Money Movements')

@section('content')
@php
    $filtered = request()->filled('search') || request()->filled('account_id') || request()->filled('owner_id') || request()->filled('type')
        || request()->filled('direction') || request()->filled('date_from') || request()->filled('date_to');
    $typeGroups = collect(\App\Models\AccountEntry::TYPES)
        ->groupBy(fn ($meta) => $meta['group'])
        ->map(fn ($group) => $group->map(fn ($meta, $key) => ['key' => $key] + $meta));
@endphp
<div class="mb-6 flex flex-wrap justify-between items-start gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Money Movements</h1>
        <p class="text-gray-600 text-sm">Every entry across company and user accounts.</p>
    </div>
    <div class="flex gap-2">
        @can('create-account-entry')
        <a href="{{ route('accounts.entries.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Add Entry</a>
        @endcan
        <a href="{{ route('export.movements', request()->query()) }}" class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[12rem]">
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Reference, receipt or description"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Person</label>
            <select name="owner_id" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Everyone</option>
                @foreach($people as $person)
                    <option value="{{ $person->id }}" @selected((int) request('owner_id') === $person->id)>{{ $person->name }}{{ $person->is_active ? '' : ' (blocked)' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Account</label>
            <select name="account_id" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All accounts</option>
                <optgroup label="Company accounts">
                    @foreach($accounts as $account)
                        <option value="{{ $account->id }}" @selected((int) request('account_id') === $account->id)>{{ $account->name }}</option>
                    @endforeach
                </optgroup>
                @if($userAccounts->isNotEmpty())
                <optgroup label="User accounts">
                    @foreach($userAccounts as $account)
                        <option value="{{ $account->id }}" @selected((int) request('account_id') === $account->id)>{{ $account->label() }}</option>
                    @endforeach
                </optgroup>
                @endif
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Movement</label>
            <select name="type" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Any movement</option>
                @foreach($typeGroups as $group => $options)
                    <optgroup label="{{ ucfirst($group === 'single' ? 'One-sided (cash in or out)' : $group) }}">
                        @foreach($options as $key => $option)
                            <option value="{{ $option['key'] }}" @selected(request('type') === $option['key'])>{{ $option['label'] }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Direction</label>
            <select name="direction" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Both</option>
                <option value="in" @selected(request('direction') === 'in')>Went up</option>
                <option value="out" @selected(request('direction') === 'out')>Went down</option>
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
        <a href="{{ route('accounts.movements') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Movement</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Goes up</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Goes down</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">The other side</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Details</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entered By</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($entries as $entry)
            <tr>
                <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $entry->entry_date->format('M d, Y') }}</td>
                <td class="px-4 py-3 text-sm">
                    <span class="px-2 py-1 text-xs rounded-full
                        @if($entry->direction === 'in') bg-green-50 text-green-800
                        @else bg-gray-100 text-gray-700 @endif">
                        {{ \App\Models\AccountEntry::labelFor($entry->type) }}
                    </span>
                </td>
                <td class="px-4 py-3 text-sm text-gray-900">
                    <a href="{{ route('accounts.show', $entry->account) }}" class="text-green-800 hover:underline">{{ $entry->account?->label() ?? 'Deleted account' }}</a>
                    <span class="block text-xs text-gray-400">Holds {{ \App\Models\Account::format($entry->account?->currency, $entry->account?->balance()) }} now</span>
                </td>
                <td class="px-4 py-3 text-sm text-left text-gray-900 whitespace-nowrap">{{ $entry->direction === 'in' ? \App\Models\Account::format($entry->account?->currency, $entry->amount) : '' }}</td>
                <td class="px-4 py-3 text-sm text-left text-gray-900 whitespace-nowrap">{{ $entry->direction === 'out' ? \App\Models\Account::format($entry->account?->currency, $entry->amount) : '' }}</td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    @if($entry->counterparty)
                        <a href="{{ route('accounts.show', $entry->counterparty) }}" class="text-green-800 hover:underline">{{ $entry->counterparty->label() }}</a>
                        <span class="block text-xs text-gray-400">{{ $entry->direction === 'in' ? 'paid out of it' : 'received it into it' }}</span>
                    @else
                        <span class="text-gray-500">Outside the business</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    {{ $entry->description }}
                    <span class="block text-xs text-gray-400">
                        @php $sameRef = $entry->transaction && $entry->reference === $entry->transaction->receipt_number; @endphp
                        @if($entry->reference && ! $sameRef)
                            <a href="{{ route('accounts.movements', ['search' => $entry->reference]) }}" class="text-gray-500 underline">{{ $entry->reference }}</a>
                        @endif
                        @if($entry->transaction)
                            @if($entry->reference && ! $sameRef) &middot; @endif
                            <a href="{{ route('transactions.show', $entry->transaction) }}" class="text-green-800 hover:underline">{{ $entry->transaction->receipt_number }}</a>
                        @endif
                    </span>
                </td>
                <td class="px-4 py-3 text-sm text-gray-500">{{ $entry->creator?->name ?? 'System' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                    @if($filtered)
                        No movements match this filter.
                        <a href="{{ route('accounts.movements') }}" class="text-green-700 underline">Clear the filters</a>.
                    @else
                        Nothing has been recorded in the cash books yet.
                        <a href="{{ route('accounts.entries.create') }}" class="text-green-700 underline">Add the first entry</a>.
                    @endif
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<p class="text-xs text-gray-500 mt-3">Paired entries share a reference. Click it to see both sides.</p>

<div class="mt-4">
    {{ $entries->links() }}
</div>
@endsection
