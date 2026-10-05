@extends('layouts.app')

@section('title', 'All Permissions')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">All Permissions</h1>
    <p class="text-gray-600">Complete list of system permissions</p>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Permission</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                    @foreach($roles as $role)
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase capitalize">{{ $role->name }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($permissions as $permission)
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $permission->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600">{{ str_replace('-', ' ', $permission->name) }}</td>
                    @foreach($roles as $role)
                    <td class="px-6 py-4 text-center">
                        @if($role->hasPermissionTo($permission->name))
                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">Yes</span>
                        @else
                        <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-500">No</span>
                        @endif
                    </td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
