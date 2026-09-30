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
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
            <input type="text" name="phone" value="{{ old('phone', $user->phone) }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
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

        <div class="mb-4">
            <label class="flex items-center">
                <input type="checkbox" name="is_active" value="1" {{ $user->is_active ? 'checked' : '' }}
                    class="rounded border-gray-300 text-green-600">
                <span class="ml-2 text-sm text-gray-700">Active</span>
            </label>
        </div>

        <div class="flex gap-4">
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Update User</button>
            <a href="{{ route('users.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Cancel</a>
        </div>
    </form>
</div>
@endsection
