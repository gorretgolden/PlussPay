@extends('layouts.app')

@section('title', 'Permission Catalog')

@section('content')
<style>
    /* The shared validation banner reserves a 45px slot for long forms; on a single-line add
       form that slot would push the table down before anything is wrong, so it stays compact. */
    .compact-validation [data-error-summary] { margin: 0; min-height: 0; padding: 0; border: 0; }
    .compact-validation [data-error-summary][style*="hidden"] { display: none; }
    .compact-validation [data-error-summary][style*="visible"] { margin-bottom: .5rem; padding: .5rem; border: 1px solid #fecaca; }
</style>
@php
    $builtInNames = \App\Support\PermissionCatalog::names();
    $canManage = auth()->user()->can('assign-permissions');
    $editingBuiltIn = $editing && in_array($editing->name, $builtInNames, true);
@endphp
<div class="max-w-6xl mx-auto">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500"><a href="{{ route('admin.roles') }}" class="underline hover:text-gray-700">Roles &amp; Permissions</a> / All Permissions</p>
            <h1 class="text-2xl font-bold text-gray-900">Permission Catalog</h1>
            <p class="mt-1 text-sm text-gray-600">Every ability a role can be given, grouped by the work it controls.</p>
        </div>
        <a href="{{ route('admin.roles') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Manage roles</a>
    </div>

    @if($errors->any())
    <div role="alert" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
    @endif

    @if($abilities === 0)
    <div class="rounded-lg bg-white p-8 text-center shadow">
        <h2 class="font-semibold text-gray-900">No permissions have been initialized</h2>
        <p class="mt-2 text-sm text-gray-600">Initialize the default catalog without resetting existing role customizations.</p>
        @can('seed-permissions')
        <form method="POST" action="{{ route('admin.permissions.seed') }}" class="mt-4">@csrf<button class="rounded-md bg-green-800 px-4 py-2 text-sm font-medium text-white">Seed default permissions</button></form>
        @else
        <p class="mt-4 text-sm text-gray-500">Ask a Super Admin to seed default permissions.</p>
        @endcan
    </div>
    @else
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach([
            ['Abilities', $abilities],
            ['Built in', $builtIn],
            ['Custom', $custom],
            ['On no role', $unused],
        ] as [$label, $value])
        <div class="rounded-lg bg-white p-4 shadow">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($value) }}</p>
        </div>
        @endforeach
    </div>

    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-950">
        Built-in abilities are wired into the application, so you can edit their description but not rename or delete them. A custom ability you add here can be renamed and deleted until a role or person holds it.
    </div>

    @if($canManage)
    <div class="mb-6 rounded-lg bg-white p-4 shadow">
        <form method="POST" action="{{ route('admin.permissions.store') }}" data-validate class="compact-validation flex flex-col gap-2 sm:flex-row sm:items-start">
            @csrf
            <div class="min-w-0 sm:w-56" data-field>
                <label for="new-permission-name" class="sr-only">Ability name</label>
                <input type="text" id="new-permission-name" name="name" value="{{ old('name') }}" maxlength="60" required
                    placeholder="Ability, e.g. approve-payout"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-600">
                @error('name')<p data-field-error="name" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div class="min-w-0 flex-1" data-field>
                <label for="new-permission-description" class="sr-only">What the ability allows</label>
                <input type="text" id="new-permission-description" name="description" value="{{ old('description') }}" maxlength="255"
                    placeholder="What this ability allows"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-600">
                @error('description')<p data-field-error="description" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="shrink-0 rounded-md bg-green-800 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Add</button>
        </form>
        <p class="mt-2 text-xs text-gray-500">Spaces become hyphens. The slug is the ability code used in the application.</p>
    </div>
    @endif

    @if($editing)
    <div class="mb-6 rounded-lg border border-gray-200 border-t-4 border-t-green-700 bg-white p-5 shadow">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Edit {{ \App\Support\PermissionCatalog::label($editing->name) }}</h2>
                <p class="mt-1 text-sm text-gray-600">
                    <span class="font-mono text-xs text-gray-500">{{ $editing->name }}</span>
                    @if($editingBuiltIn) · Built-in abilities keep their slug. @else · Custom ability. @endif
                </p>
            </div>
            <a href="{{ route('admin.permissions', array_filter(['q' => $search ?: null])) }}" class="rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</a>
        </div>
        <form method="POST" action="{{ route('admin.permissions.update', $editing) }}" data-validate class="mt-4 grid gap-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto] sm:items-start">@csrf @method('PUT')
            <div data-field>
                <label for="edit-permission-name" class="block text-sm font-medium text-gray-700">Ability slug</label>
                <input type="text" id="edit-permission-name" name="name" value="{{ old('name', $editing->name) }}" maxlength="60" required @readonly($editingBuiltIn)
                    class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm font-mono {{ $editingBuiltIn ? 'bg-gray-100 text-gray-500' : '' }}">
                @error('name')<p data-field-error="name" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <div data-field>
                <label for="edit-permission-description" class="block text-sm font-medium text-gray-700">What it allows</label>
                <input type="text" id="edit-permission-description" name="description" value="{{ old('description', $editing->description) }}" maxlength="255"
                    placeholder="What this ability allows"
                    class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                @error('description')<p data-field-error="description" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="mt-0 rounded-md bg-green-800 px-4 py-2 text-sm font-medium text-white hover:bg-green-700 sm:mt-6">Save</button>
        </form>
    </div>
    @endif

    <div class="rounded-lg bg-white shadow">
        <div class="flex flex-col gap-3 border-b border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <form method="GET" action="{{ route('admin.permissions') }}" class="flex flex-1 items-center gap-2 sm:max-w-md">
                <label for="permission-search" class="sr-only">Search permissions</label>
                <input type="search" id="permission-search" name="q" value="{{ $search }}" placeholder="Search by name or description"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm">
                <button type="submit" class="shrink-0 rounded-md border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Search</button>
                @if($search !== '')<a href="{{ route('admin.permissions') }}" class="shrink-0 text-sm text-green-800 underline">Clear</a>@endif
            </form>
            <p class="text-sm text-gray-500">
                @if($search !== ''){{ $permissions->total() }} {{ Str::plural('match', $permissions->total()) }} for "{{ $search }}"@else{{ $permissions->total() }} {{ Str::plural('ability', $permissions->total()) }}@endif
            </p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-600">Ability</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-600 sm:table-cell">Module</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-600 lg:table-cell">What it allows</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-600">Roles</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($permissions as $permission)
                    @php
                        $builtInAbility = in_array($permission->name, $builtInNames, true);
                        $module = \App\Support\PermissionCatalog::module($permission->name);
                        $editUrl = route('admin.permissions', array_filter([
                            'edit' => $permission->id,
                            'q' => $search ?: null,
                            'page' => $permissions->currentPage() > 1 ? $permissions->currentPage() : null,
                        ]));
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-medium text-gray-900">{{ \App\Support\PermissionCatalog::label($permission->name) }}</p>
                                @if($builtInAbility)
                                <span class="hidden rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 sm:inline-flex">Built in</span>
                                @else
                                <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Custom</span>
                                @endif
                            </div>
                            <p class="font-mono text-xs text-gray-500">{{ $permission->name }}</p>
                        </td>
                        <td class="hidden px-4 py-3 text-sm text-gray-700 sm:table-cell">{{ $module === 'Other' ? 'Custom' : $module }}</td>
                        <td class="hidden px-4 py-3 text-sm text-gray-700 lg:table-cell">{{ $permission->description }}</td>
                        <td class="px-4 py-3 text-right text-sm tabular-nums text-gray-900">{{ $permission->roles_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if($canManage)
                                <a href="{{ $editUrl }}" title="Edit {{ \App\Support\PermissionCatalog::label($permission->name) }}" aria-label="Edit {{ \App\Support\PermissionCatalog::label($permission->name) }}" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-700 hover:bg-green-50 hover:text-green-800 focus-visible:ring-2 focus-visible:ring-green-600">
                                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </a>
                                @endif
                                @if($builtInAbility)
                                <span title="A built-in ability is checked by the application and cannot be deleted." class="inline-flex h-8 w-8 cursor-not-allowed items-center justify-center rounded-md text-gray-300">
                                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4"/></svg>
                                </span>
                                @elseif($canManage && $permission->roles_count === 0)
                                <form method="POST" action="{{ route('admin.permissions.destroy', $permission) }}"
                                    data-confirm-title="Delete {{ \App\Support\PermissionCatalog::label($permission->name) }}?"
                                    data-confirm="No role holds it, so the custom ability will be gone for good."
                                    data-confirm-ok="Delete" data-confirm-danger="1">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Delete {{ \App\Support\PermissionCatalog::label($permission->name) }}" aria-label="Delete {{ \App\Support\PermissionCatalog::label($permission->name) }}" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-700 hover:bg-red-50 hover:text-red-800 focus-visible:ring-2 focus-visible:ring-green-600">
                                        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                                @elseif($canManage)
                                <span title="{{ \App\Support\PermissionCatalog::label($permission->name) }} is held by {{ $permission->roles_count }} {{ Str::plural('role', $permission->roles_count) }}. Take it off every role before deleting it." class="inline-flex h-8 w-8 cursor-not-allowed items-center justify-center rounded-md text-gray-300">
                                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4"/></svg>
                                </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="px-6 py-8 text-center text-gray-600">No permissions match "{{ $search }}". Try another word.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($permissions->hasPages())
        <div class="border-t border-gray-200 px-4 py-4">{{ $permissions->links() }}</div>
        @endif
    </div>
    @endif
</div>
@endsection
