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
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, email or phone..."
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
        <div>
            <label class="block text-xs text-gray-500 mb-1">Login hours</label>
            <select name="access" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                <option value="restricted" {{ request('access') === 'restricted' ? 'selected' : '' }}>Restricted</option>
                <option value="open" {{ request('access') === 'open' ? 'selected' : '' }}>Any time</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Two-factor</label>
            <select name="two_factor" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                <option value="on" {{ request('two_factor') === 'on' ? 'selected' : '' }}>On</option>
                <option value="off" {{ request('two_factor') === 'off' ? 'selected' : '' }}>Off</option>
            </select>
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
        <a href="{{ route('users.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    {{-- Bulk actions. Table checkboxes join this form through the form attribute. --}}
    <form method="POST" action="{{ route('users.bulk') }}" id="bulkForm" onsubmit="return confirmBulk(event)"
        class="flex flex-wrap items-center gap-3 px-4 py-3 border-b border-gray-200 bg-gray-50 rounded-t-lg">
        @csrf
        <label class="text-xs font-medium text-gray-500 uppercase">Bulk actions</label>
        <select name="action" id="bulkAction" class="px-3 py-2 border border-gray-300 rounded-md text-sm bg-white min-w-[260px]">
            <option value="">Choose an action...</option>
            <option value="activate">Activate accounts</option>
            <option value="deactivate">Deactivate accounts (blocks sign-in)</option>
            <option value="lock">Lock accounts</option>
            <option value="unlock">Unlock accounts</option>
            <option value="window_weekdays">Set login hours: Mon to Fri, 08:00 to 18:00</option>
            <option value="window_clear">Remove login hour limits</option>
            <option value="two_factor_off">Turn off two-factor sign-in</option>
        </select>
        <button type="submit" id="bulkApply" disabled
            class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed">
            Apply
        </button>
        <span id="bulkCount" class="text-sm text-gray-500">No users selected</span>
    </form>

    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-3 py-3 text-left">
                    <input type="checkbox" id="bulkAll" form="bulkForm" onchange="toggleAllBulk(this)"
                        class="rounded border-gray-300 text-green-700 focus:ring-green-600" title="Select all on this page">
                </th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Login hours</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">2FA</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Login</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($users as $user)
            @php
                $protected = $user->id === auth()->id()
                    || ($user->hasRole('superadmin') && !auth()->user()->hasRole('superadmin'));
            @endphp
            <tr>
                <td class="px-3 py-3">
                    <input type="checkbox" name="user_ids[]" value="{{ $user->id }}" form="bulkForm"
                        class="bulk-user rounded border-gray-300 text-green-700 focus:ring-green-600" onchange="updateBulkCount()"
                        @if($protected) disabled title="Your own or a superadmin account is protected from bulk actions" @endif>
                </td>
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
                <td class="px-4 py-3 text-sm whitespace-nowrap">
                    @if($user->hasLoginWindow())
                        <span class="text-orange-700">{{ $user->loginWindowSummary() }}</span>
                    @else
                        <span class="text-gray-500">Any time</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm whitespace-nowrap">
                    @if($user->two_factor_enabled)
                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">On</span>
                    @elseif($user->two_factor_secret)
                        <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">Setup pending</span>
                    @else
                        <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-600">Off</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                    {{ $user->last_login_at ? $user->last_login_at->format('M d, h:i A') : 'Never' }}
                </td>
                <td data-row-menu-host class="px-4 py-3 text-right relative">
                    <button type="button" onclick="toggleMenu({{ $user->id }})" class="p-1 rounded hover:bg-gray-100">
                        <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                    </button>
                    <div id="menu-{{ $user->id }}" data-row-menu class="hidden absolute right-0 top-8 z-20 bg-white rounded-lg shadow-lg border border-gray-200 py-1 w-48 text-left">
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
                        @endif

                        <div class="border-t border-gray-100 my-1"></div>

                        @if($user->two_factor_enabled)
                        <form method="POST" action="{{ route('users.two-factor.disable', $user) }}" onsubmit="return confirm('Turn two-factor sign-in off for {{ addslashes($user->name) }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-gray-50">Turn off 2FA</button>
                        </form>
                        @else
                        <a href="{{ route('users.two-factor', $user) }}" class="block px-4 py-2 text-sm text-green-800 hover:bg-gray-50">Set up 2FA (OTP)</a>
                        @endif

                        @if(!$user->hasRole('superadmin'))
                        <form method="POST" action="{{ route('users.destroy', $user) }}" onsubmit="return confirm('Delete user {{ $user->name }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50">Delete User</button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="9" class="px-6 py-8 text-center text-gray-500">No users found.</td>
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
function bulkBoxes() {
    return Array.from(document.querySelectorAll('.bulk-user'));
}

function selectedCount() {
    return bulkBoxes().filter(box => box.checked).length;
}

function toggleAllBulk(master) {
    bulkBoxes().forEach(box => {
        if (!box.disabled) box.checked = master.checked;
    });
    updateBulkCount();
}

function updateBulkCount() {
    const count = selectedCount();
    const label = document.getElementById('bulkCount');
    label.textContent = count === 0 ? 'No users selected' : count + ' user(s) selected';
    label.classList.toggle('text-green-800', count > 0);
    label.classList.toggle('text-gray-500', count === 0);
    document.getElementById('bulkApply').disabled = count === 0;

    const boxes = bulkBoxes().filter(box => !box.disabled);
    const master = document.getElementById('bulkAll');
    master.checked = boxes.length > 0 && boxes.every(box => box.checked);
    master.indeterminate = count > 0 && count < boxes.length;
}

function confirmBulk(event) {
    const select = document.getElementById('bulkAction');
    const count = selectedCount();

    if (!select.value) {
        event.preventDefault();
        alert('Choose a bulk action first.');
        return false;
    }

    return confirm('Apply "' + select.options[select.selectedIndex].text + '" to ' + count + ' selected user(s)?');
}

document.addEventListener('DOMContentLoaded', updateBulkCount);

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
