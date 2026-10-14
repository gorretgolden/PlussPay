@extends('layouts.app')

@section('title', 'Exchange Rates')

@section('content')
@php
    $editing = (int) old('rate_id');
    $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ','), '0'), '.');
    $input = fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
@endphp
<div class="mb-6 flex flex-wrap justify-between items-start gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Exchange Rates</h1>
        <p class="text-gray-600">The record of every price the business has worked at. A new price is set in System Settings, where only the two pairs that are actually used can be entered. Nothing here is deleted: an old row is the proof of what the price was on those days.</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('settings.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Change the prices</a>
        <a href="{{ route('export.exchange-rates', request()->query()) }}"
            class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('exchange-rates.index') }}" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[180px]">
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Currency or who added it"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Currency</label>
            <select name="currency" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Currencies</option>
                <option value="USD" {{ request('currency') === 'USD' ? 'selected' : '' }}>USD</option>
                <option value="UGX" {{ request('currency') === 'UGX' ? 'selected' : '' }}>UGX</option>
                <option value="RMB" {{ request('currency') === 'RMB' ? 'selected' : '' }}>RMB</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Status</label>
            <select name="status" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>In use</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Earlier prices</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Added By</label>
            <select name="created_by" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Anyone</option>
                @foreach($creators as $creator)
                <option value="{{ $creator->id }}" {{ request('created_by') == $creator->id ? 'selected' : '' }}>{{ $creator->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}"
                class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
        <a href="{{ route('exchange-rates.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
    <div class="flex flex-wrap gap-2 mt-3 pt-3 border-t border-gray-200">
        @php
            $quickBase = array_filter(request()->only(['search', 'currency', 'status', 'created_by']), fn ($v) => $v !== null && $v !== '');
            $ranges = [
                'Today' => [today()->toDateString(), today()->toDateString()],
                'Yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
                'This Month' => [now()->startOfMonth()->toDateString(), now()->toDateString()],
                'Last Month' => [now()->subMonth()->startOfMonth()->toDateString(), now()->subMonth()->endOfMonth()->toDateString()],
            ];
        @endphp
        <span class="text-sm text-gray-500 self-center mr-1">Added:</span>
        @foreach($ranges as $label => [$from, $to])
        <a href="{{ route('exchange-rates.index', array_merge($quickBase, ['date_from' => $from, 'date_to' => $to])) }}"
            class="px-3 py-1 text-xs rounded-full {{ request('date_from') === $from && request('date_to') === $to ? 'bg-green-800 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">{{ $label }}</a>
        @endforeach
    </div>
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
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <h2 class="text-sm font-semibold text-gray-900">Worked example: {{ $sendExample['hands_over'] }} handed over for a USD 1,000 send at the rates now live</h2>
    <p class="text-xs text-gray-500 mt-1 mb-3">The charge comes out of that money, so {{ $sendExample['travels'] }} travels and buys {{ $sendExample['dollars'] }}. This is what that one transfer makes.</p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
        <div>
            <div class="text-xs text-gray-500">Charge</div>
            <div class="font-medium text-gray-900">{{ $sendExample['charge'] }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Foreign exchange profit</div>
            <div class="font-medium text-gray-900">{{ $sendExample['exchange'] }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Overall profit</div>
            <div class="font-medium text-gray-900">{{ $sendExample['total'] }}</div>
        </div>
    </div>
</div>
@endif

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Selling</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Buying</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Margin</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Added By</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Added</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Edited By</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Updated</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($rates as $rate)
            <tr>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $rate->from_currency }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $rate->to_currency }}</td>
                <td class="px-6 py-4 text-sm text-gray-900 font-medium">{{ $trim($rate->sell_rate ?? $rate->rate) }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $rate->buy_rate === null ? 'Not set' : $trim($rate->buy_rate) }}</td>
                <td class="px-6 py-4 text-sm {{ ($rate->buy_rate === null) ? 'text-gray-400' : (((float) ($rate->sell_rate ?? $rate->rate) - (float) $rate->buy_rate) >= 0 ? 'text-green-700' : 'text-red-600') }}">
                    {{ $rate->buy_rate === null ? 'Pending' : $trim((float) ($rate->sell_rate ?? $rate->rate) - (float) $rate->buy_rate) }}
                </td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs rounded-full {{ $rate->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ $rate->is_active ? 'In use' : 'Earlier' }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-700 whitespace-nowrap">{{ $rate->creator?->name ?? 'Not recorded' }}</td>
                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $rate->created_at->format('M d, Y h:i A') }}</td>
                <td class="px-6 py-4 text-sm text-gray-700 whitespace-nowrap">{{ $rate->editor?->name ?? 'Not recorded' }}</td>
                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $rate->updated_at->format('M d, Y h:i A') }}</td>
                <td class="px-6 py-4 text-sm text-right whitespace-nowrap">
                    <button type="button" onclick="toggleRateEdit({{ $rate->id }})"
                        class="text-green-700 hover:text-green-900 underline">Edit</button>
                    @if(! $rate->is_active)
                    <form method="POST" action="{{ route('exchange-rates.toggle', $rate) }}" class="inline ml-3">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="text-gray-600 hover:text-gray-900 underline">Make this the price in use</button>
                    </form>
                    @endif
                </td>
            </tr>
            <tr id="rate-edit-{{ $rate->id }}" class="{{ $editing === $rate->id ? '' : 'hidden' }} bg-gray-50">
                <td colspan="11" class="px-6 py-4">
                    <form method="POST" action="{{ route('exchange-rates.update', $rate) }}" class="flex flex-wrap gap-4 items-start">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="rate_id" value="{{ $rate->id }}">
                        <div class="w-40">
                            <p class="text-sm font-medium text-gray-900">{{ $rate->from_currency }} to {{ $rate->to_currency }}</p>
                            <p class="text-xs text-gray-500">{{ $rate->is_active ? 'The price in use now.' : 'Added '.$rate->created_at->format('M d, Y').'. An earlier price.' }}</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1" for="sell_rate-{{ $rate->id }}">Selling price *</label>
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
                            <label class="block text-xs text-gray-500 mb-1" for="buy_rate-{{ $rate->id }}">Buying price</label>
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
                                <span>I understand this is an earlier price, so correcting it changes how the transfers entered under it read back.</span>
                            </label>
                            @error('history_ok')
                            @if($editing === $rate->id)
                            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                            @endif
                            @enderror
                        </div>
                        @endif
                        <div class="flex items-center gap-3">
                            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Save changes</button>
                            <button type="button" onclick="toggleRateEdit({{ $rate->id }}, true)"
                                class="text-gray-600 hover:text-gray-800 text-sm">Cancel</button>
                        </div>
                        <p class="basis-full text-xs text-gray-500">Selling is what customers are quoted and it drives every conversion. Buying is what a dollar costs the business and it only measures the margin. Leaving the buying price empty means this row carries one figure, so its margin reads as nil.</p>
                    </form>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="11" class="px-6 py-8 text-center text-gray-500">
                    No exchange rates recorded yet.
                    <a href="{{ route('settings.index') }}" class="text-green-700 underline">Set the first prices in Settings</a>.
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

<p class="mt-4 text-xs text-gray-500">The rate that is in use for a pair is the newest row for it. Setting a new price in Settings retires the row before it rather than overwriting it, so the day the price changed can still be proved. Transfers and counter deals keep the figures they were entered with; changing a price here never restates money that has already been booked, except for a row that is an earlier price and is being corrected on purpose.</p>
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
