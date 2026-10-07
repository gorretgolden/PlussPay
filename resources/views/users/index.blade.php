@extends('layouts.app')

@section('title', 'Users')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <h1 class="text-2xl font-bold text-gray-900">User Management</h1>
    <div class="flex gap-2">
        <a href="{{ route('export.users', request()->query()) }}" class="bg-blue-800 text-white px-4 py-2 rounded-md text-sm hover:bg-blue-700">Export Excel</a>
        <a href="{{ route('users.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Add User</a>
    </div>
</div>

{{-- Search/Filter Bar --}}
<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('users.index') }}" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Name or email..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Role</label>
            <select name="role" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Roles</option>
                @foreach($roles as $r)
                <option value="{{ $r }}" {{ request('role') === $r ? 'selected' : '' }}>{{ \App\Http\Controllers\RolePermissionController::displayName($r) }}</option>
                @endforeach
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
        <a href="{{ route('users.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Login</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($users as $user)
            <tr>
                <td class="px-4 py-3 text-sm">
                    <div class="font-medium text-gray-900">{{ $user->name }}</div>
                    <div class="text-gray-500 text-xs">{{ $user->phone ?? '' }}</div>
                </td>
                <td class="px-4 py-3 text-sm text-gray-900">{{ $user->email }}</td>
                <td class="px-4 py-3">
                    <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">
                        {{ \App\Http\Controllers\RolePermissionController::displayName($user->getRoleNames()->first() ?? 'none') }}
                    </span>
                </td>
                <td class="px-4 py-3">
                    @if(!$user->is_active)
                        <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-800">Inactive</span>
                    @elseif($user->isLocked())
                        <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">Locked</span>
                    @else
                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">Active</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-gray-500">
                    {{ $user->last_login_at ? $user->last_login_at->format('M d, h:i A') : 'Never' }}
                </td>
                <td data-row-menu-host class="px-4 py-3 text-right relative">
                    <button type="button" onclick="toggleMenu({{ $user->id }})" class="p-1 rounded hover:bg-gray-100">
                        <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                    </button>
                    <div id="menu-{{ $user->id }}" data-row-menu class="hidden absolute right-0 top-8 z-20 bg-white rounded-lg shadow-lg border border-gray-200 py-1 w-44 text-left">
                        <a href="{{ route('users.edit', $user) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Edit User</a>

                        @if($user->id !== auth()->id())
                        <form method="POST" action="{{ route('users.toggle-active', $user) }}">
                            @csrf @method('PATCH')
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm {{ $user->is_active ? 'text-yellow-700' : 'text-green-700' }} hover:bg-gray-50">
                                {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('users.toggle-lock', $user) }}">
                            @csrf @method('PATCH')
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-orange-700 hover:bg-gray-50">
                                {{ $user->isLocked() ? 'Unlock Account' : 'Lock Account' }}
                            </button>
                        </form>

                        <button type="button" onclick="showResetForm({{ $user->id }}, '{{ addslashes($user->name) }}')" class="w-full text-left px-4 py-2 text-sm text-blue-700 hover:bg-gray-50">Reset Password</button>

                        @if(!$user->hasRole('superadmin'))
                        <div class="border-t border-gray-100 my-1"></div>
                        <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Delete user {{ $user->name }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50">Delete User</button>
                        </form>
                        @endif
                        @endif
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-6 py-8 text-center text-gray-500">No users found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $users->links() }}
</div>

{{-- Reset Password Modal --}}
<div id="resetModal" class="fixed inset-0 bg-black/50 hidden flex items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 w-full max-w-sm mx-4">
        <h3 class="text-lg font-semibold mb-4">Reset Password: <span id="resetUserName"></span></h3>
        <form method="POST" id="resetForm" action="">
            @csrf
            <div class="mb-3">
                <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                <input type="password" name="password" required minlength="8"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                <input type="password" name="password_confirmation" required minlength="8"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-green-500">
            </div>
            <div class="flex gap-2 justify-end">
                <button type="button" onclick="closeResetModal()" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Reset</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
function showResetForm(userId, userName) {
    document.getElementById('resetUserName').textContent = userName;
    document.getElementById('resetForm').action = '/users/' + userId + '/reset-password';
    document.getElementById('resetModal').classList.remove('hidden');
}
function closeResetModal() {
    document.getElementById('resetModal').classList.add('hidden');
}
</script>
@endpush
@endsection
