@extends('layouts.app')

@section('title', 'Edit Customer')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Edit Customer: {{ $customer->name }}</h1>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-2xl">
    <form method="POST" action="{{ route('customers.update', $customer) }}">
        @csrf
        @method('PUT')

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
            <input type="text" name="name" value="{{ old('name', $customer->name) }}" required maxlength="100"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label>
            <input type="text" name="phone" value="{{ old('phone', $customer->phone) }}" required maxlength="20"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
            <select name="type" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="both" {{ old('type', $customer->type) === 'both' ? 'selected' : '' }}>Both (Sender & Receiver)</option>
                <option value="sender" {{ old('type', $customer->type) === 'sender' ? 'selected' : '' }}>Sender Only</option>
                <option value="receiver" {{ old('type', $customer->type) === 'receiver' ? 'selected' : '' }}>Receiver Only</option>
            </select>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
            <input type="text" name="country" value="{{ old('country', $customer->country) }}" maxlength="50"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
            <textarea name="notes" rows="3" maxlength="500"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">{{ old('notes', $customer->notes) }}</textarea>
        </div>

        <div class="mb-4">
            <label class="flex items-center">
                <input type="checkbox" name="is_active" value="1" {{ $customer->is_active ? 'checked' : '' }}
                    class="rounded border-gray-300 text-green-600">
                <span class="ml-2 text-sm text-gray-700">Active</span>
            </label>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700 font-medium">
                Update Customer
            </button>
            <a href="{{ route('customers.index') }}" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-md hover:bg-gray-300">Cancel</a>
        </div>
    </form>
</div>
@endsection
