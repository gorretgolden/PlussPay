@extends('layouts.app')

@section('title', 'Daily Balances')

@section('content')
@php
    $fmt = fn ($v, $currency) => $currency === 'UGX' ? number_format((float) $v) : number_format((float) $v, 2);
@endphp
<div class="mb-6 flex flex-wrap justify-between items-center gap-3">
    <div>
        <p class="text-sm text-gray-500"><a href="{{ route('accounts.index') }}" class="underline hover:text-gray-700">Company Accounts</a> / Daily Balances</p>
        <h1 class="text-2xl font-bold text-gray-900">End of Day Balances</h1>
        <p class="text-gray-600">Closing balance per day</p>
    </div>
    <a href="{{ route('export.daily-balances', request()->query()) }}" class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="{{ $from }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="{{ $to }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Show</button>
        <a href="{{ route('accounts.daily-balances', ['date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->toDateString()]) }}"
            class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">This Month</a>
        <a href="{{ route('accounts.daily-balances', ['date_from' => now()->subDays(6)->toDateString(), 'date_to' => now()->toDateString()]) }}"
            class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Last 7 Days</a>
    </form>
</div>

@if($dates === [])
<div class="bg-white rounded-lg shadow p-6 text-sm text-gray-600">The end date is before the start date. Swap them and try again.</div>
@else
<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th rowspan="2" class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase align-bottom border-r border-gray-200">Date</th>
                @foreach($accounts as $account)
                <th colspan="3" class="px-3 py-2 text-center text-xs font-medium text-gray-600 uppercase border-r border-gray-200 {{ $loop->last ? '' : '' }}">
                    {{ $account->name }} <span class="text-gray-400">({{ $account->currency }})</span>
                </th>
                @endforeach
                <th colspan="2" class="px-3 py-2 text-center text-xs font-medium text-gray-600 uppercase">Country Totals</th>
            </tr>
            <tr>
                @foreach($accounts as $account)
                    <th class="px-2 py-1 text-right text-[11px] font-medium text-gray-400">In</th>
                    <th class="px-2 py-1 text-right text-[11px] font-medium text-gray-400">Out</th>
                    <th class="px-2 py-1 text-right text-[11px] font-medium text-gray-500 border-r border-gray-200">Closing</th>
                @endforeach
                <th class="px-2 py-1 text-right text-[11px] font-medium text-gray-500">Uganda (UGX)</th>
                <th class="px-2 py-1 text-right text-[11px] font-medium text-gray-500">China (RMB)</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-100">
            @foreach(array_reverse($dates) as $day)
            @php $dayRow = $rows[$day] ?? []; @endphp
            <tr class="{{ $day === $to ? 'bg-green-50' : '' }}">
                <td class="px-4 py-2 text-sm text-gray-900 whitespace-nowrap border-r border-gray-100">{{ \Carbon\Carbon::parse($day)->format('M d, Y') }}</td>
                @foreach($accounts as $account)
                @php $values = $dayRow[$account->id] ?? ['in' => '0.00', 'out' => '0.00', 'closing' => '0.00']; @endphp
                    <td class="px-2 py-2 text-right text-xs text-gray-500">{{ (float) $values['in'] > 0 ? $fmt($values['in'], $account->currency) : '-' }}</td>
                    <td class="px-2 py-2 text-right text-xs text-gray-500">{{ (float) $values['out'] > 0 ? $fmt($values['out'], $account->currency) : '-' }}</td>
                    <td class="px-2 py-2 text-right text-sm font-medium border-r border-gray-100 {{ (float) $values['closing'] < 0 ? 'text-red-700' : 'text-gray-900' }}">
                        {{ $fmt($values['closing'], $account->currency) }}
                    </td>
                @endforeach
                @php $totals = $countries[$day] ?? null; @endphp
                <td class="px-2 py-2 text-right text-sm {{ $totals && (float) $totals['UG']['total'] < 0 ? 'text-red-700' : 'text-gray-900' }}">
                    {{ $totals ? $fmt($totals['UG']['total'], 'UGX') : '-' }}
                </td>
                <td class="px-2 py-2 text-right text-sm {{ $totals && (float) $totals['CN']['total'] < 0 ? 'text-red-700' : 'text-gray-900' }}">
                    {{ $totals ? $fmt($totals['CN']['total'], 'RMB') : '-' }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="mt-4 bg-white rounded-lg shadow p-5 text-sm text-gray-600 space-y-2">
    <p class="font-medium text-gray-900">How to read this</p>
    <p>Closing = end-of-day balance. Country totals price USD at the selling rates in System Settings.</p>
</div>
@endif
@endsection
