@extends('layouts.app')

@section('title', 'New Transaction')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Send Money to China</h1>
    <p class="text-gray-600">Fill in the details below to create a new transaction</p>
</div>

<div class="bg-white rounded-lg shadow p-6">
    <form method="POST" action="{{ route('transactions.store') }}" id="transactionForm">
        @csrf

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="md:col-span-2">
                <h3 class="text-lg font-semibold text-gray-800 mb-3 border-b pb-2">Sender Details</h3>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Sender's Name *</label>
                <input type="text" name="sender_name" value="{{ old('sender_name') }}" required maxlength="100"
                    placeholder="e.g. John Okello"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Sender's Contact *</label>
                <input type="text" name="sender_contact" value="{{ old('sender_contact', '+256 ') }}" required maxlength="20"
                    placeholder="+256 700 000 000"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="md:col-span-2">
                <h3 class="text-lg font-semibold text-gray-800 mb-3 border-b pb-2">Receiver Details</h3>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Receiver's Name *</label>
                <input type="text" name="receiver_name" value="{{ old('receiver_name') }}" required maxlength="100"
                    placeholder="e.g. Zhang Wei"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Receiver's Contact *</label>
                <input type="text" name="receiver_contact" value="{{ old('receiver_contact') }}" required maxlength="20"
                    placeholder="+86 138 0000 0000"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
            <div class="md:col-span-2">
                <h3 class="text-lg font-semibold text-gray-800 mb-3 border-b pb-2">Payment Details</h3>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Currency *</label>
                <select name="currency" id="currency" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
                    <option value="UGX" {{ old('currency') === 'UGX' ? 'selected' : '' }}>UGX (Ugandan Shilling)</option>
                    <option value="USD" {{ old('currency') === 'USD' ? 'selected' : '' }}>USD (US Dollar)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                <input type="number" name="amount" id="amount" value="{{ old('amount') }}" step="0.01" min="100" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
                <p class="text-xs text-gray-500 mt-1">Minimum: 100 {{ old('currency', 'UGX') === 'UGX' ? 'UGX' : 'USD' }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Charge Rate (% per 100 USD) *</label>
                <input type="number" name="charge_rate" id="charge_rate" value="{{ old('charge_rate', $defaultChargeRate) }}" step="0.01" min="0" required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
                <p class="text-xs text-gray-500 mt-1">Fee charged per 100 USD sent</p>
            </div>
        </div>

        <div class="bg-green-50 rounded-lg p-4 mb-6">
            <h4 class="font-semibold text-green-800 mb-2">Calculation Preview</h4>
            <div class="grid grid-cols-2 gap-2 text-sm">
                <div class="text-gray-600">USD Equivalent:</div>
                <div class="font-medium" id="preview_usd">-</div>
                <div class="text-gray-600">Exchange Rate (USD→RMB):</div>
                <div class="font-medium">{{ $usdToRmb ? $usdToRmb->rate : 'Not set' }}</div>
                <div class="text-gray-600">RMB Amount:</div>
                <div class="font-medium text-purple-700" id="preview_rmb">-</div>
                <div class="text-gray-600">Charge Amount:</div>
                <div class="font-medium text-green-700" id="preview_charge">-</div>
            </div>
        </div>

        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-1">Purpose (optional)</label>
            <select name="purpose"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
                <option value="">Select purpose...</option>
                <option value="Family Support" {{ old('purpose') === 'Family Support' ? 'selected' : '' }}>Family Support</option>
                <option value="Business" {{ old('purpose') === 'Business' ? 'selected' : '' }}>Business</option>
                <option value="Education" {{ old('purpose') === 'Education' ? 'selected' : '' }}>Education</option>
                <option value="Medical" {{ old('purpose') === 'Medical' ? 'selected' : '' }}>Medical</option>
                <option value="Investment" {{ old('purpose') === 'Investment' ? 'selected' : '' }}>Investment</option>
                <option value="Personal" {{ old('purpose') === 'Personal' ? 'selected' : '' }}>Personal</option>
                <option value="Other" {{ old('purpose') === 'Other' ? 'selected' : '' }}>Other</option>
            </select>
        </div>

        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
            <textarea name="notes" rows="3" maxlength="500"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">{{ old('notes') }}</textarea>
            <p class="text-xs text-gray-500 mt-1">Max 500 characters</p>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700 font-medium">
                Create Transaction & Generate Receipt
            </button>
            <a href="{{ route('transactions.index') }}" class="bg-gray-200 text-gray-700 px-6 py-2 rounded-md hover:bg-gray-300">Cancel</a>
        </div>
    </form>
</div>

@push('scripts')
<script>
const usdToRmbRate = {{ $usdToRmb ? $usdToRmb->rate : 0 }};
const ugxToUsdRate = {{ $ugxToUsd ? $ugxToUsd->rate : 0 }};

function calculate() {
    const currency = document.getElementById('currency').value;
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const chargeRate = parseFloat(document.getElementById('charge_rate').value) || 0;

    let usdEquivalent = 0;
    if (currency === 'UGX') {
        usdEquivalent = amount * ugxToUsdRate;
    } else {
        usdEquivalent = amount;
    }

    const rmbAmount = usdEquivalent * usdToRmbRate;
    const chargeAmount = usdEquivalent * (chargeRate / 100);

    document.getElementById('preview_usd').textContent = '$' + usdEquivalent.toFixed(2);
    document.getElementById('preview_rmb').textContent = '¥' + rmbAmount.toFixed(2);
    document.getElementById('preview_charge').textContent = '$' + chargeAmount.toFixed(2);
}

document.getElementById('currency').addEventListener('change', calculate);
document.getElementById('amount').addEventListener('input', calculate);
document.getElementById('charge_rate').addEventListener('input', calculate);
calculate();
</script>
@endpush
@endsection
