@extends('layouts.app')
@section('title', 'Roles & Permissions')
@section('content')
<div class="flex flex-wrap justify-between items-start gap-4 mb-6">
    <div><h1 class="text-2xl font-bold text-gray-900">Roles & Permissions</h1><p class="text-gray-500 mt-1">{{ $roles->count() }} {{ Str::plural('role', $roles->count()) }} · Access that matches each person's work.</p></div>
    <div class="flex gap-3 items-center">
        <a href="{{ route('admin.permissions') }}" class="text-green-800 text-sm underline">Permission catalog</a>
        @can('create-role')<button type="button" id="openAddRole" class="bg-green-800 text-white rounded-lg px-4 py-3 text-sm font-medium">Add Role</button>@endcan
    </div>
</div>
@if($errors->any())
<div role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 text-red-800 p-4 text-sm">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif
@if($permissions->isEmpty())
<div class="bg-white rounded-xl border p-8 text-center mb-6">
    <h2 class="font-semibold text-gray-900">No permissions have been initialized</h2>
    <p class="text-gray-500 text-sm mt-2">Seed the default catalog to get started. Existing role customizations will be preserved.</p>
    @can('seed-permissions')<form method="POST" action="{{ route('admin.permissions.seed') }}" class="mt-4">@csrf<button class="bg-green-800 text-white px-4 py-3 rounded-lg">Seed default permissions</button></form>@else<p class="text-sm mt-3">Ask a Super Admin to initialize permissions.</p>@endcan
</div>
@endif
<div class="grid grid-cols-1 lg:grid-cols-[17rem_minmax(0,1fr)] gap-6">
    <aside class="bg-white border border-gray-200 rounded-xl p-4">
        @if($roles->count() > 8)
        <label class="hidden lg:block text-sm font-semibold text-gray-700 mb-3">Find a role<input id="roleSearch" type="search" placeholder="Search roles by name" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 font-normal"></label>
        @endif
        <label class="lg:hidden block text-sm font-semibold text-gray-700 mb-3">Role
            <select id="roleSelect" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 font-normal">
                @foreach($roles as $role)
                <option value="{{ route('admin.roles', ['role' => $role->id]) }}" @selected($selectedRole?->id === $role->id)>{{ \App\Http\Controllers\RolePermissionController::displayName($role->name) }}</option>
                @endforeach
            </select>
        </label>
        <nav aria-label="Roles" class="hidden lg:block space-y-2">
            @foreach($roles as $role)
            <div data-role-name="{{ strtolower($role->name.' '.\App\Http\Controllers\RolePermissionController::displayName($role->name)) }}" class="rounded-lg border {{ $selectedRole?->id === $role->id ? 'border-green-700 bg-green-50' : 'border-transparent hover:bg-gray-50' }} p-3">
                <a href="{{ route('admin.roles', ['role' => $role->id]) }}" @if($selectedRole?->id === $role->id) aria-current="page" @endif class="role-switch block text-sm font-semibold text-gray-900">{{ \App\Http\Controllers\RolePermissionController::displayName($role->name) }}</a>
                <div class="flex justify-between gap-2 text-xs mt-2 text-gray-500"><span>{{ $role->name === 'superadmin' ? 'Full access · Locked' : $role->permissions->count().' '.Str::plural('permission', $role->permissions->count()) }}</span>@can('view-users')<a class="underline" href="{{ url('/users').'?role='.urlencode($role->name) }}">{{ $role->users_count }} {{ Str::plural('user', $role->users_count) }}</a>@else<span>{{ $role->users_count }} {{ Str::plural('user', $role->users_count) }}</span>@endcan</div>
            </div>
            @endforeach
        </nav>
        <p id="roleNoMatch" class="hidden text-sm text-gray-500 p-3">No matching roles.</p>
    </aside>
    @if($selectedRole)
    @php
        $super = $selectedRole->name === 'superadmin';
        $editable = !$super && !in_array($selectedRole->name, ['public', 'customer']) && auth()->user()->can('edit-role') && auth()->user()->can('assign-permissions') && $selectedRole->permissions->every(fn($p) => \App\Support\PermissionCatalog::mayDelegate(auth()->user(), $p->name));
    @endphp
    <section class="bg-white border border-gray-200 rounded-xl min-w-0">
        <div class="p-5 sm:p-6 border-b flex justify-between items-start gap-3">
            <div><h2 class="text-xl font-semibold text-gray-900">{{ \App\Http\Controllers\RolePermissionController::displayName($selectedRole->name) }}</h2><p class="text-sm text-gray-500 mt-1">{{ $selectedRole->description ?: 'Define what this team can see and do.' }}</p></div>
            @if(!$super && !in_array($selectedRole->name, ['public', 'customer']))
            @php
                $customRole = !array_key_exists($selectedRole->name, \App\Support\PermissionCatalog::DESCRIPTIONS);
            @endphp
            <div class="flex shrink-0 items-center gap-2">
                @can('edit-role')
                @if($customRole)
                <details class="relative">
                    <summary aria-label="Role actions" class="flex min-h-[44px] cursor-pointer list-none items-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 [&::-webkit-details-marker]:hidden">Actions ⋮</summary>
                    <div class="absolute right-0 top-full z-20 mt-2 w-72 max-w-[80vw] rounded-lg border bg-white p-4 text-sm shadow-lg">
                        <form method="POST" action="{{ route('admin.roles.rename', $selectedRole) }}" class="space-y-2" data-confirm-title="Rename this role?" data-confirm="The {{ $selectedRole->users_count }} {{ Str::plural('user', $selectedRole->users_count) }} on this role keep their access, only the name changes." data-confirm-ok="Rename role">@csrf @method('PATCH')
                            <label class="block">New name<input name="name" required maxlength="50" value="{{ \App\Http\Controllers\RolePermissionController::displayName($selectedRole->name) }}" class="w-full border rounded px-2 py-2 mt-1"></label>
                            <button class="min-h-[44px] text-green-800 font-semibold">Rename role</button>
                        </form>
                    </div>
                </details>
                @endif
                @endcan
                @can('delete-role')
                @if($customRole)
                <details class="relative">
                    <summary aria-label="Delete this role" title="Delete this role" class="flex min-h-[44px] min-w-[44px] cursor-pointer list-none items-center justify-center rounded-lg border border-red-300 px-3 py-2 text-red-700 hover:bg-red-50 [&::-webkit-details-marker]:hidden">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a1 1 0 011-1h4a1 1 0 011 1v2m-9 0l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13M10 11v6M14 11v6"/></svg>
                    </summary>
                    <div class="absolute right-0 top-full z-20 mt-2 w-80 max-w-[85vw] rounded-lg border bg-white p-4 text-sm shadow-lg">
                        @if($selectedRole->users_count)
                            <p class="font-medium text-gray-900">{{ $selectedRole->users_count }} {{ Str::plural('user', $selectedRole->users_count) }} still {{ $selectedRole->users_count === 1 ? 'has' : 'have' }} this role.</p>
                            <p class="mt-1 text-gray-600">Move {{ $selectedRole->users_count === 1 ? 'that person' : 'them' }} to another role before deleting.</p>
                            @can('view-users')<a href="{{ url('/users').'?role='.urlencode($selectedRole->name) }}" class="mt-3 inline-block min-h-[44px] font-semibold text-green-800 underline">View these users</a>@endcan
                        @else
                            <form method="POST" action="{{ route('admin.roles.destroy', $selectedRole) }}" class="space-y-2">@csrf @method('DELETE')
                                <p class="font-medium text-gray-900">Delete {{ \App\Http\Controllers\RolePermissionController::displayName($selectedRole->name) }}?</p>
                                <p class="text-gray-600">Nobody has this role. This cannot be undone.</p>
                                <label class="block text-red-800">Type <strong>{{ $selectedRole->name }}</strong> to confirm<input name="confirm_name" required autocomplete="off" class="mt-1 w-full rounded border px-2 py-2"></label>
                                <button class="min-h-[44px] font-semibold text-red-700">Delete role permanently</button>
                            </form>
                        @endif
                    </div>
                </details>
                @endif
                @endcan
            </div>
            @endif
        </div>
        @if($super)
        <div class="p-6 bg-green-50 text-green-950 rounded-b-xl"><h3 class="font-semibold">Locked · Full access</h3><p class="text-sm mt-2">Super Admin has full access to every current and future permission. This role cannot be edited or deleted.</p></div>
        @else
        <form method="POST" action="{{ route('admin.roles.update', $selectedRole) }}" class="role-perm-form p-5 sm:p-6 space-y-5" data-users="{{ $selectedRole->users_count }}" data-edit="1" data-initial="{{ json_encode($selectedRole->permissions->pluck('name')->all()) }}" data-description="{{ $selectedRole->description }}">@csrf @method('PUT')
            <label class="block text-sm font-medium text-gray-700">Description<textarea name="description" maxlength="500" rows="2" @disabled(!$editable) class="w-full mt-1 border rounded-lg px-3 py-2 font-normal">{{ old('description', $selectedRole->description) }}</textarea></label>
            @include('admin.partials._perm-picker', ['groups' => $permissions, 'checked' => old('permissions', $selectedRole->permissions->pluck('name')->all()), 'locked' => !$editable])
            @if($editable)
            <div class="border-t pt-4 space-y-3"><div class="perm-diff text-sm text-gray-600 space-y-1" aria-live="polite"><p>No unsaved changes.</p></div><div class="flex justify-end"><button type="submit" disabled class="save-role disabled:opacity-40 bg-green-800 text-white rounded-lg px-5 py-3 text-sm font-semibold">Save changes</button></div></div>
            @else<p class="text-sm text-gray-500">Read only. You cannot edit this role or delegate permissions you do not hold.</p>@endif
        </form>
        @endif
    </section>
    @else<p class="p-6 text-gray-500">No roles yet. Initialize the catalog or create a role.</p>@endif
</div>
@can('create-role')
<div id="addRoleModal" class="fixed inset-0 z-50 {{ $errors->has('name') ? '' : 'hidden' }}" role="dialog" aria-modal="true" aria-labelledby="addRoleTitle">
    <div class="absolute inset-0 bg-black/50 modal-backdrop"></div>
    <div class="relative bg-white rounded-xl shadow-xl mx-auto my-6 w-[calc(100%-2rem)] max-w-3xl max-h-[90dvh] overflow-y-auto p-6">
        <div class="flex justify-between items-center mb-5"><h2 id="addRoleTitle" class="text-xl font-semibold">Add Role</h2><button type="button" class="modal-cancel min-h-[44px] px-3" aria-label="Close add role">Close</button></div>
        <form method="POST" action="{{ route('admin.roles.store') }}" class="role-perm-form space-y-4" data-users="0">@csrf
            <label class="block text-sm font-medium">Role name<input name="name" required maxlength="50" value="{{ old('name') }}" placeholder="Manager" class="w-full border rounded-lg px-3 py-2 mt-1"></label>
            <label class="block text-sm font-medium">Description<textarea name="description" rows="2" maxlength="500" class="w-full border rounded-lg px-3 py-2 mt-1">{{ old('description') }}</textarea></label>
            @can('assign-permissions')@include('admin.partials._perm-picker', ['groups' => $permissions, 'checked' => old('permissions', []), 'locked' => false])@endcan
            <button class="bg-green-800 text-white rounded-lg px-5 py-3 font-semibold text-sm">Create role</button>
        </form>
    </div>
</div>
@endcan
@endsection
@push('scripts')
<script>
(() => {
    const confirmAction = options => typeof window.askConfirm === 'function' ? window.askConfirm(options) : Promise.resolve(window.confirm(options.message));
    const modal = document.getElementById('addRoleModal');
    const opener = document.getElementById('openAddRole');
    const closeModal = () => { modal?.classList.add('hidden'); opener?.focus(); };
    opener?.addEventListener('click', () => { modal.classList.remove('hidden'); modal.querySelector('[name="name"]').focus(); });
    modal?.querySelectorAll('.modal-cancel, .modal-backdrop').forEach(b => b.addEventListener('click', closeModal));
    document.addEventListener('keydown', e => {
        if (!modal || modal.classList.contains('hidden')) return;
        if (e.key === 'Escape') closeModal();
        if (e.key === 'Tab') {
            const nodes = [...modal.querySelectorAll('button,input,textarea,summary')].filter(n => !n.disabled && n.getClientRects().length);
            if (e.shiftKey && document.activeElement === nodes[0]) { e.preventDefault(); nodes.at(-1).focus(); }
            else if (!e.shiftKey && document.activeElement === nodes.at(-1)) { e.preventDefault(); nodes[0].focus(); }
        }
    });
    document.getElementById('roleSearch')?.addEventListener('input', e => {
        const cards = [...document.querySelectorAll('[data-role-name]')];
        cards.forEach(c => c.classList.toggle('hidden', !c.dataset.roleName.includes(e.target.value.trim().toLowerCase())));
        document.getElementById('roleNoMatch').classList.toggle('hidden', cards.some(c => !c.classList.contains('hidden')));
    });
    let unsaved = false;
    document.querySelectorAll('.role-perm-form').forEach(form => {
        const boxes = [...form.querySelectorAll('[name="permissions[]"]')];
        const baseline = new Set(form.dataset.edit ? JSON.parse(form.dataset.initial) : []);
        const description = form.querySelector('[name="description"]');
        const originalDescription = form.dataset.edit ? form.dataset.description : description?.defaultValue;
        const changes = () => ({added: boxes.filter(b => b.checked && !baseline.has(b.value)), removed: boxes.filter(b => !b.checked && baseline.has(b.value))});
        const refresh = () => {
            const {added, removed} = changes();
            const dirty = added.length + removed.length > 0 || description?.value !== originalDescription;
            const count = form.querySelector('.perm-selected'); if (count) count.textContent = boxes.filter(b => b.checked).length;
            form.querySelectorAll('.perm-group').forEach(group => {
                const control = group.querySelector('.perm-group-check'); if (!control) return;
                const available = [...group.querySelectorAll('[name="permissions[]"]:not(:disabled)')];
                control.checked = available.length > 0 && available.every(b => b.checked);
                control.indeterminate = available.some(b => b.checked) && !control.checked;
            });
            if (form.dataset.edit) {
                unsaved = dirty;
                const save = form.querySelector('.save-role'); if (save) save.disabled = !dirty;
                const diff = form.querySelector('.perm-diff');
                if (diff) {
                    diff.replaceChildren();
                    for (const text of dirty ? ['Added: ' + (added.map(b => b.dataset.label).join(', ') || 'None'), 'Removed: ' + (removed.map(b => b.dataset.label).join(', ') || 'None')] : ['No unsaved changes.']) {
                        const p = document.createElement('p'); p.textContent = text; diff.append(p);
                    }
                }
            }
        };
        form.addEventListener('change', refresh); description?.addEventListener('input', refresh);
        form.querySelectorAll('[data-check]').forEach(button => button.addEventListener('click', () => {
            boxes.filter(b => !b.disabled && !b.closest('.perm-item').classList.contains('hidden')).forEach(b => b.checked = button.dataset.check === 'true'); refresh();
        }));
        form.querySelectorAll('.perm-group-check').forEach(control => control.addEventListener('change', () => {
            control.closest('.perm-group').querySelectorAll('[name="permissions[]"]:not(:disabled)').forEach(b => b.checked = control.checked); refresh();
        }));
        form.addEventListener('submit', async e => {
            e.preventDefault();
            // An implicit submission (Enter) fired while the permission search box is focused is
            // a search, not a save. A button click always carries a submitter, so it still saves.
            if (!e.submitter && e.target.querySelector('.perm-filter') === document.activeElement) return;
            const {added, removed} = changes();
            const plural = (n, word) => `${n} ${word}${n === 1 ? '' : 's'}`;
            const sensitive = added.filter(b => b.dataset.sensitive === '1').map(b => b.dataset.label);
            const summary = added.length + removed.length ? `Added ${plural(added.length, 'permission')}, removed ${plural(removed.length, 'permission')}.` : 'Description changed.';
            const message = `${plural(Number(form.dataset.users), 'user')} affected. ${summary}` + (sensitive.length ? '\nSensitive additions: ' + sensitive.join(', ') : '');
            if (await confirmAction({title: 'Save role access?', message, confirmText: 'Save changes', danger: sensitive.length > 0})) { unsaved = false; form.submit(); }
        });
        refresh();
    });
    document.querySelectorAll('.role-switch').forEach(link => link.addEventListener('click', async e => {
        if (!unsaved) return; e.preventDefault();
        if (await confirmAction({title: 'Discard changes?', message: 'Your unsaved role changes will be lost.', confirmText: 'Discard', danger: true})) { unsaved = false; window.location.href = link.href; }
    }));
    const roleSelect = document.getElementById('roleSelect');
    const openedRole = roleSelect?.value;
    roleSelect?.addEventListener('change', async e => {
        const url = e.target.value;
        if (unsaved && !(await confirmAction({title: 'Discard changes?', message: 'Your unsaved role changes will be lost.', confirmText: 'Discard', danger: true}))) {
            e.target.value = openedRole;
            return;
        }
        unsaved = false;
        window.location.href = url;
    });
    window.addEventListener('beforeunload', e => { if (unsaved) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
@endpush
