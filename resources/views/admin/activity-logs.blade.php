@extends('layouts.app')

@section('title', 'Activity Logs')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Activity Logs</h1>
        <p class="text-gray-600">Transaction activity audit trail</p>
    </div>
    <a href="{{ route('export.activity-logs', request()->query()) }}"
        class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('admin.activity-logs') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm text-gray-600 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Description, user, receipt #..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Action</label>
            <select name="action" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Actions</option>
                @foreach($actions as $action)
                <option value="{{ $action }}" {{ request('action') === $action ? 'selected' : '' }}>{{ ucfirst($action) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">User</label>
            <select name="user_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Users</option>
                @foreach($users as $user)
                <option value="{{ $user->id }}" {{ request('user_id') == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">From Date</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">To Date</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div class="md:col-span-5 flex gap-2">
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
            <a href="{{ route('admin.activity-logs') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Reset</a>
        </div>
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
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Details</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($logs as $log)
                <tr>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $log->created_at->format('M d, Y h:i A') }}</td>
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
                    <td class="px-6 py-4 text-sm text-right">
                        <button type="button" data-log-details="{{ route('admin.activity-logs.details', $log) }}"
                            class="text-green-700 hover:text-green-900 hover:underline">View</button>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">No activity logs found.</td>
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

{{-- Log Details Modal --}}
<div id="logDetailModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black bg-opacity-50 log-detail-close"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4 pointer-events-none">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl flex flex-col max-h-[85vh] pointer-events-auto">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">Change Details</h3>
                <button type="button" class="log-detail-close text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
            </div>
            <div id="logDetailBody" class="px-6 py-5 overflow-y-auto text-sm">
                <p class="text-gray-500">Loading...</p>
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
                <button type="button" class="log-detail-close px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const logDetailModal = document.getElementById('logDetailModal');
    const logDetailBody = document.getElementById('logDetailBody');

    const closeLogDetail = () => logDetailModal.classList.add('hidden');

    document.querySelectorAll('[data-log-details]').forEach((button) => {
        button.addEventListener('click', async () => {
            logDetailModal.classList.remove('hidden');
            logDetailBody.innerHTML = '<p class="text-gray-500">Loading...</p>';

            try {
                const response = await fetch(button.dataset.logDetails, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Request failed');
                }

                logDetailBody.innerHTML = await response.text();
            } catch (error) {
                logDetailBody.innerHTML = '<p class="text-red-700">Could not load these details. Refresh the page and try again.</p>';
            }
        });
    });

    document.querySelectorAll('.log-detail-close').forEach((el) => el.addEventListener('click', closeLogDetail));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeLogDetail();
    });
</script>
@endpush
