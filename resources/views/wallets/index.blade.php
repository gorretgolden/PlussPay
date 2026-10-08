@extends('layouts.app')

@section('title', 'Agent Wallets')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Agent Wallets</h1>
    <p class="text-gray-600">Commission balances for every agent.</p>
</div>

@if(empty($allWallets))
<div class="bg-white rounded-lg shadow p-8 text-center text-gray-500">No active agents found.</div>
@else
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agent</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">UGX Balance</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">USD Balance</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">RMB Balance</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @foreach($allWallets as $agentId => $data)
            <tr>
                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $data['user']->name }}</td>
                <td class="px-6 py-4 text-sm text-right text-gray-900">UGX {{ number_format((float) $data['wallets']['UGX']->balance(), 0) }}</td>
                <td class="px-6 py-4 text-sm text-right text-gray-900">${{ number_format((float) $data['wallets']['USD']->balance(), 2) }}</td>
                <td class="px-6 py-4 text-sm text-right text-gray-900">&yen;{{ number_format((float) $data['wallets']['RMB']->balance(), 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif
@endsection
