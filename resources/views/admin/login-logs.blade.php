@extends('layouts.app')

@section('title', 'Login Logs')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Login Logs</h1>
        <p class="text-gray-600">User authentication history</p>
    </div>
    <a href="{{ route('export.login-logs', request()->query()) }}"
        class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('admin.login-logs') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm text-gray-600 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, email, IP..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Event</label>
            <select name="action" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Events</option>
                <option value="login" {{ request('action') === 'login' ? 'selected' : '' }}>Login</option>
                <option value="logout" {{ request('action') === 'logout' ? 'selected' : '' }}>Logout</option>
                <option value="login_blocked" {{ request('action') === 'login_blocked' ? 'selected' : '' }}>Login blocked</option>
                <option value="login_failed" {{ request('action') === 'login_failed' ? 'selected' : '' }}>Failed attempt</option>
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
            <a href="{{ route('admin.login-logs') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Reset</a>
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
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP Address</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Details</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($logs as $log)
                <tr>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ \Carbon\Carbon::parse($log->created_at)->format('M d, Y h:i A') }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $log->user?->name ?? ($log->action === 'login_failed' ? 'No such account' : 'System') }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600">{{ $log->user?->email ?? '-' }}</td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 text-xs rounded-full {{ $log->action === 'login' ? 'bg-green-100 text-green-800' : ($log->action === 'login_blocked' ? 'bg-red-100 text-red-800' : ($log->action === 'login_failed' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700')) }}">
                            {{ str_replace('_', ' ', ucfirst($log->action)) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ $log->sourceIp() ?? 'not recorded' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600">{{ $log->description ?? '-' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">No login logs found.</td>
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
