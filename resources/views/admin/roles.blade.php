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
<div class="grid grid-cols-1 lg:grid-cols-[17rem_minmax(0,1fr)] gap-6 items-start">
    <aside class="bg-white border border-gray-200 rounded-xl p-4">
        <label class="block text-sm font-semibold text-gray-700 mb-3">Find a role<input id="roleSearch" type="search" placeholder="Search roles by name" class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 font-normal"></label>
        <nav aria-label="Roles" class="space-y-2">
            @foreach($roles as $role)
            <div data-role-name="{{ strtolower($role->name.' '.\App\Http\Controllers\RolePermissionController::displayName($role->name)) }}" class="rounded-lg border {{ $selectedRole?->id === $role->id ? 'border-green-700 bg-green-50' : 'border-transparent hover:bg-gray-50' }} p-3">
                <a href="{{ route('admin.roles', ['role' => $role->id]) }}" @if($selectedRole?->id === $role->id) aria-current="page" @endif class="role-switch block text-sm font-semibold text-gray-900">{{ \App\Http\Controllers\RolePermissionController::displayName($role->name) }}</a>
                <div class="flex justify-between gap-2 text-xs mt-2 text-gray-500"><span>{{ $role->name === 'superadmin' ? 'Full access · Locked' : $role->permissions->count().' permissions' }}</span>@can('view-users')<a class="underline" href="{{ url('/users').'?role='.urlencode($role->name) }}">{{ $role->users_count }} {{ Str::plural('user', $role->users_count) }}</a>@else<span>{{ $role->users_count }} {{ Str::plural('user', $role->users_count) }}</span>@endcan</div>
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
            <details class="relative shrink-0"><summary aria-label="Role actions" class="cursor-pointer px-3 py-2 text-xl rounded-lg border">⋮</summary>
                <div class="absolute right-0 top-full mt-2 w-72 max-w-[80vw] bg-white border shadow-lg rounded-lg p-4 z-20 text-sm space-y-4">
                    @can('create-role')
                    <form method="POST" action="{{ route('admin.roles.duplicate', $selectedRole) }}" class="space-y-2">@csrf<label class="block">Copy name<input name="name" required maxlength="50" value="{{ $selectedRole->name }} copy" class="w-full border rounded px-2 py-2 mt-1"></label><button class="text-green-800 font-semibold min-h-[44px]">Duplicate role</button></form>
                    @endcan
                    @can('delete-role')
                    @if(!array_key_exists($selectedRole->name, \App\Support\PermissionCatalog::DESCRIPTIONS))
                    <form method="POST" action="{{ route('admin.roles.destroy', $selectedRole) }}" class="border-t pt-3 space-y-2">@csrf @method('DELETE')
                        @if($selectedRole->users_count)<p class="text-gray-500">Reassign {{ $selectedRole->users_count }} {{ Str::plural('user', $selectedRole->users_count) }} before deletion.</p>@else<label class="block text-red-800">Type {{ $selectedRole->name }} to delete<input name="confirm_name" required autocomplete="off" class="w-full border rounded px-2 py-2 mt-1"></label><button class="min-h-[44px] text-red-700 font-semibold">Delete role permanently</button>@endif
                    </form>
                    @endif
                    @endcan
                </div>
            </details>
            @endif
        </div>
        @if($super)
        <div class="p-6 bg-green-50 text-green-950 rounded-b-xl"><h3 class="font-semibold">Locked · Full access</h3><p class="text-sm mt-2">Super Admin has full access to every current and future permission. This role cannot be edited, duplicated or deleted.</p></div>
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
    document.getElementById('roleSearch').addEventListener('input', e => {
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
        form.querySelector('.perm-filter')?.addEventListener('input', e => {
            const term = e.target.value.toLowerCase().trim();
            form.querySelectorAll('.perm-item').forEach(item => item.classList.toggle('hidden', !item.dataset.name.toLowerCase().includes(term)));
            const groups = [...form.querySelectorAll('.perm-group')];
            groups.forEach(group => { group.classList.toggle('hidden', ![...group.querySelectorAll('.perm-item')].some(i => !i.classList.contains('hidden'))); if (term) group.open = true; });
            form.querySelector('.perm-no-match').classList.toggle('hidden', groups.some(g => !g.classList.contains('hidden')));
        });
        form.addEventListener('submit', async e => {
            e.preventDefault();
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
    window.addEventListener('beforeunload', e => { if (unsaved) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
@endpush
