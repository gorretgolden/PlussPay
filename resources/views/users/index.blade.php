@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="user-management" data-users-directory>
    <div class="mb-6 flex flex-wrap gap-4 justify-between items-center">
        <div><h1 class="text-2xl font-bold text-gray-900">User Management</h1><p class="text-sm text-gray-500 mt-1">People, desks and sign-in access.</p></div>
        <div class="flex gap-2">
            @can('export-users')<a data-users-export href="{{ route('export.users', request()->query()) }}" class="border border-gray-300 bg-white text-gray-700 px-4 py-2 rounded-md text-sm">Export Excel</a>@endcan
            @can('create-user')<a href="{{ route('users.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Add User</a>@endcan
        </div>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <a href="{{ route('users.index') }}" data-users-card class="block bg-white rounded-lg shadow p-4 border border-transparent hover:border-green-700">
            <p class="text-2xl font-bold text-gray-900">{{ $summary['total'] }}</p>
            <p class="text-sm text-gray-500 mt-1">Total users</p>
        </a>
        <a href="{{ route('users.index', ['role' => 'agent', 'country' => 'UG']) }}" data-users-card data-filter-role="agent" data-filter-country="UG" class="block bg-white rounded-lg shadow p-4 border border-transparent hover:border-green-700">
            <p class="text-2xl font-bold text-gray-900">{{ $summary['uganda_agents'] }}</p>
            <p class="text-sm text-gray-500 mt-1">Uganda agents</p>
        </a>
        <a href="{{ route('users.index', ['role' => 'agent', 'country' => 'CN']) }}" data-users-card data-filter-role="agent" data-filter-country="CN" class="block bg-white rounded-lg shadow p-4 border border-transparent hover:border-green-700">
            <p class="text-2xl font-bold text-gray-900">{{ $summary['china_agents'] }}</p>
            <p class="text-sm text-gray-500 mt-1">China agents</p>
        </a>
        <a href="{{ route('users.index', ['role' => 'accountant']) }}" data-users-card data-filter-role="accountant" class="block bg-white rounded-lg shadow p-4 border border-transparent hover:border-green-700">
            <p class="text-2xl font-bold text-gray-900">{{ $summary['accountants'] }}</p>
            <p class="text-sm text-gray-500 mt-1">Accountants</p>
        </a>
    </div>
    <form id="user-filters" method="GET" action="{{ route('users.index') }}" class="bg-white rounded-lg shadow p-4 mb-5">
        <div class="user-filter-grid">
            <div class="user-field user-search"><label for="user-search">Search users</label><input id="user-search" type="search" name="search" value="{{ request('search') }}" placeholder="Name, email or phone" autocomplete="off"></div>
            <div class="user-field"><label for="filter-role">Role</label><select id="filter-role" name="role"><option value="">All roles</option>@foreach($roles as $role)<option value="{{ $role }}" @selected(request('role') === $role)>{{ \App\Http\Controllers\RolePermissionController::displayName($role) }}</option>@endforeach</select></div>
            <div class="user-field"><label for="filter-status">Status</label><select id="filter-status" name="status"><option value="">All statuses</option><option value="active" @selected(request('status') === 'active')>Active</option><option value="inactive" @selected(request('status') === 'inactive')>Blocked</option><option value="locked" @selected(request('status') === 'locked')>Locked out</option></select></div>
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm">Search</button>
            <a href="{{ route('users.index') }}" data-clear-users class="text-sm text-gray-600 underline py-2">Clear</a>
        </div>
        <details class="mt-3" @if(request()->anyFilled(['country', 'access', 'two_factor'])) open @endif>
            <summary class="text-sm text-green-800 cursor-pointer">More filters</summary>
            <div class="user-filter-grid mt-3">
                <div class="user-field"><label for="filter-country">Country</label><select id="filter-country" name="country"><option value="">All countries</option>@foreach(\App\Models\User::COUNTRIES as $code => $label)<option value="{{ $code }}" @selected(request('country') === $code)>{{ $label }}</option>@endforeach</select></div>
                <div class="user-field"><label for="filter-access">Login hours</label><select id="filter-access" name="access"><option value="">Any hours</option><option value="restricted" @selected(request('access') === 'restricted')>Restricted</option><option value="open" @selected(request('access') === 'open')>Unrestricted</option></select></div>
                <div class="user-field"><label for="filter-2fa">Two-factor</label><select id="filter-2fa" name="two_factor"><option value="">Any 2FA status</option><option value="on" @selected(request('two_factor') === 'on')>Enabled</option><option value="off" @selected(request('two_factor') === 'off')>Not enabled</option></select></div>
            </div>
        </details>
    </form>
    <p id="users-request-status" role="status" class="text-sm text-gray-600 mb-3"></p>
    <div id="users-loading" class="user-loading bg-white rounded-lg" hidden aria-label="Loading users"><div class="user-skeleton"></div><div class="user-skeleton"></div><div class="user-skeleton"></div></div>
    <div id="users-results" aria-live="polite">@include('users.partials.table')</div>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('user-management.css') }}?v={{ @filemtime(public_path('user-management.css')) ?: 1 }}">@endpush
@push('scripts')<script src="{{ asset('user-management.js') }}?v={{ @filemtime(public_path('user-management.js')) ?: 1 }}" defer></script>@endpush
