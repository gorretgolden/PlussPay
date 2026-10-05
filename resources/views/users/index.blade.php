@extends('layouts.app')

@section('title', 'Users')

@php
    function roleDisplayName($slug) {
        $map = ['superadmin' => 'Super Admin'];
        return $map[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }
@endphp

@section('content')
<div class="mb-6 flex justify-between items-center">
    <h1 class="text-2xl font-bold text-gray-900">User Management</h1>
    <a href="{{ route('users.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Add User</a>
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
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
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
                        {{ roleDisplayName($user->getRoleNames()->first() ?? 'none') }}
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
                    {{ $user->last_login_at ? $user->last_login_at->format('M d, H:i') : 'Never' }}
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2 text-sm">
                        <a href="{{ route('users.edit', $user) }}" class="text-green-600 hover:text-green-800" title="Edit">Edit</a>

                        @if($user->id !== auth()->id())
                        <span class="text-gray-300">|</span>

                        <form method="POST" action="{{ route('users.toggle-active', $user) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="{{ $user->is_active ? 'text-yellow-600 hover:text-yellow-800' : 'text-green-600 hover:text-green-800' }}">
                                {{ $user->is_active ? 'Deactivate' : 'Activate' }}
                            </button>
                        </form>

                        <span class="text-gray-300">|</span>

                        @if($user->isLocked())
                        <form method="POST" action="{{ route('users.toggle-lock', $user) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="text-green-600 hover:text-green-800">Unlock</button>
                        </form>
                        @else
                        <form method="POST" action="{{ route('users.toggle-lock', $user) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="text-orange-600 hover:text-orange-800">Lock</button>
                        </form>
                        @endif

                        <span class="text-gray-300">|</span>

                        <button type="button" onclick="showResetForm({{ $user->id }}, '{{ $user->name }}')" class="text-blue-600 hover:text-blue-800">Reset Pwd</button>

                        @if(!$user->hasRole('superadmin'))
                        <span class="text-gray-300">|</span>
                        <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Delete user {{ $user->name }}? This cannot be undone.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-600 hover:text-red-800">Delete</button>
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
