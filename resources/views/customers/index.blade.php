@extends('layouts.app')

@section('title', 'Customers')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Customers</h1>
        <p class="text-gray-600">Manage frequent senders and receivers</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('export.customers', request()->query()) }}" class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
        <a href="{{ route('customers.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Add Customer</a>
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('customers.index') }}" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by name or phone..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Type</label>
            <select name="type" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Types</option>
                <option value="sender" {{ request('type') === 'sender' ? 'selected' : '' }}>Senders</option>
                <option value="receiver" {{ request('type') === 'receiver' ? 'selected' : '' }}>Receivers</option>
                <option value="both" {{ request('type') === 'both' ? 'selected' : '' }}>Both</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Country</label>
            <select name="country" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Countries</option>
                @foreach($countries as $country)
                <option value="{{ $country }}" {{ request('country') === $country ? 'selected' : '' }}>{{ $country }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Status</label>
            <select name="status" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
        <a href="{{ route('customers.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Phone</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Country</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Added</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($customers as $customer)
            <tr>
                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $customer->name }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $customer->phone }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs rounded-full
                        @if($customer->type === 'sender') bg-blue-100 text-blue-800
                        @elseif($customer->type === 'receiver') bg-purple-100 text-purple-800
                        @else bg-green-100 text-green-800 @endif">
                        {{ ucfirst($customer->type) }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-900">{{ $customer->country }}</td>
                <td class="px-6 py-4">
                    <span class="px-2 py-1 text-xs rounded-full {{ $customer->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' }}">
                        {{ $customer->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $customer->created_at->format('Y-m-d') }}</td>
                <td data-row-menu-host class="px-6 py-4 text-right relative">
                    <button type="button" onclick="toggleMenu({{ $customer->id }})" class="p-1 rounded hover:bg-gray-100">
                        <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                    </button>
                    <div id="menu-{{ $customer->id }}" data-row-menu class="hidden absolute right-0 top-8 z-20 bg-white rounded-lg shadow-lg border border-gray-200 py-1 w-40 text-left">
                        <a href="{{ route('customers.show', $customer) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">View Details</a>
                        <a href="{{ route('customers.edit', $customer) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Edit Customer</a>
                        <div class="border-t border-gray-100 my-1"></div>
                        <form method="POST" action="{{ route('customers.destroy', $customer) }}" onsubmit="return confirm('Delete this customer?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50">Delete Customer</button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="px-6 py-8 text-center text-gray-500">No customers found.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $customers->links() }}
</div>
@endsection
