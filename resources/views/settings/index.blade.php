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
                <label class="block text-sm font-medium text-gray-700 mb-1">Service Fee (UGX)</label>
                <input type="number" step="0.01" name="service_fee_ugx" value="{{ old('service_fee_ugx', \App\Models\Setting::get('service_fee_ugx', '2000')) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Charged Per (USD)</label>
                <input type="number" step="0.01" name="service_fee_usd" value="{{ old('service_fee_usd', \App\Models\Setting::get('service_fee_usd', '100')) }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                <p class="text-xs text-gray-500 mt-1">The fee is charged for each block of this many dollars, and scales with the amount sent.</p>
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
        @php
            $trimRate = fn ($v) => rtrim(rtrim(number_format((float) $v, 6, '.', ''), '0'), '.');
            $trimMoney = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.');
            $ugxMargin = ($usdUgxPair['sell'] ?? 0) - ($usdUgxPair['buy'] ?? 0);
        @endphp
        <p class="text-sm text-gray-500 mb-4">Two prices per currency: what a dollar costs you to buy, and what the customer pays you for it. The difference is your profit on every dollar.</p>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">US Dollar in UGX</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Buying rate (UGX)</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">UGX</span>
                            <input type="number" step="0.0001" min="0.01" name="usd_ugx_buying"
                                value="{{ old('usd_ugx_buying', isset($usdUgxPair) ? $trimRate($usdUgxPair['buy']) : '') }}"
                                placeholder="e.g. 3750"
                                class="w-full pl-14 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">What you pay for one dollar.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Selling rate (UGX)</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">UGX</span>
                            <input type="number" step="0.0001" min="0.01" name="usd_ugx_selling"
                                value="{{ old('usd_ugx_selling', isset($usdUgxPair) ? $trimRate($usdUgxPair['sell']) : '') }}"
                                placeholder="e.g. 3760"
                                class="w-full pl-14 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">What customers pay you for one dollar. This rate drives every conversion.</p>
                    </div>
                </div>
                @if($usdUgxPair)
                    <div class="mt-3 rounded-md px-3 py-2 text-sm {{ $ugxMargin >= 0 ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700' }}">
                        Profit on one dollar:
                        <span class="font-semibold">UGX {{ $trimMoney(abs($ugxMargin)) }}</span>
                        @if($ugxMargin >= 0)
                            <span class="text-xs text-green-700">(buying {{ $trimRate($usdUgxPair['buy']) }}, selling {{ $trimRate($usdUgxPair['sell']) }})</span>
                        @else
                            <span class="text-xs">(you would lose on each dollar as entered)</span>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        100,000 UGX at the selling rate = ${{ number_format(100000 / (float) $usdUgxPair['sell'], 2) }}
                        @if($usdUgxPair['buy'] > 0)
                            , which costs you UGX {{ number_format(round(100000 / (float) $usdUgxPair['sell'] * (float) $usdUgxPair['buy'])) }} to buy.
                        @endif
                    </p>
                @else
                    <p class="text-xs text-amber-700 mt-3">Not set yet. Transactions in UGX cannot be converted or priced until the selling rate is saved.</p>
                @endif
            </div>

            <div class="border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-semibold text-gray-900 mb-3">US Dollar in RMB</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Buying rate (RMB)</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">CNY</span>
                            <input type="number" step="0.000001" min="0.000001" name="usd_rmb_buying"
                                value="{{ old('usd_rmb_buying', isset($usdRmbPair) ? $trimRate($usdRmbPair['buy']) : '') }}"
                                placeholder="e.g. 6.70"
                                class="w-full pl-14 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">What the China side pays for one dollar.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Selling rate (RMB)</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">CNY</span>
                            <input type="number" step="0.000001" min="0.000001" name="usd_rmb_selling"
                                value="{{ old('usd_rmb_selling', isset($usdRmbPair) ? $trimRate($usdRmbPair['sell']) : '') }}"
                                placeholder="e.g. 6.75"
                                class="w-full pl-14 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">What RMB is priced at per dollar. Recipients are paid at this rate.</p>
                    </div>
                </div>
                @if($usdRmbPair)
                    <div class="mt-3 rounded-md bg-gray-50 px-3 py-2 text-sm text-gray-700">
                        Spread on one dollar:
                        <span class="font-semibold">RMB {{ $trimMoney(abs(($usdRmbPair['sell'] ?? 0) - ($usdRmbPair['buy'] ?? 0))) }}</span>
                        <span class="text-xs text-gray-500">(buying {{ $trimRate($usdRmbPair['buy']) }}, selling {{ $trimRate($usdRmbPair['sell']) }})</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">$100 at the selling rate = ¥{{ number_format(100 * (float) $usdRmbPair['sell'], 2) }}.</p>
                @else
                    <p class="text-xs text-amber-700 mt-3">Not set yet. Senders cannot receive RMB quotes until the selling rate is saved.</p>
                @endif
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
