@extends('layouts.app')

@section('title', 'Activity Logs')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Activity Logs</h1>
    <p class="text-gray-600">Transaction activity audit trail</p>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('admin.activity-logs') }}" class="flex items-end gap-4 flex-wrap">
        <div>
            <label class="block text-sm text-gray-600 mb-1">Action</label>
            <select name="action" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Actions</option>
                @foreach($actions as $action)
                <option value="{{ $action }}" {{ request('action') === $action ? 'selected' : '' }}>{{ ucfirst($action) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">User</label>
            <select name="user_id" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Users</option>
                @foreach($users as $user)
                <option value="{{ $user->id }}" {{ request('user_id') == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
        <a href="{{ route('admin.activity-logs') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Reset</a>
    </form>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Transaction</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($logs as $log)
                <tr>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $log->created_at->format('M d, Y H:i') }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $log->user?->name ?? 'System' }}</td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">{{ ucfirst($log->action) }}</span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-600">{{ $log->description ?? '-' }}</td>
                    <td class="px-6 py-4 text-sm">
                        @if($log->transaction)
                        <a href="{{ route('transactions.show', $log->transaction) }}" class="text-green-600 hover:text-green-800">{{ $log->transaction->receipt_number }}</a>
                        @else
                        -
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">No activity logs found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())
    <div class="px-6 py-4 border-t border-gray-200">
        {{ $logs->links() }}
    </div>
    @endif
</div>
@endsection
