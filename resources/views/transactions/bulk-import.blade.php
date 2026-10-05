@extends('layouts.app')

@section('title', 'Bulk Import Transactions')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Bulk Import Transactions</h1>
    <p class="text-gray-600">Import multiple transactions from a CSV or Excel file</p>
</div>

<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
    <h3 class="text-sm font-semibold text-blue-900 mb-2">File Format Requirements:</h3>
    <ul class="text-sm text-blue-800 space-y-1 list-disc list-inside">
        <li>Supported formats: CSV, XLSX, XLS</li>
        <li>Maximum file size: 10 MB</li>
        <li>First row must contain column headers</li>
        <li>Required columns: <strong>sender_name</strong>, <strong>receiver_name</strong>, <strong>amount</strong></li>
        <li>Optional columns: sender_contact, receiver_contact, currency (UGX/USD, default: USD), purpose, notes</li>
        <li>Service fee is not a column: every row is charged the UGX per 100 USD fee set by an admin in System Settings</li>
    </ul>
</div>

<div class="bg-white rounded-lg shadow p-6 mb-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">Upload File</h3>

    <form method="POST" action="{{ route('transactions.bulk-import.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">Select File</label>
            <input type="file" name="file" accept=".csv,.xlsx,.xls" required
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
            @error('file')
                <p class="text-red-600 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex gap-3">
            <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md text-sm hover:bg-green-700">
                Import Transactions
            </button>
            <a href="{{ route('transactions.index') }}" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-md text-sm hover:bg-gray-300">
                Cancel
            </a>
        </div>
    </form>
</div>

<div class="bg-white rounded-lg shadow p-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">Sample CSV Format</h3>
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">sender_name</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">sender_contact</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">receiver_name</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">receiver_contact</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">currency</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">amount</th>
                    <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">purpose</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <tr>
                    <td class="px-3 py-2 text-gray-900">John Okello</td>
                    <td class="px-3 py-2 text-gray-600">+256 700 000 001</td>
                    <td class="px-3 py-2 text-gray-900">Zhang Wei</td>
                    <td class="px-3 py-2 text-gray-600">+86 138 0000 0001</td>
                    <td class="px-3 py-2 text-gray-600">USD</td>
                    <td class="px-3 py-2 text-gray-900">500</td>
                    <td class="px-3 py-2 text-gray-600">Business</td>
                </tr>
                <tr>
                    <td class="px-3 py-2 text-gray-900">Jane Smith</td>
                    <td class="px-3 py-2 text-gray-600">+256 700 000 002</td>
                    <td class="px-3 py-2 text-gray-900">Li Na</td>
                    <td class="px-3 py-2 text-gray-600">+86 138 0000 0002</td>
                    <td class="px-3 py-2 text-gray-600">UGX</td>
                    <td class="px-3 py-2 text-gray-900">1000000</td>
                    <td class="px-3 py-2 text-gray-600">Family Support</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

@if(session('import_errors') && count(session('import_errors')) > 0)
    <div class="bg-green-50 border border-green-200 rounded-lg p-4 mt-6">
        <h4 class="text-sm font-semibold text-green-900 mb-2">Errors encountered:</h4>
        <ul class="text-sm text-green-800 space-y-1 list-disc list-inside max-h-48 overflow-y-auto">
            @foreach(session('import_errors') as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if($errors->any())
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mt-6">
        <h4 class="text-sm font-semibold text-red-900 mb-2">Import failed:</h4>
        <ul class="text-sm text-red-800 space-y-1 list-disc list-inside">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
@endsection
