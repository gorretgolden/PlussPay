@extends('layouts.app')

@section('title', 'Settings')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">System Settings</h1>
    <p class="text-gray-600">Configure your system preferences</p>
</div>

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
                <label class="block text-sm font-medium text-gray-700 mb-1">Call / WhatsApp</label>
                <input type="text" name="company_phone" value="{{ old('company_phone', \App\Models\Setting::get('company_phone')) }}"
                    placeholder="+256 393 256 744"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea name="company_address" rows="2"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">{{ old('company_address', \App\Models\Setting::get('company_address')) }}</textarea>
                <p class="text-xs text-gray-500 mt-1">Each line prints separately on the receipt.</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
                <input type="text" name="company_website" value="{{ old('company_website', \App\Models\Setting::get('company_website')) }}"
                    placeholder="www.example.com"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                <p class="text-xs text-gray-500 mt-1">Company name, phone, address and website all print on receipts.</p>
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

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold text-gray-900">Currency Rates</h2>
            <a href="{{ route('exchange-rates.index') }}" class="text-xs text-green-700 underline hover:text-green-900">Rate history</a>
        </div>
        <p class="text-sm text-gray-500 mb-4">Enter what one US dollar costs. These figures drive every conversion in the system.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">UGX per 1 USD</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">UGX</span>
                    <input type="number" step="0.0001" min="0.01" name="usd_ugx_rate"
                        value="{{ old('usd_ugx_rate', $usdToUgx ? rtrim(rtrim(number_format($usdToUgx, 4, '.', ''), '0'), '.') : '') }}"
                        placeholder="e.g. 3700"
                        class="w-full pl-14 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    @if($usdToUgx)
                        Current: 1 USD = {{ rtrim(rtrim(number_format($usdToUgx, 4, '.', ''), '0'), '.') }} UGX, so 100,000 UGX = ${{ number_format(100000 / $usdToUgx, 2) }}
                    @else
                        Not set yet. Transactions in UGX cannot be converted until this is saved.
                    @endif
                </p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">RMB per 1 USD</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">CNY</span>
                    <input type="number" step="0.000001" min="0.000001" name="usd_rmb_rate"
                        value="{{ old('usd_rmb_rate', $usdToRmb ? rtrim(rtrim(number_format((float) $usdToRmb->rate, 6, '.', ''), '0'), '.') : '') }}"
                        placeholder="e.g. 7.24"
                        class="w-full pl-14 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    @if($usdToRmb)
                        Current: 1 USD = {{ rtrim(rtrim(number_format((float) $usdToRmb->rate, 6, '.', ''), '0'), '.') }} RMB, so $100 = ¥{{ number_format(100 * (float) $usdToRmb->rate, 2) }}
                    @else
                        Not set yet. Senders cannot receive RMB quotes until this is saved.
                    @endif
                </p>
            </div>
        </div>
        @if($usdToRmb)
            <p class="text-xs text-gray-400 mt-3">Saved as new active rates. The old ones stay in the history, last change {{ optional($usdToRmb)->created_at?->format('M d, Y H:i') }}.</p>
        @endif
    </div>

    <div class="flex justify-end">
        <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700">Save Settings</button>
    </div>
</form>
@endsection
