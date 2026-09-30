@extends('layouts.app')

@section('title', 'Add Customer')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Add Customer</h1>
    <p class="text-gray-600">Add a frequent sender or receiver</p>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-2xl">
    <form method="POST" action="{{ route('customers.store') }}">
        @csrf

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Name *</label>
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="100"
                placeholder="e.g. John Okello"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Phone *</label>
            <input type="text" name="phone" value="{{ old('phone') }}" required maxlength="20"
                placeholder="+256 700 000 000"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Type *</label>
            <select name="type" required class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="both" {{ old('type') === 'both' ? 'selected' : '' }}>Both (Sender & Receiver)</option>
                <option value="sender" {{ old('type') === 'sender' ? 'selected' : '' }}>Sender Only</option>
                <option value="receiver" {{ old('type') === 'receiver' ? 'selected' : '' }}>Receiver Only</option>
            </select>
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
            <input type="text" name="country" value="{{ old('country', 'Uganda') }}" maxlength="50"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
            <textarea name="notes" rows="3" maxlength="500"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">{{ old('notes') }}</textarea>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700 font-medium">
                Add Customer
            </button>
            <a href="{{ route('customers.index') }}" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-md hover:bg-gray-300">Cancel</a>
        </div>
    </form>
</div>
@endsection
