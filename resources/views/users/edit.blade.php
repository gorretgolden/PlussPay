@extends('layouts.app')

@section('title', 'Edit User')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Edit User: {{ $user->name }}</h1>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-2xl">
    <form method="POST" action="{{ route('users.update', $user) }}">
        @csrf
        @method('PUT')

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
            <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none">
            <p class="text-xs text-gray-500 mt-1">First name and last name, like Gorret Golden. We correct the capital letters for you.</p>
            @error('name')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none">
            @error('email')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
            <input type="text" name="phone" value="{{ old('phone', $user->phone) }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none">
            <p class="text-xs text-gray-500 mt-1">Each account needs its own number. Duplicated country codes and leading zeros are corrected automatically.</p>
            @error('phone')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">New Password <span class="text-gray-500">(leave blank to keep current)</span></label>
            <input type="password" name="password" minlength="8"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
            <input type="password" name="password_confirmation" minlength="8"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
            <select name="role" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                @foreach($roles as $role)
                <option value="{{ $role->name }}" {{ $user->hasRole($role->name) ? 'selected' : '' }}>{{ $role->name }}</option>
                @endforeach
            </select>
        </div>

        @include('users.partials.access-fields', [
            'selectedDays' => old('days', $user->allowedDayList()),
            'allowedFrom' => old('allowed_from', $user->allowed_from),
            'allowedTo' => old('allowed_to', $user->allowed_to),
        ])

        <div class="mb-4">
            <label class="flex items-center">
                <input type="checkbox" name="is_active" value="1" {{ $user->is_active ? 'checked' : '' }}
                    class="rounded border-gray-300 text-green-600">
                <span class="ml-2 text-sm text-gray-700">Active <span class="text-gray-500">(an inactive account cannot sign in)</span></span>
            </label>
        </div>

        <div class="mb-6 border border-gray-200 rounded-md p-4 bg-gray-50 flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-gray-800">Two-factor sign-in (OTP)</p>
                <p class="text-xs text-gray-500">
                    @if($user->two_factor_enabled)
                        Currently on. They enter a 6-digit code from an authenticator app after the password.
                    @else
                        Currently off. Set it up when this account should need a code as well as a password.
                    @endif
                </p>
            </div>
            <a href="{{ route('users.two-factor', $user) }}"
                class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700 whitespace-nowrap">
                {{ $user->two_factor_enabled ? 'View or turn off' : 'Set up 2FA' }}
            </a>
        </div>

        <div class="flex gap-4">
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Update User</button>
            <a href="{{ route('users.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Cancel</a>
        </div>
    </form>
</div>
@endsection
