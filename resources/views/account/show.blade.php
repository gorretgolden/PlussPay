@extends('layouts.app')
@section('title', 'My Account')
@section('inline-validation', true)
@section('content')
@php
    $memberAgo = $user->created_at?->diffForHumans(['parts' => 2, 'join' => ' and ']) ?? 'some time ago';
    $details = [
        'Phone' => $user->phone ?: 'Not set',
        'Desk' => $user->countryLabel() ?: 'Not set',
        'Sign-in hours' => $user->loginWindowSummary(),
        'Two-step sign-in' => $user->two_factor_enabled ? 'On' : 'Off',
    ];
@endphp

<div class="user-management max-w-5xl">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        <div class="bg-white rounded-lg shadow p-6">
            <h1 class="text-2xl font-bold text-gray-900">My Account</h1>
            <p class="text-sm text-gray-600 mt-1">The details the office holds for you.</p>

            <div class="mt-6 flex items-center gap-4">
                <div class="w-14 h-14 shrink-0 rounded-full bg-green-800 text-white flex items-center justify-center text-xl font-semibold" aria-hidden="true">{{ strtoupper(mb_substr($user->name, 0, 1)) }}</div>
                <div class="min-w-0">
                    <p class="font-semibold text-gray-900 text-lg truncate">{{ $user->name }}</p>
                    <p class="text-sm text-gray-600 truncate">{{ $user->email }}</p>
                    <p class="text-sm text-green-800 font-medium mt-0.5">{{ $roles ? implode(', ', $roles) : 'No role assigned' }}</p>
                </div>
            </div>

            <p class="mt-5 text-sm text-gray-700 bg-green-50 border border-green-100 rounded-md px-3 py-2">
                Account created {{ $memberAgo }}@if ($user->created_at) on {{ $user->created_at->format('d M Y') }}@endif
            </p>

            <dl class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-5">
                @foreach($details as $label => $value)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</dt>
                    <dd class="text-sm text-gray-900 mt-1 break-words">{{ $value }}</dd>
                </div>
                @endforeach
            </dl>

            <p class="text-xs text-gray-500 mt-6">Need a name, email, phone or desk corrected? Ask an administrator.</p>
        </div>

        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-900">Change your password</h2>
            <p class="text-sm text-gray-600 mt-1 mb-5">Enter your current password, then choose the new one you will sign in with.</p>
            <form method="POST" action="{{ route('password.change') }}" data-validate data-lock-on-submit data-current-password-url="{{ route('password.check-current') }}">
                @csrf
                <input type="hidden" name="source" value="account">
                <div class="user-field mb-5">
                    <label for="current_password">Current password</label>
                    <div class="user-password">
                        <input id="current_password" name="current_password" type="password" class="user-control" autocomplete="current-password" required data-current-check aria-describedby="error-current_password">
                        <button type="button" data-toggle-password="current_password" aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                    <p id="error-current_password" data-field-error="current_password" class="user-error">@error('current_password'){{ $message }}@enderror</p>
                </div>
                @include('users.partials.password-fields', ['passwordLabel' => 'New password'])
                <button type="submit" class="mt-5 bg-green-800 text-white px-5 py-2 rounded-md text-sm font-medium hover:bg-green-700 transition">Change password</button>
            </form>
            <p class="text-xs text-gray-500 mt-4">Forgot your current password? Sign out and ask an administrator to send you a set-password link.</p>
        </div>
    </div>
</div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ asset('user-management.css') }}?v={{ @filemtime(public_path('user-management.css')) ?: 1 }}">@endpush
@push('scripts')<script src="{{ asset('user-management.js') }}?v={{ @filemtime(public_path('user-management.js')) ?: 1 }}" defer></script>@endpush
