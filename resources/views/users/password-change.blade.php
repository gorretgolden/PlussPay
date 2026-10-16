@extends('layouts.app')
@section('title', 'Change your password')
@section('inline-validation', true)
@section('content')
<div class="user-management bg-white rounded-lg shadow p-6 max-w-3xl">
    <h1 class="text-2xl font-bold mb-2">Change your password</h1>
    <p class="text-sm text-gray-600 mb-6">Choose your own password before continuing.</p>
    <form method="POST" action="{{ route('password.change') }}" data-validate data-password-change>
        @csrf
        <div class="user-field mb-5"><label for="current_password">Current password</label><input id="current_password" type="password" name="current_password" autocomplete="current-password" required aria-describedby="error-current_password"><p id="error-current_password" data-field-error="current_password" class="user-error">@error('current_password'){{ $message }}@enderror</p></div>
        @include('users.partials.password-fields')
        <button type="submit" class="mt-5 bg-green-800 text-white px-5 py-2 rounded-md text-sm">Change password</button>
    </form>
    <form method="POST" action="{{ route('logout') }}" class="mt-4">@csrf<button type="submit" class="text-sm underline text-gray-600">Sign out instead</button></form>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('user-management.css') }}?v={{ @filemtime(public_path('user-management.css')) ?: 1 }}">@endpush
@push('scripts')<script src="{{ asset('user-management.js') }}?v={{ @filemtime(public_path('user-management.js')) ?: 1 }}" defer></script>@endpush
