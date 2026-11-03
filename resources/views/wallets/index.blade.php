@extends('layouts.app')

@section('title', 'User Accounts')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">User Accounts</h1>
    <p class="text-gray-600">Cash each person is holding. A dash means that desk doesn't use the currency.</p>
</div>

@can('adjust-wallets')
<div class="bg-white rounded-lg shadow p-5 mb-6">
    <h2 class="text-lg font-semibold text-gray-900 mb-4">Adjust an Agent's Wallet</h2>
    <form method="POST" action="{{ route('wallets.adjust') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
        @csrf
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Agent</label>
            <select name="agent_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                <option value="">Choose an agent</option>
                @foreach($adjustable as $agent)
                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                @endforeach
            </select>
            @error('agent_id') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Action</label>
            <select name="direction" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                <option value="credit">Credit (add)</option>
                <option value="debit">Debit (remove)</option>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Amount (UGX)</label>
            <input type="number" name="amount" step="0.01" min="0.01" required value="{{ old('amount') }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
            @error('amount') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Reason</label>
            <input type="text" name="description" required maxlength="255" value="{{ old('description') }}"
                placeholder="e.g. Advance payment, correction for PS-..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
            @error('description') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-5 flex justify-end">
            <button type="submit" class="bg-green-800 text-white px-5 py-2 rounded-md text-sm font-medium hover:bg-green-700 transition">
                Post Adjustment
            </button>
        </div>
    </form>
</div>
@endcan

@if(empty($allWallets))
<div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">No user accounts yet.</div>
@else
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Person</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Country</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">UGX in hand</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">USD in hand</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">RMB in hand</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @foreach($allWallets as $agentId => $data)
            @php $held = $data['wallets']; @endphp
            <tr>
                <td class="px-6 py-4 text-sm font-medium text-gray-900">
                    @can('view-accounts')
                        <a href="{{ route('accounts.movements', ['owner_id' => $agentId]) }}" class="text-green-800 hover:underline">{{ $data['user']->name }}</a>
                    @else
                        {{ $data['user']->name }}
                    @endcan
                    @unless($data['user']->is_active)
                        <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-red-50 text-red-700">Blocked</span>
                    @endunless
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ $data['user']->countryLabel() ?: 'Not set' }}</td>
                @foreach(['UGX', 'USD', 'RMB'] as $currency)
                @php $given = $data['handed_in'][$currency] ?? '0.00'; @endphp
                <td class="px-6 py-4 text-sm text-right text-gray-900">
                    @if($held[$currency] ?? null)
                        @can('view-accounts')
                            <a href="{{ route('accounts.show', $held[$currency]) }}" class="hover:underline">{{ \App\Models\Account::format($currency, $held[$currency]->balance()) }}</a>
                        @else
                            {{ \App\Models\Account::format($currency, $held[$currency]->balance()) }}
                        @endcan
                        @if(bccomp((string) $given, '0', 2) > 0)
                        <span class="block text-xs text-gray-500">handed in {{ \App\Models\Account::format($currency, $given) }}</span>
                        @endif
                    @else
                        <span class="text-gray-400">&mdash;</span>
                    @endif
                </td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
        <tfoot class="bg-gray-50">
            <tr>
                <td class="px-6 py-3 text-sm font-semibold text-gray-900" colspan="2">Total</td>
                @foreach(['UGX', 'USD', 'RMB'] as $currency)
                <td class="px-6 py-3 text-sm text-right font-semibold text-gray-900">{{ \App\Models\Account::format($currency, $totals[$currency] ?? '0.00') }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>
</div>
@endif
@endsection
