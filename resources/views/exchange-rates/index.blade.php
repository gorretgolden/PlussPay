@extends('layouts.app')

@section('title', 'Exchange Rates')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Exchange Rates</h1>
        <p class="text-gray-600">Manage currency conversion rates</p>
    </div>
    <a href="{{ route('export.exchange-rates', request()->query()) }}"
        class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('exchange-rates.index') }}" class="flex flex-wrap gap-3 items-end">
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
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
        <a href="{{ route('exchange-rates.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
</div>

<div class="bg-white rounded-lg shadow p-6 mb-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Add / Update Rate</h2>
    <form method="POST" action="{{ route('exchange-rates.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">From Currency</label>
            <select name="from_currency" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="USD">USD</option>
                <option value="UGX">UGX</option>
                <option value="RMB">RMB</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">To Currency</label>
            <select name="to_currency" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="RMB">RMB</option>
                <option value="USD">USD</option>
                <option value="UGX">UGX</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Rate</label>
            <input type="number" name="rate" step="0.000001" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div class="flex items-end">
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Save Rate</button>
        </div>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">From</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">To</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rate</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Updated</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($rates as $rate)
            <tr>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $rate->from_currency }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $rate->to_currency }}</td>
                <td class="px-6 py-4 text-sm text-gray-900 font-medium">{{ $rate->rate }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs rounded-full {{ $rate->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ $rate->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ $rate->updated_at->format('M d, Y H:i') }}</td>
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
                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No exchange rates configured.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
