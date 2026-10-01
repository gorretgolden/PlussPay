@extends('layouts.app')

@section('title', 'Agent Performance')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Agent Performance</h1>
        <p class="text-gray-600">Track agent productivity and revenue</p>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-6 mb-6">
    <form method="GET" action="{{ route('agents.performance') }}" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">From Date</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">To Date</label>
            <input type="date" name="date_to" value="{{ $dateTo }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
        </div>
        <div class="flex items-end">
            <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700">Filter</button>
        </div>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Agent</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Transactions</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Completed</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pending</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Success Rate</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Amount (USD)</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Revenue Generated</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($agents as $data)
            <tr>
                <td class="px-6 py-4">
                    <div class="text-sm font-medium text-gray-900">{{ $data['agent']->name }}</div>
                    <div class="text-sm text-gray-500">{{ $data['agent']->email }}</div>
                </td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ number_format($data['total_transactions']) }}</td>
                <td class="px-6 py-4 text-sm text-green-600 font-medium">{{ number_format($data['completed_transactions']) }}</td>
                <td class="px-6 py-4 text-sm text-yellow-600">{{ number_format($data['pending_transactions']) }}</td>
                <td class="px-6 py-4">
                    <div class="flex items-center">
                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                            <div class="bg-green-600 h-2 rounded-full" style="width: {{ $data['success_rate'] }}%"></div>
                        </div>
                        <span class="text-sm text-gray-900">{{ $data['success_rate'] }}%</span>
                    </div>
                </td>
                <td class="px-6 py-4 text-sm text-gray-900">${{ number_format($data['total_amount'], 2) }}</td>
                <td class="px-6 py-4 text-sm font-medium text-green-700">${{ number_format($data['total_revenue'], 2) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="px-6 py-8 text-center text-gray-500">No agents found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
