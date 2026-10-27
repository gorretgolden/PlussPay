@extends('layouts.app')
@section('title', 'User Details')
@section('content')
<div class="user-management">
    <div class="mb-6 flex flex-wrap gap-4 justify-between items-center">
        <div>
            <p class="text-sm text-gray-500"><a href="{{ route('users.index') }}" class="text-green-800 underline">Users</a> &rsaquo; #{{ $user->id }}</p>
            <h1 class="text-2xl font-bold text-gray-900 mt-1">{{ $user->name }} @if($user->id === auth()->id())<span class="text-xs text-gray-400 font-normal">(you)</span>@endif</h1>
        </div>
        <div class="flex gap-2">
            @can('edit-user')<a href="{{ route('users.edit', $user) }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Edit User</a>@endcan
            <a href="{{ route('users.index') }}" class="border border-gray-300 bg-white text-gray-700 px-4 py-2 rounded-md text-sm">Back to Users</a>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow max-w-3xl p-6">
        <h2 class="text-sm font-semibold text-gray-900 mb-4">Account</h2>
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Email</p>
                <p class="text-sm text-gray-900 mt-1">{{ $user->email }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Phone</p>
                <p class="text-sm text-gray-900 mt-1">{{ $user->phone ?: 'Not set' }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Role</p>
                <p class="text-sm text-gray-900 mt-1">{{ \App\Http\Controllers\RolePermissionController::displayName($user->getRoleNames()->first() ?? 'none') }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Status</p>
                <p class="mt-1"><span class="user-badge {{ !$user->is_active ? 'bad' : ($user->isLocked() ? 'warn' : 'good') }}">{{ !$user->is_active ? 'Blocked' : ($user->isLocked() ? 'Locked out' : 'Active') }}</span></p>
            </div>
        </div>
        <h2 class="text-sm font-semibold text-gray-900 mb-4 mt-8">Desk</h2>
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Country</p>
                <p class="text-sm text-gray-900 mt-1">{{ $user->countryLabel() ?: 'Not set' }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Time zone</p>
                <p class="text-sm text-gray-900 mt-1">{{ $user->timezone }}</p>
            </div>
        </div>
        <h2 class="text-sm font-semibold text-gray-900 mb-4 mt-8">Sign-in</h2>
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Login hours</p>
                <p class="text-sm text-gray-900 mt-1">{{ $user->loginWindowSummary() }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Two-factor</p>
                <p class="mt-1"><span class="user-badge {{ $user->two_factor_enabled ? 'good' : 'warn' }}">{{ $user->two_factor_enabled ? '2FA on' : '2FA not enabled' }}</span></p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Last login</p>
                <p class="text-sm text-gray-900 mt-1">{{ $user->last_login_at ? $user->last_login_at->format('M d, Y h:i A') . ($user->last_login_ip ? ' from ' . $user->last_login_ip : '') : 'Never' }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Password changed</p>
                <p class="text-sm text-gray-900 mt-1">{{ $user->password_changed_at ? $user->password_changed_at->format('M d, Y g:i A') : 'Not set' }}</p>
                @if($user->must_change_password)<p class="text-xs text-amber-700 mt-1">Must set a new password at next sign-in</p>@endif
            </div>
        </div>
        <h2 class="text-sm font-semibold text-gray-900 mb-4 mt-8">Record</h2>
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Created by</p>
                <p class="text-sm text-gray-900 mt-1">{{ $user->creator?->name ?? 'Not recorded' }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ $user->created_at->format('M d, Y h:i A') }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wide text-gray-500">Edited by</p>
                <p class="text-sm text-gray-900 mt-1">{{ $user->editor?->name ?? 'Not recorded' }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ $user->updated_at->format('M d, Y h:i A') }}</p>
            </div>
        </div>
    </div>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('user-management.css') }}?v={{ @filemtime(public_path('user-management.css')) ?: 1 }}">@endpush
