@extends('layouts.app')

@section('title', 'All Permissions')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">All Permissions</h1>
        <p class="text-gray-600">{{ $totalPermissions }} permission(s) in system &middot; {{ $roles->count() }} role(s)</p>
    </div>
</div>

@if($errors->any())
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
    @foreach($errors->all() as $error)
    <p>{{ $error }}</p>
    @endforeach
</div>
@endif

{{-- Search + Create --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Search Permissions</h2>
        <form method="GET" action="{{ route('admin.permissions') }}" class="flex gap-3 items-end">
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">Filter by name</label>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="e.g. transaction, customer..."
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>
            <button type="submit" class="bg-gray-700 text-white px-6 py-2 rounded-lg hover:bg-gray-600 text-sm font-medium">
                Search
            </button>
            @if(request('search'))
            <a href="{{ route('admin.permissions') }}" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Clear</a>
            @endif
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-800 mb-4">Add New Permission</h2>
        <form method="POST" action="{{ route('admin.permissions.store') }}" class="flex gap-3 items-end">
            @csrf
            <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">Permission Name</label>
                <input type="text" name="name" required placeholder="e.g. manage-inventory"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
            </div>
            <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-lg hover:bg-green-700 text-sm font-medium">
                Create
            </button>
        </form>
    </div>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase sticky left-0 bg-gray-50 z-10">Permission</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    @foreach($roles as $role)
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase whitespace-nowrap">{{ \App\Http\Controllers\RolePermissionController::displayName($role->name) }}</th>
                    @endforeach
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">In Use</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($permissions as $permission)
                @php
                    $roleCount = $permission->roles->count();
                    $userCount = DB::table('model_has_permissions')->where('permission_id', $permission->id)->count();
                    $inUse = $roleCount > 0 || $userCount > 0;
                @endphp
                <tr data-id="{{ $permission->id }}">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900 sticky left-0 bg-white z-10">{{ $permission->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600">{{ ucwords(str_replace('-', ' ', $permission->name)) }}</td>
                    @foreach($roles as $role)
                    @php
                        $hasIt = $role->hasPermissionTo($permission->name);
                        $locked = $role->name === 'superadmin' && !auth()->user()->hasRole('superadmin');
                    @endphp
                    <td class="px-4 py-4 text-center">
                        <button type="button"
                            class="toggle-perm-btn inline-flex items-center justify-center w-10 h-6 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-green-500 {{ $hasIt ? 'bg-green-600' : 'bg-gray-300' }} {{ $locked ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:opacity-80' }}"
                            data-permission="{{ $permission->id }}"
                            data-role="{{ $role->id }}"
                            aria-label="{{ $hasIt ? 'Revoke' : 'Grant' }} {{ $permission->name }} for {{ $role->name }}"
                            {{ $locked ? 'disabled' : '' }}>
                            <span class="inline-block w-4 h-4 rounded-full bg-white shadow transform transition-transform {{ $hasIt ? 'translate-x-2' : '-translate-x-2' }}"></span>
                        </button>
                    </td>
                    @endforeach
                    <td class="px-6 py-4 text-center">
                        @if($inUse)
                        <span class="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800" title="{{ $roleCount }} role(s), {{ $userCount }} user(s)">Yes</span>
                        @else
                        <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-500">No</span>
                        @endif
                    </td>
                    <td class="px-6 py-4 text-right text-sm space-x-2 whitespace-nowrap">
                        <button type="button" class="edit-perm-btn text-blue-600 hover:text-blue-800 font-medium"
                            data-id="{{ $permission->id }}"
                            data-name="{{ $permission->name }}">Edit</button>
                        @if(!$inUse)
                        <form method="POST" action="{{ route('admin.permissions.destroy', $permission) }}" class="inline"
                            onsubmit="return confirm('Delete permission &quot;{{ $permission->name }}&quot;?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-red-600 hover:text-red-800 font-medium">Delete</button>
                        </form>
                        @else
                        <span class="text-gray-400 cursor-not-allowed" title="Remove from all roles and users first">Delete</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ 4 + $roles->count() }}" class="px-6 py-8 text-center text-gray-500">
                        {{ request('search') ? 'No permissions match "' . e(request('search')) . '".' : 'No permissions found.' }}
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($permissions->hasPages())
    <div class="px-6 py-4 border-t border-gray-200">
        {{ $permissions->links() }}
    </div>
    @endif
</div>

{{-- Edit Modal --}}
<div id="editPermModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black bg-opacity-50 modal-backdrop"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md relative">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">Edit Permission</h3>
            </div>
            <form id="editPermForm" method="POST">
                @csrf
                @method('PUT')
                <div class="p-6">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Permission Name</label>
                    <input type="text" name="name" id="editPermName" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button type="button" class="modal-cancel px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                    <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-lg hover:bg-green-700 text-sm font-medium">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const modal = document.getElementById('editPermModal');
    const form = document.getElementById('editPermForm');
    const nameInput = document.getElementById('editPermName');

    document.querySelectorAll('.edit-perm-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            form.action = '/admin/permissions/' + btn.dataset.id;
            nameInput.value = btn.dataset.name;
            modal.classList.remove('hidden');
            nameInput.focus();
        });
    });

    modal.querySelector('.modal-backdrop').addEventListener('click', () => modal.classList.add('hidden'));
    modal.querySelector('.modal-cancel').addEventListener('click', () => modal.classList.add('hidden'));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') modal.classList.add('hidden'); });

    document.querySelectorAll('.toggle-perm-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            if (this.disabled) return;
            const permId = this.dataset.permission;
            const roleId = this.dataset.role;
            const knob = this.querySelector('span');

            fetch('/admin/permissions/' + permId + '/roles/' + roleId + '/toggle', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            })
            .then(r => r.json())
            .then(data => {
                if (data.error) { alert(data.error); return; }
                const granted = data.granted;
                this.className = 'toggle-perm-btn inline-flex items-center justify-center w-10 h-6 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-green-500 cursor-pointer hover:opacity-80 '
                    + (granted ? 'bg-green-600' : 'bg-gray-300');
                knob.className = 'inline-block w-4 h-4 rounded-full bg-white shadow transform transition-transform '
                    + (granted ? 'translate-x-2' : '-translate-x-2');
            })
            .catch(() => alert('Failed to update permission.'));
        });
    });
</script>
@endpush
