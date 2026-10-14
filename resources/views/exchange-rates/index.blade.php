@extends('layouts.app')

@section('title', 'Exchange Rates')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Exchange Rates</h1>
        <p class="text-gray-600">Manage currency conversion rates</p>
    </div>
    <div class="flex gap-2">
        <button type="button" id="openAddRate"
            class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Add Exchange Rate</button>
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
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
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
    <h2 class="text-sm font-semibold text-gray-900">Worked example: a USD 1,000 send at the rates now live</h2>
    <p class="text-xs text-gray-500 mt-1 mb-3">The customer hands over {{ $sendExample['hands_over'] }} and we buy {{ $sendExample['buys'] }} with it. This is what that one transfer makes.</p>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
        <div>
            <div class="text-xs text-gray-500">Service fee</div>
            <div class="font-medium text-gray-900">{{ $sendExample['fee'] }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Margin on shillings</div>
            <div class="font-medium text-gray-900">{{ $sendExample['margin'] }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Yuan leg</div>
            <div class="font-medium text-gray-900">{{ $sendExample['yuan_leg'] }}</div>
        </div>
        <div>
            <div class="text-xs text-gray-500">Total profit</div>
            <div class="font-medium text-gray-900">{{ $sendExample['total'] }}</div>
        </div>
    </div>
</div>
@endif

<div class="bg-white rounded-lg shadow overflow-x-auto">
    @php $trim = fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ','), '0'), '.'); @endphp
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
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
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
                        {{ $rate->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-700 whitespace-nowrap">{{ $rate->creator?->name ?? 'Not recorded' }}</td>
                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $rate->created_at->format('M d, Y h:i A') }}</td>
                <td class="px-6 py-4 text-sm text-gray-700 whitespace-nowrap">{{ $rate->editor?->name ?? 'Not recorded' }}</td>
                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $rate->updated_at->format('M d, Y h:i A') }}</td>
                <td class="px-6 py-4 text-sm">
                    <form method="POST" action="{{ route('exchange-rates.toggle', $rate) }}" class="inline">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="text-green-600 hover:text-green-800">
                            {{ $rate->is_active ? 'Deactivate' : 'Activate' }}
                        </button>
                    </form>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="11" class="px-6 py-8 text-center text-gray-500">No exchange rates configured.</td>
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

{{-- Add Exchange Rate Modal --}}
<div id="addRateModal" class="fixed inset-0 z-50 {{ $errors->any() ? '' : 'hidden' }}">
    <div class="absolute inset-0 bg-black bg-opacity-50 modal-backdrop"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-start">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Add Exchange Rate</h3>
                    <p class="text-sm text-gray-500">Saving a new rate retires the current one for that pair.</p>
                </div>
                <button type="button" class="modal-cancel text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
            </div>
            <form method="POST" action="{{ route('exchange-rates.store') }}">
                @csrf
                <div class="px-6 py-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">From Currency</label>
                        <select name="from_currency" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                            @foreach(['USD', 'UGX', 'RMB'] as $currency)
                            <option value="{{ $currency }}" {{ old('from_currency') === $currency ? 'selected' : '' }}>{{ $currency }}</option>
                            @endforeach
                        </select>
                        @error('from_currency')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">To Currency</label>
                        <select name="to_currency" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                            @foreach(['RMB', 'USD', 'UGX'] as $currency)
                            <option value="{{ $currency }}" {{ old('to_currency') === $currency ? 'selected' : '' }}>{{ $currency }}</option>
                            @endforeach
                        </select>
                        @error('to_currency')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Selling rate</label>
                        <input type="number" name="rate" step="0.000001" min="0.000001" required value="{{ old('rate') }}"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        @error('rate')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Buying rate</label>
                        <input type="number" name="buy_rate" step="0.000001" min="0.000001" value="{{ old('buy_rate') }}"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                        @error('buy_rate')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                    <p class="sm:col-span-2 text-xs text-gray-500">The selling rate is what customers are quoted and is used for every conversion. The buying rate is what the dollar costs you, and only measures profit.</p>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button type="button" class="modal-cancel px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                    <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700 text-sm font-medium">Save Rate</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const addRateModal = document.getElementById('addRateModal');

    document.getElementById('openAddRate').addEventListener('click', () => {
        addRateModal.classList.remove('hidden');
        addRateModal.querySelector('input[name="rate"]').focus();
    });

    addRateModal.querySelectorAll('.modal-cancel').forEach(btn => btn.addEventListener('click', () => addRateModal.classList.add('hidden')));
    addRateModal.querySelector('.modal-backdrop').addEventListener('click', () => addRateModal.classList.add('hidden'));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') addRateModal.classList.add('hidden'); });
</script>
@endpush
