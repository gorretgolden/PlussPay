@extends('layouts.app')

@section('title', 'Roles & Permissions')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Roles & Permissions</h1>
        <p class="text-gray-600">{{ $roles->count() }} role(s) &middot; {{ $permissions->flatten()->count() }} permission(s) available</p>
    </div>
    <button type="button" id="openAddRole" class="bg-green-800 text-white px-5 py-2 rounded-lg hover:bg-green-700 text-sm font-medium flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Add Role
    </button>
</div>

@if($errors->any())
<div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700">
    @foreach($errors->all() as $error)
    <p>{{ $error }}</p>
    @endforeach
</div>
@endif

{{-- Role Search --}}
<div class="mb-6 bg-white rounded-lg shadow p-4">
    <div class="relative">
        <svg class="w-5 h-5 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        <input type="text" id="roleSearch" autocomplete="off" placeholder="Search roles by name... e.g. superadmin"
            class="w-full pl-10 pr-28 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
        <span id="roleSearchCount" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-500"></span>
    </div>
</div>

{{-- Role Cards --}}
<div id="roleCards" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    @foreach($roles as $role)
    <div class="bg-white rounded-lg shadow" data-role-name="{{ strtolower($role->name.' '.\App\Http\Controllers\RolePermissionController::displayName($role->name)) }}">
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

<p id="roleNoMatch" class="hidden bg-white rounded-lg shadow p-8 text-center text-sm text-gray-500">
    No role called "<span id="roleNoMatchTerm"></span>".
</p>

{{-- Add Role Modal --}}
<div id="addRoleModal" class="fixed inset-0 z-50 {{ $errors->any() ? '' : 'hidden' }}">
    <div class="absolute inset-0 bg-black bg-opacity-50 modal-backdrop"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl flex flex-col max-h-[90vh]">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-start">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Add New Role</h3>
                    <p class="text-sm text-gray-500">Name it and tick the permissions it should have, in one step.</p>
                </div>
                <button type="button" class="modal-cancel text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
            </div>
            <form method="POST" action="{{ route('admin.roles.store') }}" class="role-perm-form flex flex-col min-h-0 flex-1">
                @csrf
                <div class="px-6 pt-5">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role Name</label>
                    <input type="text" name="name" value="{{ old('name') }}" required placeholder="e.g. Manager, Supervisor"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <div class="flex-1 min-h-0 flex flex-col">
                    @include('admin.partials._perm-picker', [
                        'groups' => $permissions,
                        'checked' => old('permissions', []),
                        'listClass' => 'flex-1 min-h-0 overflow-y-auto',
                    ])
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button type="button" class="modal-cancel px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                    <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-lg hover:bg-green-700 text-sm font-medium">
                        Create Role
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    const addRoleModal = document.getElementById('addRoleModal');

    document.getElementById('openAddRole').addEventListener('click', () => {
        addRoleModal.classList.remove('hidden');
        addRoleModal.querySelector('input[name="name"]').focus();
    });

    addRoleModal.querySelectorAll('.modal-cancel').forEach(btn => btn.addEventListener('click', () => addRoleModal.classList.add('hidden')));
    addRoleModal.querySelector('.modal-backdrop').addEventListener('click', () => addRoleModal.classList.add('hidden'));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') addRoleModal.classList.add('hidden'); });

    const roleSearch = document.getElementById('roleSearch');
    const roleCards = [...document.querySelectorAll('#roleCards [data-role-name]')];
    const roleNoMatch = document.getElementById('roleNoMatch');

    roleSearch.addEventListener('input', () => {
        const q = roleSearch.value.trim().toLowerCase();
        let shown = 0;

        roleCards.forEach(card => {
            const hit = !q || card.dataset.roleName.includes(q);
            card.classList.toggle('hidden', !hit);
            if (hit) shown++;
        });

        document.getElementById('roleNoMatchTerm').textContent = roleSearch.value.trim();
        roleNoMatch.classList.toggle('hidden', shown !== 0);
        document.getElementById('roleSearchCount').textContent = q ? shown + ' of ' + roleCards.length + ' roles' : '';
    });

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
