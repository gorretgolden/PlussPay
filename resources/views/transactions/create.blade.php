@extends('layouts.app')

@section('title', 'New Transaction')

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Send Money to China</h1>
        <p class="text-gray-600">Complete each step to create a new transaction</p>
    </div>

    {{-- Stepper --}}
    <div class="flex items-center justify-between mb-8 px-4">
        <div class="flex flex-col items-center step-indicator" data-step="1">
            <div class="w-10 h-10 rounded-full bg-green-800 text-white flex items-center justify-center font-bold text-sm step-circle">1</div>
            <span class="text-xs mt-1 font-medium text-green-800 step-label">Sender</span>
        </div>
        <div class="flex-1 border-t-2 border-dashed border-gray-300 mx-2 step-line" data-line="1"></div>
        <div class="flex flex-col items-center step-indicator" data-step="2">
            <div class="w-10 h-10 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center font-bold text-sm step-circle">2</div>
            <span class="text-xs mt-1 font-medium text-gray-500 step-label">Receiver</span>
        </div>
        <div class="flex-1 border-t-2 border-dashed border-gray-300 mx-2 step-line" data-line="2"></div>
        <div class="flex flex-col items-center step-indicator" data-step="3">
            <div class="w-10 h-10 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center font-bold text-sm step-circle">3</div>
            <span class="text-xs mt-1 font-medium text-gray-500 step-label">Payment</span>
        </div>
        <div class="flex-1 border-t-2 border-dashed border-gray-300 mx-2 step-line" data-line="3"></div>
        <div class="flex flex-col items-center step-indicator" data-step="4">
            <div class="w-10 h-10 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center font-bold text-sm step-circle">4</div>
            <span class="text-xs mt-1 font-medium text-gray-500 step-label">Confirm</span>
        </div>
    </div>

    <form method="POST" action="{{ route('transactions.store') }}" id="transactionForm">
        @csrf

        {{-- Step 1: Sender --}}
        <div class="wizard-step bg-white rounded-lg shadow p-8" id="step-1">
            <h2 class="text-lg font-semibold text-gray-800 mb-6">Sender Details</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sender's Name *</label>
                    <input type="text" name="sender_name" value="{{ old('sender_name') }}" required maxlength="100"
                        placeholder="e.g. John Okello"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sender's Phone *</label>
                    <div class="flex">
                        <select id="sender_prefix" class="px-3 py-3 border border-r-0 border-gray-300 rounded-l-lg bg-gray-50 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            <option value="+256" selected>🇺🇬 +256</option>
                            <option value="+1">🇺🇸 +1</option>
                            <option value="+44">🇬 +44</option>
                            <option value="+86">🇨 +86</option>
                            <option value="+254">🇰🇪 +254</option>
                            <option value="+255">🇹🇿 +255</option>
                            <option value="+250">🇷🇼 +250</option>
                            <option value="+971">🇦🇪 +971</option>
                            <option value="+91">🇮🇳 +91</option>
                            <option value="+27">🇿🇦 +27</option>
                        </select>
                        <input type="tel" name="sender_contact" id="sender_contact" value="{{ old('sender_contact') }}" required maxlength="15"
                            placeholder="700 000 000"
                            class="flex-1 px-4 py-3 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>
                    <input type="hidden" name="sender_full_contact" id="sender_full_contact">
                </div>
            </div>
            <div class="flex justify-end mt-8">
                <button type="button" onclick="nextStep(1)" class="bg-green-800 text-white px-8 py-3 rounded-lg hover:bg-green-700 font-medium">
                    Next: Receiver →
                </button>
            </div>
        </div>

        {{-- Step 2: Receiver --}}
        <div class="wizard-step bg-white rounded-lg shadow p-8 hidden" id="step-2">
            <h2 class="text-lg font-semibold text-gray-800 mb-6">Receiver Details</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Receiver's Name *</label>
                    <input type="text" name="receiver_name" value="{{ old('receiver_name') }}" required maxlength="100"
                        placeholder="e.g. Zhang Wei"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Receiver's Phone *</label>
                    <div class="flex">
                        <select id="receiver_prefix" class="px-3 py-3 border border-r-0 border-gray-300 rounded-l-lg bg-gray-50 text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500">
                            <option value="+86" selected>🇨🇳 +86</option>
                            <option value="+256">🇺🇬 +256</option>
                            <option value="+1">🇺 +1</option>
                            <option value="+44">🇬🇧 +44</option>
                            <option value="+254">🇰🇪 +254</option>
                            <option value="+255">🇹🇿 +255</option>
                            <option value="+971">🇦🇪 +971</option>
                            <option value="+91">🇮 +91</option>
                            <option value="+81">🇯 +81</option>
                            <option value="+82">🇰 +82</option>
                        </select>
                        <input type="tel" name="receiver_contact" id="receiver_contact" value="{{ old('receiver_contact') }}" required maxlength="15"
                            placeholder="138 0000 0000"
                            class="flex-1 px-4 py-3 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>
                    <input type="hidden" name="receiver_full_contact" id="receiver_full_contact">
                </div>
            </div>
            <div class="flex justify-between mt-8">
                <button type="button" onclick="prevStep(2)" class="bg-gray-200 text-gray-700 px-8 py-3 rounded-lg hover:bg-gray-300 font-medium">
                    ← Back
                </button>
                <button type="button" onclick="nextStep(2)" class="bg-green-800 text-white px-8 py-3 rounded-lg hover:bg-green-700 font-medium">
                    Next: Payment →
                </button>
            </div>
        </div>

        {{-- Step 3: Payment --}}
        <div class="wizard-step bg-white rounded-lg shadow p-8 hidden" id="step-3">
            <h2 class="text-lg font-semibold text-gray-800 mb-6">Payment Details</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Currency *</label>
                    <select name="currency" id="currency" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        <option value="UGX" {{ old('currency') === 'UGX' ? 'selected' : '' }}>UGX (Ugandan Shilling)</option>
                        <option value="USD" {{ old('currency') === 'USD' ? 'selected' : '' }}>USD (US Dollar)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Amount *</label>
                    <input type="number" name="amount" id="amount" value="{{ old('amount') }}" step="0.01" min="100" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <p class="text-xs text-gray-500 mt-1">Minimum: 100 UGX</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Charge Rate (% per 100 USD) *</label>
                    <input type="number" name="charge_rate" id="charge_rate" value="{{ old('charge_rate', $defaultChargeRate) }}" step="0.01" min="0" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
            </div>

            <div class="bg-green-50 rounded-lg p-5 mt-6">
                <h4 class="font-semibold text-green-800 mb-3">Live Calculation</h4>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-gray-500">USD Equivalent</div>
                        <div class="font-bold text-lg" id="preview_usd">-</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Rate (USD→RMB)</div>
                        <div class="font-bold text-lg">{{ $usdToRmb ? $usdToRmb->rate : 'N/A' }}</div>
                    </div>
                    <div>
                        <div class="text-gray-500">RMB Receiver Gets</div>
                        <div class="font-bold text-lg text-purple-700" id="preview_rmb">-</div>
                    </div>
                    <div>
                        <div class="text-gray-500">Service Charge</div>
                        <div class="font-bold text-lg text-green-700" id="preview_charge">-</div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Purpose (optional)</label>
                    <select name="purpose"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
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
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notes (optional)</label>
                    <input type="text" name="notes" value="{{ old('notes') }}" maxlength="500"
                        placeholder="Any additional notes..."
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
            </div>

            <div class="flex justify-between mt-8">
                <button type="button" onclick="prevStep(3)" class="bg-gray-200 text-gray-700 px-8 py-3 rounded-lg hover:bg-gray-300 font-medium">
                    ← Back
                </button>
                <button type="button" onclick="nextStep(3)" class="bg-green-800 text-white px-8 py-3 rounded-lg hover:bg-green-700 font-medium">
                    Next: Review →
                </button>
            </div>
        </div>

        {{-- Step 4: Preview & Confirm --}}
        <div class="wizard-step bg-white rounded-lg shadow p-8 hidden" id="step-4">
            <h2 class="text-lg font-semibold text-gray-800 mb-6">Review & Confirm</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div class="border border-gray-200 rounded-lg p-5">
                    <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Sender</h3>
                    <p class="text-lg font-medium" id="rev_sender_name">-</p>
                    <p class="text-sm text-gray-600" id="rev_sender_contact">-</p>
                </div>
                <div class="border border-gray-200 rounded-lg p-5">
                    <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Receiver</h3>
                    <p class="text-lg font-medium" id="rev_receiver_name">-</p>
                    <p class="text-sm text-gray-600" id="rev_receiver_contact">-</p>
                </div>
            </div>

            <div class="bg-gray-50 rounded-lg p-5 mb-6">
                <h3 class="text-sm font-semibold text-gray-500 uppercase mb-3">Transaction Summary</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-xs text-gray-500">Amount</div>
                        <div class="font-bold text-lg" id="rev_amount">-</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">USD Equivalent</div>
                        <div class="font-bold text-lg" id="rev_usd">-</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">RMB to Receiver</div>
                        <div class="font-bold text-lg text-purple-700" id="rev_rmb">-</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Service Charge</div>
                        <div class="font-bold text-lg text-green-700" id="rev_charge">-</div>
                    </div>
                </div>
                <div class="mt-3 text-sm text-gray-600" id="rev_purpose"></div>
            </div>

            <div class="flex justify-between mt-8">
                <button type="button" onclick="prevStep(4)" class="bg-gray-200 text-gray-700 px-8 py-3 rounded-lg hover:bg-gray-300 font-medium">
                    ← Edit Details
                </button>
                <button type="submit" id="submitBtn" class="bg-green-800 text-white px-8 py-3 rounded-lg hover:bg-green-700 font-medium">
                    Confirm & Generate Receipt
                </button>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
const usdToRmbRate = {{ $usdToRmb ? $usdToRmb->rate : 0 }};
const ugxToUsdRate = {{ $ugxToUsd ? $ugxToUsd->rate : 0 }};
let currentStep = 1;

function buildFullContact(prefixId, contactId, hiddenId) {
    const prefix = document.getElementById(prefixId).value;
    const contact = document.getElementById(contactId).value.trim();
    document.getElementById(hiddenId).value = prefix + ' ' + contact;
}

document.getElementById('sender_prefix').addEventListener('change', function() {
    buildFullContact('sender_prefix', 'sender_contact', 'sender_full_contact');
});
document.getElementById('receiver_prefix').addEventListener('change', function() {
    buildFullContact('receiver_prefix', 'receiver_contact', 'receiver_full_contact');
});
document.getElementById('sender_contact').addEventListener('input', function() {
    buildFullContact('sender_prefix', 'sender_contact', 'sender_full_contact');
});
document.getElementById('receiver_contact').addEventListener('input', function() {
    buildFullContact('receiver_prefix', 'receiver_contact', 'receiver_full_contact');
});

function validateStep(step) {
    const el = document.getElementById('step-' + step);
    const inputs = el.querySelectorAll('[required]');
    for (const input of inputs) {
        if (!input.value.trim()) {
            input.focus();
            input.classList.add('ring-2', 'ring-red-400');
            setTimeout(() => input.classList.remove('ring-2', 'ring-red-400'), 2000);
            return false;
        }
    }
    return true;
}

function showStep(step) {
    document.querySelectorAll('.wizard-step').forEach(s => s.classList.add('hidden'));
    document.getElementById('step-' + step).classList.remove('hidden');

    document.querySelectorAll('.step-indicator').forEach(ind => {
        const s = parseInt(ind.dataset.step);
        const circle = ind.querySelector('.step-circle');
        const label = ind.querySelector('.step-label');
        if (s < step) {
            circle.className = 'w-10 h-10 rounded-full bg-green-200 text-green-800 flex items-center justify-center font-bold text-sm step-circle';
            circle.textContent = '✓';
            label.className = 'text-xs mt-1 font-medium text-green-700 step-label';
        } else if (s === step) {
            circle.className = 'w-10 h-10 rounded-full bg-green-800 text-white flex items-center justify-center font-bold text-sm step-circle';
            circle.textContent = s;
            label.className = 'text-xs mt-1 font-medium text-green-800 step-label';
        } else {
            circle.className = 'w-10 h-10 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center font-bold text-sm step-circle';
            circle.textContent = s;
            label.className = 'text-xs mt-1 font-medium text-gray-500 step-label';
        }
    });

    document.querySelectorAll('.step-line').forEach(line => {
        const l = parseInt(line.dataset.line);
        line.className = l < step ? 'flex-1 border-t-2 border-green-400 mx-2 step-line' : 'flex-1 border-t-2 border-dashed border-gray-300 mx-2 step-line';
    });

    currentStep = step;
}

function nextStep(from) {
    if (!validateStep(from)) return;
    const to = from + 1;
    if (to === 4) populateReview();
    showStep(to);
}

function prevStep(from) {
    showStep(from - 1);
}

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

function populateReview() {
    buildFullContact('sender_prefix', 'sender_contact', 'sender_full_contact');
    buildFullContact('receiver_prefix', 'receiver_contact', 'receiver_full_contact');

    document.getElementById('rev_sender_name').textContent = document.querySelector('[name=sender_name]').value;
    document.getElementById('rev_sender_contact').textContent = document.getElementById('sender_full_contact').value;
    document.getElementById('rev_receiver_name').textContent = document.querySelector('[name=receiver_name]').value;
    document.getElementById('rev_receiver_contact').textContent = document.getElementById('receiver_full_contact').value;

    const currency = document.getElementById('currency').value;
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const chargeRate = parseFloat(document.getElementById('charge_rate').value) || 0;
    let usdEquivalent = currency === 'UGX' ? amount * ugxToUsdRate : amount;
    const rmbAmount = usdEquivalent * usdToRmbRate;
    const chargeAmount = usdEquivalent * (chargeRate / 100);

    document.getElementById('rev_amount').textContent = currency + ' ' + amount.toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('rev_usd').textContent = '$' + usdEquivalent.toFixed(2);
    document.getElementById('rev_rmb').textContent = '¥' + rmbAmount.toFixed(2);
    document.getElementById('rev_charge').textContent = '$' + chargeAmount.toFixed(2);

    const purpose = document.querySelector('[name=purpose]').value;
    document.getElementById('rev_purpose').textContent = purpose ? 'Purpose: ' + purpose : '';
}

document.getElementById('currency').addEventListener('change', calculate);
document.getElementById('amount').addEventListener('input', calculate);
document.getElementById('charge_rate').addEventListener('input', calculate);
calculate();

document.getElementById('transactionForm').addEventListener('submit', function(e) {
    buildFullContact('sender_prefix', 'sender_contact', 'sender_full_contact');
    buildFullContact('receiver_prefix', 'receiver_contact', 'receiver_full_contact');

    document.querySelector('[name=sender_contact]').value = document.getElementById('sender_full_contact').value;
    document.querySelector('[name=receiver_contact]').value = document.getElementById('receiver_full_contact').value;

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Processing...';
});
</script>
@endpush
@endsection
