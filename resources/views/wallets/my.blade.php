@extends('layouts.app')

@section('title', 'My Wallet')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">My Wallet</h1>
    <p class="text-gray-600">Your commission balances across all currencies.</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    @foreach($wallets as $currency => $account)
    <div class="bg-white rounded-lg shadow p-6">
        <div class="text-sm text-gray-500 uppercase tracking-wide">{{ $currency }} Wallet</div>
        <div class="text-3xl font-bold text-gray-900 mt-2">
            @if($currency === 'UGX')
                UGX {{ number_format((float) $account->balance(), 0) }}
            @elseif($currency === 'RMB')
                &yen;{{ number_format((float) $account->balance(), 2) }}
            @else
                ${{ number_format((float) $account->balance(), 2) }}
            @endif
        </div>
        <div class="text-xs text-gray-400 mt-2">Account: {{ $account->code }}</div>
    </div>
    @endforeach
</div>

<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900">Recent Commission Entries</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @php
                    $entries = \App\Models\AccountEntry::whereIn('account_id', array_map(fn($a) => $a->id, $wallets))
                        ->where('type', 'agent_fee')
                        ->latest('entry_date')
                        ->latest('id')
                        ->take(20)
                        ->get();
                @endphp
                @forelse($entries as $entry)
                <tr>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $entry->entry_date->format('M d, Y') }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $entry->description }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $entry->reference ?? '-' }}</td>
                    <td class="px-6 py-4 text-sm">
                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">Commission</span>
                    </td>
                    <td class="px-6 py-4 text-sm text-right text-gray-900">
                        UGX {{ number_format((float) $entry->amount, 0) }}
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">No commission entries yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
