@extends('layouts.app')

@section('title', 'New Transaction')

@section('content')
@php
    $serverRepopulated = $errors->any();
    $errorFields = $errors->keys();
    $draftKey = 'pluspay_send_draft_u' . (auth()->id() ?? 'guest');
    $keepKey = 'pluspay_keep_draft_u' . (auth()->id() ?? 'guest');
    $trimRate = fn ($r) => rtrim(rtrim(number_format((float) $r, 6, '.', ''), '0'), '.');
@endphp
<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Send Money to China</h1>
    </div>

    {{-- Draft restored banner --}}
    <div id="draftBar" class="hidden mb-6 bg-amber-50 border border-amber-300 rounded-lg p-4 flex items-center justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-amber-900" id="draftMessage">Draft restored</p>
            <p class="text-xs text-amber-700 mt-1">Your unfinished transaction was saved in this browser.</p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('transactions.create') }}" id="editLink" class="text-sm bg-white border border-amber-300 text-amber-900 px-4 py-2 rounded-lg hover:bg-amber-100 font-medium">Edit Draft</a>
            <button type="button" id="discardDraft" class="text-sm bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-500 font-medium">Discard</button>
        </div>
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
        <input type="hidden" name="form_id" value="{{ $formId }}">

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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sender's Phone</label>
                    <div class="flex">
                        @include('transactions.partials.prefix-picker', ['who' => 'sender', 'default' => '+256'])
                        <input type="tel" name="sender_contact" id="sender_contact" value="{{ old('sender_contact') }}" maxlength="15"
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
                    <label class="block text-sm font-medium text-gray-700 mb-1">Receiver's Phone</label>
                    <div class="flex">
                        @include('transactions.partials.prefix-picker', ['who' => 'receiver', 'default' => '+86'])
                        <input type="tel" name="receiver_contact" id="receiver_contact" value="{{ old('receiver_contact') }}" maxlength="15"
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
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label class="block text-sm font-medium text-gray-700" for="currency">Currency *</label>
                        @include('transactions.partials.field-tip', [
                            'id' => 'currency-help-tip',
                            'text' => 'UGX: the customer pays shillings, the charge comes out of that money, and what is left is turned into dollars. USD: the customer hands you dollars, all of them travel, and the charge is collected on top in shillings.',
                        ])
                    </div>
                    <select name="currency" id="currency" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        <option value="UGX" {{ old('currency') === 'UGX' ? 'selected' : '' }}>UGX</option>
                        <option value="USD" {{ old('currency') === 'USD' ? 'selected' : '' }}>USD</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Choose what the customer pays in.</p>
                </div>
                <div>
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label class="block text-sm font-medium text-gray-700" for="amount">Money handed over *</label>
                        @include('transactions.partials.field-tip', [
                            'id' => 'amount-help-tip',
                            'text' => 'Count the cash as it was handed to you, before anything is taken out. The charge is subtracted from it, and only what is left is converted.',
                        ])
                    </div>
                    <input type="text" inputmode="decimal" autocomplete="off" data-money name="amount" id="amount" value="{{ old('amount') }}" min="100" required
                        placeholder="0"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 tabular-nums">
                    <p class="text-xs text-gray-500 mt-1" id="cash_help">Total received, charge included.</p>
                </div>
                <div>
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label class="block text-sm font-medium text-gray-700" for="charge_ugx">Charge (UGX) *</label>
                        @include('transactions.partials.field-tip', [
                            'id' => 'charge-help-tip',
                            'text' => $defaultCharge !== ''
                                ? 'The box opens at the default charge from System Settings. Type over it whenever this customer agreed a different figure, or 0 when nothing was taken.'
                                : 'You agree this per customer, so nothing is filled in for you. Type what you kept, or 0 when nothing was taken.',
                        ])
                    </div>
                    <input type="text" inputmode="decimal" autocomplete="off" data-money name="charge_ugx" id="charge_ugx" value="{{ old('charge_ugx', $defaultCharge) }}" min="0" required
                        placeholder="0"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 tabular-nums">
                    <p class="text-xs text-gray-500 mt-1">{{ $defaultCharge !== '' ? 'Starts at the default charge from Settings.' : 'Type what you kept from this customer.' }}</p>
                </div>
                <div>
                    <label for="purpose" class="block text-sm font-medium text-gray-700 mb-1">Purpose (optional)</label>
                    <select name="purpose" id="purpose"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        <option value="">Select purpose...</option>
                        @foreach(['Family Support', 'Business', 'Education', 'Medical', 'Investment', 'Personal', 'Other'] as $option)
                            <option value="{{ $option }}" {{ old('purpose') === $option ? 'selected' : '' }}>{{ $option }}</option>
                        @endforeach
                    </select>
                    <div id="purpose-other-wrap" class="mt-3 hidden">
                        <label for="purpose_other" class="block text-sm font-medium text-gray-700 mb-1">Reason for other *</label>
                        <input type="text" name="purpose_other" id="purpose_other" maxlength="100" value="{{ old('purpose_other') }}"
                            placeholder="e.g. Church contribution"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="notes">Notes (optional)</label>
                    <input type="text" name="notes" id="notes" value="{{ old('notes') }}" maxlength="500"
                        placeholder="Sending money to China"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
            </div>

            <div class="mt-6 rounded-lg border-2 border-green-700 bg-white p-4 sm:p-5 hidden" id="balance_strip">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <span class="block text-sm font-semibold text-gray-800" id="balance_label">Balance after the charge</span>
                        <span class="block text-xs text-gray-500 mt-1" id="balance_note">This is the money turned into dollars, then into yuan.</span>
                    </div>
                    <span class="text-2xl font-bold text-green-800 tabular-nums leading-none" id="balance_amount">-</span>
                </div>
            </div>

            <div class="bg-green-50 rounded-lg p-5 mt-4">
                <div class="flex items-center justify-between mb-3">
                    <h4 class="font-semibold text-green-800">Live Calculation</h4>
                    @can('manage-exchange-rates')
                        <a href="{{ route('settings.index') }}" class="text-xs text-green-700 underline hover:text-green-900">Update rates</a>
                    @endcan
                </div>

                <p class="text-sm text-gray-600" id="calc_empty">Enter an amount to see the total.</p>

                <div id="calc_rows" class="hidden">
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div class="text-sm font-medium text-gray-600">RMB receiver gets</div>
                        <div class="text-3xl font-bold text-purple-700 tabular-nums leading-none" id="preview_rmb">-</div>
                    </div>
                    <div class="mt-3 pt-2 border-t border-green-200 space-y-1 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-gray-600">Credited in USD</span>
                            <span class="font-medium text-gray-900 tabular-nums" id="preview_usd">-</span>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-gray-600">Rate</span>
                            <span class="font-medium text-gray-900 tabular-nums" id="preview_rate">{{ $usdRmb && $usdRmb['sell'] > 0 ? '1 USD = ' . $trimRate($usdRmb['sell']) . ' RMB' : 'Not set' }}</span>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-3 leading-relaxed" id="preview_formula"></p>
                </div>
            </div>

            <div class="flex items-start justify-between mt-8">
                <button type="button" onclick="prevStep(3)" class="bg-gray-200 text-gray-700 px-8 py-3 rounded-lg hover:bg-gray-300 font-medium">
                    ← Back
                </button>
                <div class="flex flex-col items-end gap-2">
                    <button type="button" id="next-3" onclick="nextStep(3)" class="bg-green-800 text-white px-8 py-3 rounded-lg hover:bg-green-700 disabled:bg-gray-300 disabled:text-gray-500 disabled:cursor-not-allowed disabled:hover:bg-gray-300 font-medium">
                        Next: Review →
                    </button>
                    <p class="text-xs text-gray-500" id="step3_hint"></p>
                </div>
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
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div>
                        <div class="text-xs text-gray-500">Money Handed Over</div>
                        <div class="font-bold text-lg" id="rev_amount">-</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Charge Taken Out</div>
                        <div class="font-bold text-lg text-green-700" id="rev_charge">-</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Money That Travels</div>
                        <div class="font-bold text-lg" id="rev_sent">-</div>
                        <div class="text-xs text-gray-500 mt-1" id="rev_total_note"></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Dollars Credited</div>
                        <div class="font-bold text-lg" id="rev_usd">-</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">RMB to Receiver</div>
                        <div class="font-bold text-lg text-purple-700" id="rev_rmb">-</div>
                    </div>
                </div>
                <div class="mt-3 text-sm text-gray-600" id="rev_purpose"></div>
            </div>

            <div class="flex justify-between items-center mt-8">
                <button type="button" onclick="prevStep(4)" class="bg-gray-200 text-gray-700 px-8 py-3 rounded-lg hover:bg-gray-300 font-medium">
                    ← Edit Details
                </button>
                <div class="text-right">
                    <button type="submit" id="submitBtn" class="bg-green-800 text-white px-8 py-3 rounded-lg hover:bg-green-700 font-medium">
                        Book & Generate Receipt
                    </button>
                    <p class="text-xs text-gray-500 mt-2">The transfer is finished as soon as you book it.</p>
                </div>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
const usdSellRate = {{ $usdUgx && $usdUgx['sell'] > 0 ? $usdUgx['sell'] : 0 }};
const rmbSellRate = {{ $usdRmb && $usdRmb['sell'] > 0 ? $usdRmb['sell'] : 0 }};
const errorFields = @json($errorFields);
let currentStep = 1;

function nationalDigits(prefixId, contactId) {
    const code = document.getElementById(prefixId).value.replace(/\D/g, '');
    let digits = document.getElementById(contactId).value.replace(/\D/g, '');
    if (digits.startsWith(code) && digits.length > code.length) {
        digits = digits.slice(code.length);
    } else if (digits.startsWith('0')) {
        digits = digits.slice(1);
    }
    return digits;
}

function buildFullContact(prefixId, contactId, hiddenId) {
    const prefix = document.getElementById(prefixId).value;
    document.getElementById(hiddenId).value = prefix + ' ' + nationalDigits(prefixId, contactId);
}

['sender', 'receiver'].forEach(who => {
    document.getElementById(who + '_contact').addEventListener('blur', function () {
        const digits = nationalDigits(who + '_prefix', who + '_contact');
        if (digits) this.value = digits;
        buildFullContact(who + '_prefix', who + '_contact', who + '_full_contact');
    });
});

document.querySelectorAll('.prefix-picker').forEach(picker => {
    const hidden = picker.querySelector('input[type=hidden]');
    const btn = picker.querySelector('.prefix-btn');
    const label = picker.querySelector('.prefix-label');
    const panel = picker.querySelector('.prefix-panel');
    const search = picker.querySelector('.prefix-search');
    const options = Array.from(picker.querySelectorAll('.prefix-option'));

    function apply(value) {
        const opt = options.find(o => o.dataset.value === value) || options[0];
        label.textContent = opt.dataset.label;
        hidden.value = opt.dataset.value;
        options.forEach(o => o.classList.toggle('bg-green-50', o === opt));
    }
    function filter() {
        const q = search.value.trim().toLowerCase();
        options.forEach(o => o.classList.toggle('hidden', !o.dataset.search.includes(q)));
    }
    function open() { panel.classList.remove('hidden'); search.value = ''; filter(); search.focus(); }
    function close() { panel.classList.add('hidden'); }

    apply(hidden.value);
    btn.addEventListener('click', () => panel.classList.contains('hidden') ? open() : close());
    search.addEventListener('input', filter);
    options.forEach(o => o.addEventListener('click', () => {
        apply(o.dataset.value);
        hidden.dispatchEvent(new Event('change'));
        close();
    }));
    document.addEventListener('click', e => { if (!picker.contains(e.target)) close(); });
});

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

const purposeSelect = document.getElementById('purpose');
const purposeOtherWrap = document.getElementById('purpose-other-wrap');
const purposeOther = document.getElementById('purpose_other');

function syncPurposeOther() {
    const show = purposeSelect.value === 'Other';
    purposeOtherWrap.classList.toggle('hidden', !show);
    purposeOther.required = show;
    if (!show) purposeOther.value = '';
    syncStep3();
}
purposeSelect.addEventListener('change', syncPurposeOther);
syncPurposeOther();

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
    saveDraft();
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

function trimRate(n) {
    return parseFloat(Number(n).toFixed(6)).toString();
}

function ugx(n) {
    return 'UGX ' + n.toLocaleString(undefined, {maximumFractionDigits: 2});
}

function dollars(n) {
    return '$' + n.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1});
}

function showMoney(currency, n) {
    return currency === 'UGX' ? ugx(n) : '$' + n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// The two money boxes are typed as text so the separators can appear while the cashier types.
// Every read strips them and every send puts a plain number back, which is what the server
// validates as numeric.
function parseMoney(el) {
    const raw = el.value.replace(/,/g, '').trim();
    return raw === '' ? NaN : Number(raw);
}

function formatMoney(el) {
    const before = (el.value.slice(0, el.selectionStart).match(/[\d.]/g) || []).length;
    const parts = el.value.replace(/,/g, '').match(/^(\d*)(\.?)(\d{0,2})/);
    if (!parts) return;
    el.value = parts[1].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + parts[2] + parts[3];
    if (el.setSelectionRange) {
        let seen = 0;
        let caret = el.value.length;
        for (let i = 0; i < el.value.length; i++) {
            if (/[\d.]/.test(el.value[i])) seen++;
            if (seen === before) { caret = i + 1; break; }
        }
        el.setSelectionRange(before === 0 ? 0 : caret, before === 0 ? 0 : caret);
    }
}

function setMoney(el, value) {
    el.value = value;
    formatMoney(el);
}

document.querySelectorAll('[data-money]').forEach(el => {
    el.addEventListener('input', () => formatMoney(el));
    if (el.value) setMoney(el, el.value);
});

// Everything the pricing depends on, worked the same way the server works it: the charge comes
// out of the cash handed over, the dollars that travel are credited at one decimal place, and
// the yuan payout is priced from that rounded figure.
function figures() {
    const currency = document.getElementById('currency').value;
    const cash = parseMoney(document.getElementById('amount')) || 0;
    const charge = parseMoney(document.getElementById('charge_ugx')) || 0;
    const paidInUgx = currency === 'UGX';
    const sent = paidInUgx ? cash - charge : cash;
    const credited = sent > 0
        ? Number((paidInUgx && usdSellRate > 0 ? sent / usdSellRate : sent).toFixed(1))
        : 0;
    return {
        currency,
        cash,
        charge,
        paidInUgx,
        sent: sent > 0 ? sent : 0,
        credited,
        payout: rmbSellRate > 0 ? Number((credited * rmbSellRate).toFixed(2)) : 0
    };
}

// The card shows the working as well as the answer: the same three operations the server runs,
// in the order the money moves.
function derivation(f) {
    if (!f.sent) {
        return 'The charge is the whole of the money handed over, so nothing is left to send. It must be less than that.';
    }

    const steps = [];
    if (f.paidInUgx) {
        steps.push('Step 1: ' + ugx(f.cash) + ' handed over − ' + ugx(f.charge) + ' charge = ' + ugx(f.sent) + ' that travels');
        if (usdSellRate > 0) {
            steps.push('Step 2: ' + ugx(f.sent) + ' ÷ ' + trimRate(usdSellRate) + ' = ' + dollars(f.credited) + ' credited');
        } else {
            steps.push('Step 2: no dollar rate is set, so the dollars cannot be worked out yet');
        }
    } else {
        steps.push('Step 1: paid in dollars, so ' + dollars(f.credited) + ' travels and no shilling rate is used');
        steps.push('Step 2: the ' + ugx(f.charge) + ' charge is collected on top in shillings');
    }
    if (rmbSellRate > 0) {
        steps.push('Step 3: ' + dollars(f.credited) + ' × ' + trimRate(rmbSellRate) + ' = ¥' + f.payout.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' to the receiver');
    } else {
        steps.push('Step 3: no yuan rate is set, so the payout cannot be worked out yet');
    }

    return steps.join('   ·   ');
}

function calculate() {
    const f = figures();
    const typed = document.getElementById('amount').value.trim() !== '';

    document.getElementById('cash_help').textContent = f.paidInUgx
        ? 'Total received, charge included.'
        : 'All of these dollars travel.';

    // Until an amount is typed the card holds one line instead of a row of dashes.
    document.getElementById('calc_empty').classList.toggle('hidden', typed);
    document.getElementById('calc_rows').classList.toggle('hidden', !typed);
    document.getElementById('balance_strip').classList.toggle('hidden', !typed);

    if (typed) {
        document.getElementById('balance_amount').textContent = showMoney(f.currency, f.sent);
        document.getElementById('balance_note').textContent = f.paidInUgx
            ? 'This is the money turned into dollars, then into yuan.'
            : 'Paid in dollars, so all of them travel. The charge is collected on top in shillings.';
        document.getElementById('preview_usd').textContent = dollars(f.credited);
        document.getElementById('preview_rmb').textContent = '¥' + f.payout.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        document.getElementById('preview_formula').textContent = derivation(f);
    }

    syncStep3();
}

// The review step is only reachable with what the server will accept, so the hint names the one
// thing still missing rather than leaving a dead button.
function step3Hint() {
    const amount = document.getElementById('amount');
    const charge = document.getElementById('charge_ugx');
    const cash = parseMoney(amount);
    const fee = parseMoney(charge);
    const min = parseFloat(amount.getAttribute('min')) || 0;

    if (!amount.value.trim()) return 'Enter the money handed over.';
    if (!Number.isFinite(cash)) return 'The money handed over must be a number.';
    if (cash < min) return 'The smallest booking is ' + showMoney(document.getElementById('currency').value, min) + '.';
    if (!charge.value.trim()) return 'Enter the charge, or 0 if none was taken.';
    if (!Number.isFinite(fee) || fee < 0) return 'The charge cannot be negative.';
    if (document.getElementById('currency').value === 'UGX' && cash - fee <= 0) return 'The charge must be less than the money handed over.';
    if (purposeSelect.value === 'Other' && !purposeOther.value.trim()) return 'Say what the other reason is.';

    return '';
}

function syncStep3() {
    const hint = step3Hint();
    document.getElementById('next-3').disabled = hint !== '';
    document.getElementById('step3_hint').textContent = hint;
}

function titleCaseName(input) {
    input.value = input.value.toLowerCase().replace(/(^|[\s.'\-])\p{L}/gu, m => m.toUpperCase());
}

document.querySelectorAll('input[name="sender_name"], input[name="receiver_name"]').forEach(i => {
    i.addEventListener('blur', () => titleCaseName(i));
});

function populateReview() {
    buildFullContact('sender_prefix', 'sender_contact', 'sender_full_contact');
    buildFullContact('receiver_prefix', 'receiver_contact', 'receiver_full_contact');

    document.querySelectorAll('input[name="sender_name"], input[name="receiver_name"]').forEach(titleCaseName);

    document.getElementById('rev_sender_name').textContent = document.querySelector('[name=sender_name]').value;
    document.getElementById('rev_sender_contact').textContent = document.getElementById('sender_contact').value.trim() ? document.getElementById('sender_full_contact').value : 'Not provided';
    document.getElementById('rev_receiver_name').textContent = document.querySelector('[name=receiver_name]').value;
    document.getElementById('rev_receiver_contact').textContent = document.getElementById('receiver_contact').value.trim() ? document.getElementById('receiver_full_contact').value : 'Not provided';

    const f = figures();

    document.getElementById('rev_amount').textContent = showMoney(f.currency, f.cash);
    document.getElementById('rev_charge').textContent = ugx(f.charge);
    document.getElementById('rev_sent').textContent = showMoney(f.currency, f.sent);
    document.getElementById('rev_usd').textContent = f.credited ? dollars(f.credited) : '-';
    document.getElementById('rev_rmb').textContent = f.payout ? '¥' + f.payout.toLocaleString(undefined, {minimumFractionDigits: 2}) : '-';

    document.getElementById('rev_total_note').textContent = f.paidInUgx
        ? showMoney(f.currency, f.cash) + ' − UGX ' + f.charge.toLocaleString(undefined, {maximumFractionDigits: 2}) + ' charge'
        : 'Dollars travel in full; the charge is collected in shillings';

    const chosen = purposeSelect.value;
    const purpose = chosen === 'Other' ? (purposeOther.value.trim() || 'Other') : chosen;
    document.getElementById('rev_purpose').textContent = purpose ? 'Purpose: ' + purpose : '';
}

document.getElementById('currency').addEventListener('change', calculate);
document.getElementById('amount').addEventListener('input', calculate);
document.getElementById('charge_ugx').addEventListener('input', calculate);
document.getElementById('purpose_other').addEventListener('input', syncStep3);
calculate();

document.getElementById('transactionForm').addEventListener('submit', function(e) {
    buildFullContact('sender_prefix', 'sender_contact', 'sender_full_contact');
    buildFullContact('receiver_prefix', 'receiver_contact', 'receiver_full_contact');

    // The separators are for reading; the server gets a plain number.
    document.querySelectorAll('[data-money]').forEach(el => {
        if (el.value.trim()) el.value = String(parseMoney(el));
    });

    document.querySelector('[name=sender_contact]').value = document.getElementById('sender_contact').value.trim() ? document.getElementById('sender_full_contact').value : '';
    document.querySelector('[name=receiver_contact]').value = document.getElementById('receiver_contact').value.trim() ? document.getElementById('receiver_full_contact').value : '';

    draftCleared = true;
    localStorage.removeItem(DRAFT_KEY);

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.textContent = 'Processing...';
});

// ---- Draft save / restore (scoped to the signed-in user) ----
const DRAFT_KEY = @json($draftKey);
const KEEP_KEY = @json($keepKey);
localStorage.removeItem('pluspay_send_draft');
localStorage.removeItem('pluspay_keep_draft');
const DRAFT_FIELDS = ['sender_name', 'sender_contact', 'receiver_name', 'receiver_contact', 'currency', 'amount', 'charge_ugx', 'purpose', 'purpose_other', 'notes'];
let draftCleared = false;

function collectDraft() {
    const data = { step: currentStep };
    DRAFT_FIELDS.forEach(name => {
        const el = document.querySelector('[name="' + name + '"]');
        if (el) data[name] = el.value;
    });
    data.sender_prefix = document.getElementById('sender_prefix').value;
    data.receiver_prefix = document.getElementById('receiver_prefix').value;
    return data;
}

let draftTimer = null;
function saveDraft() {
    if (draftCleared) return;
    clearTimeout(draftTimer);
    draftTimer = setTimeout(() => {
        try { localStorage.setItem(DRAFT_KEY, JSON.stringify(collectDraft())); } catch (err) {}
    }, 300);
}

function readDraft() {
    try {
        const raw = localStorage.getItem(DRAFT_KEY);
        if (!raw) return null;
        const data = JSON.parse(raw);
        const hasContent = DRAFT_FIELDS.some(name => data[name] && data[name].trim() !== '');
        return hasContent ? data : null;
    } catch (err) {
        return null;
    }
}

const form = document.getElementById('transactionForm');
form.addEventListener('input', saveDraft);
form.addEventListener('change', saveDraft);

function pickPrefix(which, value) {
    const picker = document.querySelectorAll('.prefix-picker')[which === 'sender' ? 0 : 1];
    const opt = Array.from(picker.querySelectorAll('.prefix-option')).find(o => o.dataset.value === value);
    if (opt) opt.click();
}

function restoreDraft() {
    // After a validation error the server already repopulated the fields; leave the draft untouched
    // but open the step that holds the first problem so the message is not hidden behind step 1.
    if ({{ $serverRepopulated ? 'true' : 'false' }}) {
        for (const name of errorFields) {
            const el = document.querySelector('[name="' + name + '"]');
            const stepEl = el && el.closest('.wizard-step');
            if (stepEl) { showStep(parseInt(stepEl.id.split('-')[1], 10)); break; }
        }
        return;
    }
    const data = readDraft();
    if (!data) return;

    DRAFT_FIELDS.forEach(name => {
        const el = document.querySelector('[name="' + name + '"]');
        if (el && data[name] !== undefined) el.value = data[name];
    });
    document.querySelectorAll('[data-money]').forEach(el => { if (el.value) setMoney(el, el.value); });
    pickPrefix('sender', data.sender_prefix);
    pickPrefix('receiver', data.receiver_prefix);
    form.querySelectorAll('select').forEach(sel => sel.dispatchEvent(new Event('change', { bubbles: true })));

    document.getElementById('draftMessage').textContent =
        'Welcome back - we restored your unfinished transaction for ' + (data.sender_name || 'the sender') + '.';

    // Step 4 is the review screen; it needs live data, so restore up to step 3 max.
    const step = Math.min(parseInt(data.step) || 1, 3);
    if (step > 1) showStep(step);

    const bar = document.getElementById('draftBar');
    bar.classList.remove('hidden');
    document.getElementById('discardDraft').addEventListener('click', function () {
        localStorage.removeItem(DRAFT_KEY);
        location.reload();
    });
    document.getElementById('editLink').addEventListener('click', function () {
        // Keep editing on the fresh page without the banner; draft itself stays until submitted or discarded.
        sessionStorage.setItem(KEEP_KEY, '1');
    });

    if (sessionStorage.getItem(KEEP_KEY)) {
        sessionStorage.removeItem(KEEP_KEY);
        document.getElementById('draftBar').classList.add('hidden');
        return;
    }
}
restoreDraft();
</script>
@endpush
@endsection
