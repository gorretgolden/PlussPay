@extends('layouts.app')
@section('title', 'Edit User')
@section('inline-validation', true)
@section('content')
<div class="mb-6"><h1 class="text-2xl font-bold text-gray-900">Edit User: {{ $user->name }}</h1><p class="text-sm text-gray-500 mt-1">Manage this account’s desk and sign-in access.</p></div>
<div class="user-management bg-white rounded-lg shadow p-6 max-w-4xl">
    <form method="POST" action="{{ route('users.update', $user) }}" data-validate data-user-form data-lock-on-submit data-user-id="{{ $user->id }}" data-availability-url="{{ url('/users/availability') }}">
        @csrf @method('PUT')
        @include('users.partials.form')
        <div class="flex gap-3 mt-6 border-t pt-5">
            <button type="submit" class="bg-green-800 text-white px-5 py-2 rounded-md text-sm hover:bg-green-700">Save changes</button>
            <a href="{{ route('users.index') }}" class="border border-gray-300 text-gray-700 px-5 py-2 rounded-md text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('user-management.css') }}?v={{ @filemtime(public_path('user-management.css')) ?: 1 }}">@endpush
@push('scripts')<script src="{{ asset('user-management.js') }}?v={{ @filemtime(public_path('user-management.js')) ?: 1 }}" defer></script>@endpush
