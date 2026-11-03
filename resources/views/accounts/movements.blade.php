@extends('layouts.app')

@section('title', 'Money Movements')

@section('content')
@php
    $filtered = request()->filled('search') || request()->filled('account_id') || request()->filled('owner_id') || request()->filled('type')
        || request()->filled('direction') || request()->filled('date_from') || request()->filled('date_to');
    $typeGroups = collect(\App\Models\AccountEntry::TYPES)
        ->groupBy(fn ($meta) => $meta['group'])
        ->map(fn ($group) => $group->map(fn ($meta, $key) => ['key' => $key] + $meta));
    $moreOpen = request()->filled('type') || request()->filled('direction');
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
        <a href="{{ route('export.movements', request()->query()) }}" class="border border-green-800 text-green-800 px-4 py-2 rounded-md text-sm hover:bg-green-50">Export Excel</a>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-4">
    <form method="GET" id="movements-filter" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[10rem]">
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Reference or description"
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
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div class="flex gap-1 items-center pb-1">
            <a href="{{ route('accounts.movements', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]) }}" class="text-xs text-green-800 hover:underline">Today</a>
            <span class="text-gray-300">|</span>
            <a href="{{ route('accounts.movements', ['date_from' => now()->startOfWeek()->toDateString(), 'date_to' => now()->endOfWeek()->toDateString()]) }}" class="text-xs text-green-800 hover:underline">This week</a>
            <span class="text-gray-300">|</span>
            <a href="{{ route('accounts.movements', ['date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->endOfMonth()->toDateString()]) }}" class="text-xs text-green-800 hover:underline">This month</a>
        </div>
        <a href="{{ route('accounts.movements') }}" class="text-sm text-gray-600 underline py-2">Clear</a>

        <details class="w-full mt-2" @if($moreOpen) open @endif>
            <summary class="text-sm text-green-800 cursor-pointer">More filters</summary>
            <div class="flex flex-wrap gap-3 mt-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Movement</label>
                    <select name="type" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                        <option value="">Any movement</option>
                        @foreach($typeGroups as $group => $options)
                            <optgroup label="{{ ucfirst($group) }}">
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
            </div>
        </details>
    </form>
</div>

@if($personBalances)
<p class="text-sm text-gray-700 mb-3">
    <span class="font-medium">{{ $personBalances['name'] }}:</span>
    @foreach($personBalances['balances'] as $currency => $bal)
        <span class="ml-1">{{ \App\Models\Account::format($currency, $bal) }}</span>@if(!$loop->last) &middot; @endif
    @endforeach
</p>
@endif

@if($totals->isNotEmpty())
<div class="flex flex-wrap gap-3 mb-4">
    @foreach($totals as $currency => $t)
    <div class="bg-white rounded-lg shadow px-4 py-2 text-sm flex gap-4 items-center">
        <span class="font-medium text-gray-700">{{ $currency }}</span>
        <span class="text-green-700">In {{ \App\Models\Account::format($currency, $t['in']) }}</span>
        <span class="text-rose-700">Out {{ \App\Models\Account::format($currency, $t['out']) }}</span>
        <span class="text-gray-900 font-semibold">Net {{ \App\Models\Account::format($currency, $t['net']) }}</span>
    </div>
    @endforeach
</div>
@endif

{{-- Desktop table --}}
<div class="bg-white rounded-lg shadow overflow-x-auto hidden md:block">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Movement</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Details</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($entries as $entry)
            <tr>
                <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">
                    {{ $entry->entry_date->format('M d, Y') }}
                    <span class="block text-xs text-gray-400">by {{ $entry->creator?->name ?? 'System' }}</span>
                </td>
                <td class="px-4 py-3 text-sm text-gray-900">
                    <a href="{{ route('accounts.show', $entry->account) }}" class="text-green-800 hover:underline">{{ $entry->account?->label() ?? 'Deleted account' }}</a>
                    @if($entry->counterparty)
                        <span class="block text-xs text-gray-400">&rarr; {{ $entry->counterparty->label() }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm whitespace-nowrap">
                    <span class="px-2 py-1 text-xs rounded-full whitespace-nowrap
                        @if($entry->direction === 'in') bg-green-50 text-green-800
                        @else bg-rose-50 text-rose-700 @endif">
                        {{ \App\Models\AccountEntry::labelFor($entry->type) }}
                    </span>
                </td>
                <td class="px-4 py-3 text-sm text-gray-700 max-w-[18rem]">
                    <span class="block truncate" title="{{ $entry->description }}">{{ $entry->description }}</span>
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
                <td class="px-4 py-3 text-sm text-right whitespace-nowrap font-medium
                    @if($entry->direction === 'in') text-green-700 @else text-rose-700 @endif">
                    {{ $entry->direction === 'in' ? '+' : '&minus;' }}{{ \App\Models\Account::format($entry->account?->currency, $entry->amount) }}
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
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

{{-- Mobile cards --}}
<div class="space-y-3 md:hidden">
    @forelse($entries as $entry)
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex justify-between items-start gap-2">
            <div class="text-sm text-gray-900">
                {{ $entry->entry_date->format('M d, Y') }}
                <span class="block text-xs text-gray-400">by {{ $entry->creator?->name ?? 'System' }}</span>
            </div>
            <span class="text-sm font-medium whitespace-nowrap
                @if($entry->direction === 'in') text-green-700 @else text-rose-700 @endif">
                {{ $entry->direction === 'in' ? '+' : '&minus;' }}{{ \App\Models\Account::format($entry->account?->currency, $entry->amount) }}
            </span>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <a href="{{ route('accounts.show', $entry->account) }}" class="text-sm text-green-800 hover:underline">{{ $entry->account?->label() ?? 'Deleted account' }}</a>
            @if($entry->counterparty)
                <span class="text-xs text-gray-400">&rarr; {{ $entry->counterparty->label() }}</span>
            @endif
            <span class="px-2 py-0.5 text-xs rounded-full whitespace-nowrap
                @if($entry->direction === 'in') bg-green-50 text-green-800
                @else bg-rose-50 text-rose-700 @endif">
                {{ \App\Models\AccountEntry::labelFor($entry->type) }}
            </span>
        </div>
        <p class="mt-1 text-xs text-gray-600 truncate" title="{{ $entry->description }}">{{ $entry->description }}</p>
        @if($entry->transaction)
            <a href="{{ route('transactions.show', $entry->transaction) }}" class="text-xs text-green-800 hover:underline">{{ $entry->transaction->receipt_number }}</a>
        @endif
    </div>
    @empty
    <div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">
        @if($filtered)
            No movements match this filter.
            <a href="{{ route('accounts.movements') }}" class="text-green-700 underline">Clear the filters</a>.
        @else
            Nothing has been recorded yet.
        @endif
    </div>
    @endforelse
</div>

<div class="mt-4">
    {{ $entries->links() }}
</div>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('movements-filter');
    if (!form) return;
    form.querySelectorAll('select').forEach(sel => {
        sel.addEventListener('change', () => form.submit());
    });
    form.querySelectorAll('input[type="date"]').forEach(inp => {
        inp.addEventListener('change', () => form.submit());
    });
})();
</script>
@endpush
