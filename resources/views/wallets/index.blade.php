@extends('layouts.app')

@section('title', 'Agent Wallets')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Agent Wallets</h1>
    <p class="text-gray-600">Commission balances for every agent. A desk in Uganda holds shillings and dollars and a desk in China holds yuan and dollars, so a dash means that currency is not one this person keeps.</p>
</div>

@if(empty($allWallets))
<div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">No active agents found.</div>
@else
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agent</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Country</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">UGX Balance</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">USD Balance</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">RMB Balance</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @foreach($allWallets as $agentId => $data)
            @php $held = $data['wallets']; @endphp
            <tr>
                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $data['user']->name }}</td>
                <td class="px-6 py-4 text-sm text-gray-500">{{ $data['user']->countryLabel() ?: 'Not set' }}</td>
                @foreach(['UGX', 'USD', 'RMB'] as $currency)
                <td class="px-6 py-4 text-sm text-right text-gray-900">
                    @if($held[$currency] ?? null)
                        @php $balance = (float) $held[$currency]->balance(); @endphp
                        @if($currency === 'UGX')UGX {{ number_format($balance, 0) }}
                        @elseif($currency === 'RMB')&yen;{{ number_format($balance, 2) }}
                        @else${{ number_format($balance, 2) }}
                        @endif
                    @else
                        <span class="text-gray-400">&mdash;</span>
                    @endif
                </td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
