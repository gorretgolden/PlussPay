@extends('layouts.app')

@section('title', 'Settings')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">System Settings</h1>
    <p class="text-gray-600">Configure your system preferences</p>
</div>

@if(session('success'))
<div class="mb-4 bg-green-100 border border-green-300 text-green-800 px-4 py-3 rounded">
    {{ session('success') }}
</div>
@endif

<form method="POST" action="{{ route('settings.update') }}" class="space-y-6">
    @csrf
    @method('PUT')

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Company Information</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                <input type="text" name="company_name" value="{{ old('company_name', \App\Models\Setting::get('company_name', 'Plus Save Financial Solutions')) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="company_email" value="{{ old('company_email', \App\Models\Setting::get('company_email')) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                <input type="text" name="company_phone" value="{{ old('company_phone', \App\Models\Setting::get('company_phone')) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea name="company_address" rows="2"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">{{ old('company_address', \App\Models\Setting::get('company_address')) }}</textarea>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Transaction Settings</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Default Charge Rate (%)</label>
                <input type="number" step="0.01" name="default_charge_rate" value="{{ old('default_charge_rate', \App\Models\Setting::get('default_charge_rate', '5')) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Min Transaction Amount</label>
                <input type="number" step="0.01" name="min_transaction_amount" value="{{ old('min_transaction_amount', \App\Models\Setting::get('min_transaction_amount', '100')) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Max Transaction Amount</label>
                <input type="number" step="0.01" name="max_transaction_amount" value="{{ old('max_transaction_amount', \App\Models\Setting::get('max_transaction_amount', '100000000')) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Receipt Prefix</label>
                <input type="text" name="receipt_prefix" value="{{ old('receipt_prefix', \App\Models\Setting::get('receipt_prefix', 'PS')) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
        </div>
    </div>

    <div class="flex justify-end">
        <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700">Save Settings</button>
    </div>
</form>
@endsection
