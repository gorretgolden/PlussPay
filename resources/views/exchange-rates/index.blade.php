@extends('layouts.app')

@section('title', 'Exchange Rates')

@section('content')
@php
    $editing = (int) old('rate_id');
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ','), '0'), '.');
    $input = fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
@endphp

<div class="mb-6 flex flex-wrap justify-between items-center gap-3">
    <div class="flex items-center gap-2">
        <h1 class="text-2xl font-bold text-gray-900">Exchange Rates</h1>
        <span class="group relative cursor-help text-gray-400 hover:text-gray-600" title="Rates are never deleted. Changing a rate does not affect past transfers.">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"/><path stroke-linecap="round" stroke-width="2" d="M12 16v-1m0-3a2 2 0 10-2-2"/></svg>
        </span>
    </div>
    <p class="text-sm text-gray-500 hidden sm:block">Rate history. Set new rates in System Settings.</p>
    <div class="flex gap-2 ml-auto">
        <a href="{{ route('settings.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300 whitespace-nowrap">Update rates</a>
        <a href="{{ route('export.exchange-rates', request()->query()) }}"
            class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600 whitespace-nowrap">Export Excel</a>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('exchange-rates.index') }}" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[160px]">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search currency or name"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <select name="currency" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All currencies</option>
                <option value="USD" {{ request('currency') === 'USD' ? 'selected' : '' }}>USD</option>
                <option value="UGX" {{ request('currency') === 'UGX' ? 'selected' : '' }}>UGX</option>
                <option value="RMB" {{ request('currency') === 'RMB' ? 'selected' : '' }}>RMB</option>
            </select>
        </div>
        <div>
            <select name="status" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="active" {{ (request('status') ?? 'active') === 'active' ? 'selected' : '' }}>In use</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Earlier</option>
                <option value="" {{ request()->has('status') && request('status') === '' ? 'selected' : '' }}>All</option>
            </select>
        </div>
        <div>
            <select name="created_by" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Added by anyone</option>
                @foreach($creators as $creator)
                <option value="{{ $creator->id }}" {{ request('created_by') == $creator->id ? 'selected' : '' }}>{{ $creator->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <div class="flex items-center gap-1">
                <input type="date" name="date_from" value="{{ request('date_from') }}" placeholder="From"
                    class="px-2 py-2 border border-gray-300 rounded-md text-sm w-[8.5rem]">
                <span class="text-gray-400 text-xs">to</span>
                <input type="date" name="date_to" value="{{ request('date_to') }}" placeholder="To"
                    class="px-2 py-2 border border-gray-300 rounded-md text-sm w-[8.5rem]">
            </div>
            <div class="flex gap-1">
                @php
                    $quickBase = array_filter(request()->only(['search', 'currency', 'status', 'created_by']), fn ($v) => $v !== null && $v !== '');
                    $ranges = [
                        'Today' => [today()->toDateString(), today()->toDateString()],
                        'Yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
                        'This month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
                        'Last month' => [now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()],
                    ];
                @endphp
                @foreach($ranges as $label => [$from, $to])
                <a href="{{ route('exchange-rates.index', array_merge($quickBase, ['date_from' => $from, 'date_to' => $to])) }}"
                    class="px-2 py-0.5 text-xs rounded-md whitespace-nowrap {{ request('date_from') === $from && request('date_to') === $to ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
        <a href="{{ route('exchange-rates.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
</div>

@if(!empty($spreadWarnings))
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
    <div class="flex items-start gap-2">
        <svg class="w-5 h-5 text-red-600 mt-0.5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        <div>
            <h2 class="text-sm font-semibold text-red-800">Spread warning</h2>
            <ul class="mt-1 space-y-1">
                @foreach($spreadWarnings as $warning)
                <li class="text-sm text-red-700">{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
@endif

@if($sendExample)
<details class="bg-white rounded-lg shadow mb-6 group">
    <summary class="px-4 py-3 cursor-pointer select-none flex items-center gap-2 text-sm font-medium text-gray-700 hover:text-gray-900">
        <svg class="w-4 h-4 text-gray-400 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        Sample: send USD 1,000
        <span class="text-xs text-gray-400 font-normal ml-1">{{ $sendExample['hands_over'] }} handed over</span>
    </summary>
    <div class="px-4 pb-4 pt-1">
        <div class="flex flex-wrap gap-4 text-sm">
            <div class="bg-gray-50 rounded px-3 py-2">
                <span class="text-xs text-gray-500 block">Charge</span>
                <span class="font-medium text-gray-900">{{ $sendExample['charge'] }}</span>
            </div>
            <div class="bg-gray-50 rounded px-3 py-2">
                <span class="text-xs text-gray-500 block">FX profit</span>
                <span class="font-medium text-gray-900">{{ $sendExample['exchange'] }}</span>
            </div>
            <div class="bg-gray-50 rounded px-3 py-2">
                <span class="text-xs text-gray-500 block">Overall profit</span>
                <span class="font-medium text-gray-900">{{ $sendExample['total'] }}</span>
            </div>
        </div>
    </div>
</details>
@endif

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pair</th>
                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Selling</th>
                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Buying</th>
                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Margin</th>
                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Added</th>
                <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Updated</th>
                <th class="px-5 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($rates as $rate)
            <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-5 py-4 text-sm font-medium text-gray-900 whitespace-nowrap">{{ $rate->from_currency }} &rarr; {{ $rate->to_currency }}</td>
                <td class="px-5 py-4 text-sm text-gray-900 font-medium">{{ $trim($rate->sell_rate ?? $rate->rate) }}</td>
                <td class="px-5 py-4 text-sm text-gray-900">{{ $rate->buy_rate === null ? '-' : $trim($rate->buy_rate) }}</td>
                <td class="px-5 py-4 text-sm {{ ($rate->buy_rate === null) ? 'text-gray-400' : (((float) ($rate->sell_rate ?? $rate->rate) - (float) $rate->buy_rate) >= 0 ? 'text-green-700' : 'text-red-600') }}">
                    {{ $rate->buy_rate === null ? '-' : $trim((float) ($rate->sell_rate ?? $rate->rate) - (float) $rate->buy_rate) }}
                </td>
                <td class="px-5 py-4">
                    @if($rate->is_active)
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800">In use</span>
                    @else
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full bg-gray-100 text-gray-600">Earlier</span>
                    @endif
                </td>
                <td class="px-5 py-4 text-sm whitespace-nowrap">
                    <div class="text-gray-900">{{ $rate->creator?->name ?? '-' }}</div>
                    <div class="text-xs text-gray-400">{{ $rate->created_at->format('M d, Y') }}</div>
                </td>
                <td class="px-5 py-4 text-sm whitespace-nowrap">
                    <div class="text-gray-900">{{ $rate->editor?->name ?? '-' }}</div>
                    <div class="text-xs text-gray-400">{{ $rate->updated_at->format('M d, Y') }}</div>
                </td>
                <td class="px-5 py-4 text-sm text-right whitespace-nowrap space-x-3">
                    <button type="button" onclick="toggleRateEdit({{ $rate->id }})"
                        class="text-green-700 hover:text-green-900 font-medium">Edit</button>
                    @if(! $rate->is_active)
                    <form method="POST" action="{{ route('exchange-rates.toggle', $rate) }}" class="inline">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="text-gray-500 hover:text-gray-800 font-medium">Set as current</button>
                    </form>
                    @endif
                </td>
            </tr>
            <tr id="rate-edit-{{ $rate->id }}" class="{{ $editing === $rate->id ? '' : 'hidden' }} bg-gray-50">
                <td colspan="8" class="px-5 py-4">
                    <form method="POST" action="{{ route('exchange-rates.update', $rate) }}" class="flex flex-wrap gap-4 items-start">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="rate_id" value="{{ $rate->id }}">
                        <div class="w-40">
                            <p class="text-sm font-medium text-gray-900">{{ $rate->from_currency }} &rarr; {{ $rate->to_currency }}</p>
                            <p class="text-xs text-gray-500">{{ $rate->is_active ? 'Currently in use.' : 'Added '.$rate->created_at->format('M d, Y').'.' }}</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1" for="sell_rate-{{ $rate->id }}">Selling *</label>
                            <input type="number" id="sell_rate-{{ $rate->id }}" name="sell_rate" step="0.000001" min="0.000001"
                                value="{{ $editing === $rate->id ? old('sell_rate') : $input($rate->sell_rate ?? $rate->rate) }}"
                                class="w-40 px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            @error('sell_rate')
                            @if($editing === $rate->id)
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @endif
                            @enderror
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1" for="buy_rate-{{ $rate->id }}">Buying</label>
                            <input type="number" id="buy_rate-{{ $rate->id }}" name="buy_rate" step="0.000001" min="0.000001"
                                value="{{ $editing === $rate->id ? old('buy_rate', $rate->buy_rate === null ? '' : $input($rate->buy_rate)) : ($rate->buy_rate === null ? '' : $input($rate->buy_rate)) }}"
                                class="w-40 px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            @error('buy_rate')
                            @if($editing === $rate->id)
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @endif
                            @enderror
                        </div>
                        @if(! $rate->is_active)
                        <div class="w-64">
                            <label class="flex items-start gap-2 text-xs text-gray-600">
                                <input type="checkbox" name="history_ok" value="1" class="mt-0.5 rounded border-gray-300">
                                <span>I understand this is an earlier price, so correcting it changes how past transfers read back.</span>
                            </label>
                            @error('history_ok')
                            @if($editing === $rate->id)
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @endif
                            @enderror
                        </div>
                        @endif
                        <div class="flex items-center gap-3">
                            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Save</button>
                            <button type="button" onclick="toggleRateEdit({{ $rate->id }}, true)"
                                class="text-gray-600 hover:text-gray-800 text-sm">Cancel</button>
                        </div>
                    </form>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="px-6 py-8 text-center text-gray-500">
                    No exchange rates found.
                    <a href="{{ route('settings.index') }}" class="text-green-700 underline">Set rates in Settings</a>.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
    @if($rates->hasPages())
    <div class="px-6 py-4 border-t border-gray-200">
        {{ $rates->links() }}
    </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    function toggleRateEdit(id, forceClose) {
        const row = document.getElementById('rate-edit-' + id);
        const open = row.classList.contains('hidden');

        document.querySelectorAll('tr[id^="rate-edit-"]').forEach((tr) => tr.classList.add('hidden'));

        if (open && ! forceClose) {
            row.classList.remove('hidden');
            row.querySelector('input[name="sell_rate"]').focus();
        }
    }
</script>
@endpush
