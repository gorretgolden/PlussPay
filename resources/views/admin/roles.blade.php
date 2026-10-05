@extends('layouts.app')

@section('title', 'Roles & Permissions')

@php
    function roleDisplayName($slug) {
        $map = ['superadmin' => 'Super Admin'];
        return $map[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }
@endphp

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Roles & Permissions</h1>
        <p class="text-gray-600">Manage roles and assign permissions</p>
    </div>
</div>

{{-- Create Role --}}
<div class="bg-white rounded-lg shadow p-6 mb-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-4">Add New Role</h2>
    <form method="POST" action="{{ route('admin.roles.store') }}" class="flex gap-3 items-end">
        @csrf
        <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Role Name</label>
            <input type="text" name="name" required placeholder="e.g. Manager, Supervisor"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
        </div>
        <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-lg hover:bg-green-700 text-sm font-medium">
            Create Role
        </button>
    </form>
</div>

{{-- Role Cards --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    @foreach($roles as $role)
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <h3 class="text-lg font-semibold text-gray-900">{{ roleDisplayName($role->name) }}</h3>
                <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">{{ $role->permissions->count() }} perms</span>
                <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-600">{{ $role->users_count }} user(s)</span>
            </div>
            @if($role->name !== 'superadmin')
            <form method="POST" action="{{ route('admin.roles.destroy', $role) }}" onsubmit="return confirm('Delete this role? Users assigned to it must be reassigned first.')">
                @csrf
                @method('DELETE')
                <button type="submit" class="text-red-600 hover:text-red-800 text-sm">Delete</button>
            </form>
            @endif
        </div>
        <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="role-perm-form">
            @csrf
            @method('PUT')
            <div class="px-6 pt-4 flex items-center justify-between">
                <span class="text-xs text-gray-500"><span class="perm-selected">0</span> of {{ $permissions->flatten()->count() }} selected</span>
                <div class="flex gap-4 text-sm">
                    <button type="button" data-check="true" class="text-green-800 hover:underline font-medium">Select all</button>
                    <button type="button" data-check="false" class="text-red-600 hover:underline font-medium">Remove all</button>
                </div>
            </div>
            <div class="p-6 max-h-96 overflow-y-auto space-y-4">
                @foreach($permissions as $group => $perms)
                <div>
                    <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{{ ucwords(str_replace('-', ' ', $group)) }}</h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-1">
                        @foreach($perms as $permission)
                        <label class="flex items-center gap-2 text-sm py-1">
                            <input type="checkbox" name="permissions[]" value="{{ $permission->name }}"
                                {{ $role->hasPermissionTo($permission->name) ? 'checked' : '' }}
                                class="rounded border-gray-300 text-green-700 focus:ring-green-600">
                            <span class="text-gray-700">{{ $permission->name }}</span>
                        </label>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
            <div class="px-6 py-4 border-t border-gray-200">
                <button type="submit" class="w-full bg-green-800 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700 font-medium">
                    Save Permissions
                </button>
            </div>
        </form>
    </div>
    @endforeach
</div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('.role-perm-form').forEach(form => {
        const boxes = form.querySelectorAll('input[name="permissions[]"]');
        const counter = form.querySelector('.perm-selected');

        function refresh() {
            counter.textContent = form.querySelectorAll('input[name="permissions[]"]:checked').length;
        }
        form.querySelectorAll('[data-check]').forEach(btn => btn.addEventListener('click', () => {
            const check = btn.dataset.check === 'true';
            boxes.forEach(b => b.checked = check);
            refresh();
        }));
        boxes.forEach(b => b.addEventListener('change', refresh));
        refresh();
    });
</script>
@endpush
