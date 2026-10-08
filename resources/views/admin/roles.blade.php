@extends('layouts.app')

@section('title', 'Roles & Permissions')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Roles & Permissions</h1>
        <p class="text-gray-600">{{ $roles->count() }} role(s) &middot; {{ $permissions->flatten()->count() }} permission(s) available</p>
    </div>
</div>

{{-- Create Role --}}
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-800">Add New Role</h2>
        <p class="text-sm text-gray-500">Name it and tick the permissions it should have, in one step.</p>
    </div>
    <form method="POST" action="{{ route('admin.roles.store') }}" class="role-perm-form pb-6">
        @csrf
        <div class="px-6 pt-5 max-w-md">
            <label class="block text-sm font-medium text-gray-700 mb-1">Role Name</label>
            <input type="text" name="name" value="{{ old('name') }}" required placeholder="e.g. Manager, Supervisor"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
            @error('name')
            <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
            @enderror
        </div>
        @include('admin.partials._perm-picker', ['groups' => $permissions, 'checked' => old('permissions', [])])
        <div class="px-6 pt-2">
            <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-lg hover:bg-green-700 text-sm font-medium">
                Create Role
            </button>
        </div>
    </form>
</div>

{{-- Role Cards --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    @foreach($roles as $role)
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <div class="flex items-center gap-3">
                <h3 class="text-lg font-semibold text-gray-900">{{ \App\Http\Controllers\RolePermissionController::displayName($role->name) }}</h3>
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
        <form method="POST" action="{{ route('admin.roles.update', $role) }}" class="role-perm-form pb-6">
            @csrf
            @method('PUT')
            @include('admin.partials._perm-picker', ['groups' => $permissions, 'checked' => $role->permissions->pluck('name')->all()])
            <div class="px-6 pt-2">
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
    document.querySelectorAll('.perm-picker').forEach(picker => {
        const boxes = picker.querySelectorAll('input[name="permissions[]"]');
        const items = [...picker.querySelectorAll('.perm-item')];
        const groups = [...picker.querySelectorAll('.perm-group')];
        const counter = picker.querySelector('.perm-selected');
        const filter = picker.querySelector('.perm-filter');
        const noMatch = picker.querySelector('.perm-no-match');

        function refresh() {
            counter.textContent = items.filter(i => i.querySelector('input').checked).length;
        }

        filter.addEventListener('input', () => {
            const q = filter.value.trim().toLowerCase();
            let visible = 0;

            items.forEach(item => {
                const hit = !q || item.dataset.name.toLowerCase().includes(q);
                item.classList.toggle('hidden', !hit);
                if (hit) visible++;
            });

            groups.forEach(group => {
                const any = [...group.querySelectorAll('.perm-item')].some(i => !i.classList.contains('hidden'));
                group.classList.toggle('hidden', !any);
            });

            noMatch.classList.toggle('hidden', visible !== 0);
        });

        picker.querySelectorAll('[data-check]').forEach(btn => btn.addEventListener('click', () => {
            const check = btn.dataset.check === 'true';
            items.filter(i => !i.classList.contains('hidden'))
                .forEach(i => i.querySelector('input').checked = check);
            refresh();
        }));

        boxes.forEach(b => b.addEventListener('change', refresh));
        refresh();
    });
</script>
@endpush
