@extends('layouts.app')

@section('title', 'Roles & Permissions')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Roles & Permissions</h1>
    <p class="text-gray-600">Manage role permissions</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    @foreach($roles as $role)
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 capitalize">{{ $role->name }}</h3>
            <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">{{ $role->permissions->count() }} permissions</span>
        </div>
        <form method="POST" action="{{ route('admin.roles.update', $role) }}">
            @csrf
            @method('PUT')
            <div class="space-y-2 max-h-64 overflow-y-auto">
                @foreach($permissions as $permission)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="permissions[]" value="{{ $permission->name }}"
                        {{ $role->hasPermissionTo($permission->name) ? 'checked' : '' }}
                        class="rounded border-gray-300 text-green-700 focus:ring-green-600">
                    <span class="text-gray-700">{{ str_replace('-', ' ', $permission->name) }}</span>
                </label>
                @endforeach
            </div>
            <button type="submit" class="mt-4 w-full bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">
                Update Permissions
            </button>
        </form>
    </div>
    @endforeach
</div>
@endsection
