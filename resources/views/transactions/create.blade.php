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

            @if ($agents->isNotEmpty())
                <div class="mt-6 rounded-lg border border-green-200 bg-green-50/50 p-6">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label class="block text-sm font-medium text-gray-700" for="agent_id">Which agent took this money? *</label>
                        @include('transactions.partials.field-tip', [
                            'id' => 'agent-help-tip',
                            'text' => 'An agent standing beside the customer holds that cash in their own hand, so the transfer, the money and the commission are all written in their name. Pick the office counter only when the money came over the till, because then it is till money from the start.',
                        ])
                    </div>
                    <select name="agent_id" id="agent_id" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        <option value="" disabled {{ old('agent_id') === null ? 'selected' : '' }}>Agent or the counter</option>
                        @foreach ($agents as $agent)
                            <option value="{{ $agent->id }}" {{ (string) old('agent_id') === (string) $agent->id ? 'selected' : '' }}>{{ $agent->name }} ({{ $agent->countryLabel() ?: 'Uganda' }})</option>
                        @endforeach
                        <option value="counter" {{ old('agent_id') === 'counter' ? 'selected' : '' }}>The office counter</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Choose who holds the cash.</p>
                </div>
            @elseif (auth()->user()->can('view-all-transactions'))
                <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900">
                    There is no open agent on either desk to book for, and every transfer has to
                    belong to one.
                    @can('view-users')
                    Open <a href="{{ route('users.index') }}" class="underline font-medium">User Management</a>
                    and add an agent, or bring one back on,
                    @else
                    Ask the office to add an agent, or bring one back on,
                    @endcan
                    then start this booking again.
                </div>
            @endif
            <div class="mt-6 min-h-[20px] text-right text-sm text-red-700" data-step-hint role="status"></div>
            <div class="flex justify-end mt-2">
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
            <div class="mt-6 min-h-[20px] text-right text-sm text-red-700" data-step-hint role="status"></div>
            <div class="flex justify-between mt-2">
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
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label class="block text-sm font-medium text-gray-700" for="travels_out">Amount that travels</label>
                        @include('transactions.partials.field-tip', [
                            'id' => 'travels-help-tip',
                            'text' => 'The figure the booking is priced from. In shillings it is the money handed over less your charge; in dollars it is all of them. It fills itself in as you type, so there is nothing to enter here.',
                        ])
                    </div>
                    <input type="text" id="travels_out" readonly aria-readonly="true" placeholder="-"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-gray-100 text-gray-900 tabular-nums cursor-default">
                    <p class="text-xs text-gray-500 mt-1" id="travels_help">Fills itself in from the two boxes above.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="payment_method">Paid how? *</label>
                    <select name="payment_method" id="payment_method" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        <option value="">Choose how the customer paid</option>
                        @foreach(\App\Models\Transaction::PAYMENT_METHODS as $method)
                            <option value="{{ $method }}" {{ old('payment_method') === $method ? 'selected' : '' }}>{{ $method }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">How the money reached this desk.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="payment_reference" id="payment-reference-label">Confirmation number</label>
                    <input type="text" name="payment_reference" id="payment_reference" maxlength="60" value="{{ old('payment_reference') }}"
                        placeholder="e.g. MoMo ID 48219355" autocomplete="off"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <p class="text-xs text-gray-500 mt-1" id="payment_reference_help">The code from the payment alert, bank reference, or cheque number.</p>
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
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="notes">Notes (optional)</label>
                    <input type="text" name="notes" id="notes" value="{{ old('notes', 'Sending money to China') }}" maxlength="500"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
            </div>

            <div class="mt-6">
                @include('transactions.partials.calculation-card', [
                    'panel' => 'live',
                    'ratesLink' => true,
                    'collapsible' => true,
                ])
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
            <h2 class="text-lg font-semibold text-gray-800 mb-6">Review &amp; Confirm</h2>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div class="rounded-lg border border-gray-200 p-5">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Sender</h3>
                    <p class="mt-2 text-lg font-medium text-gray-900" id="rev_sender_name">-</p>
                    <p class="mt-1 text-sm text-gray-600 tabular-nums" id="rev_sender_contact_row"><span class="text-gray-400">Phone</span> <span id="rev_sender_contact">-</span></p>
                    @if ($agents->isNotEmpty())
                        <p class="mt-1 text-sm text-gray-600"><span class="text-gray-400">Held by</span> <span id="rev_holder">the office desk</span></p>
                    @endif
                </div>
                <div class="rounded-lg border border-gray-200 p-5">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-gray-500">Receiver</h3>
                    <p class="mt-2 text-lg font-medium text-gray-900" id="rev_receiver_name">-</p>
                    <p class="mt-1 text-sm text-gray-600 tabular-nums" id="rev_receiver_contact_row"><span class="text-gray-400">Phone</span> <span id="rev_receiver_contact">-</span></p>
                </div>
            </div>

            <p class="mt-3 text-sm text-gray-600 hidden" id="rev_payment"></p>
            <p class="mt-1 text-sm text-gray-600 hidden" id="rev_purpose"></p>

            <div class="mt-6">
                @include('transactions.partials.calculation-card', [
                    'panel' => 'review',
                    'heading' => 'Transaction Summary',
                    'collapsible' => true,
                ])
            </div>

            <div class="mt-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <button type="button" onclick="prevStep(4)" class="bg-gray-200 text-gray-700 px-8 py-3 rounded-lg hover:bg-gray-300 font-medium self-start sm:self-auto">
                    ← Edit Details
                </button>
                <div class="flex flex-col items-start gap-2 sm:items-end">
                    <p class="flex items-center gap-1.5 text-xs text-gray-600">
                        <svg class="h-4 w-4 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        The transfer is finished as soon as you book it.
                    </p>
                    <button type="submit" id="submitBtn" class="bg-green-800 text-white px-8 py-3 rounded-lg hover:bg-green-700 font-medium">
                        Book transfer
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

@push('styles')
<style>
    /* calcBump() skips this class entirely when the visitor asks for reduced motion. */
    @@keyframes calc-bump { from { opacity: .35; transform: translateY(3px); } to { opacity: 1; transform: none; } }
    .calc-bump { display: inline-block; animation: calc-bump .18s ease-out; }
</style>
@endpush

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

// Cash is counted across the counter, so it is the one payment with nothing to quote back.
// Every other way arrives with a confirmation number, and the box only asks for it when the
// method needs one.
const cashPayment = @json(\App\Models\Transaction::CASH_PAYMENT);
const paymentSelect = document.getElementById('payment_method');
const paymentRef = document.getElementById('payment_reference');
const paymentRefLabel = document.getElementById('payment-reference-label');
const paymentRefHelp = document.getElementById('payment_reference_help');

function paymentNeedsReference() {
    return paymentSelect.value !== '' && paymentSelect.value !== cashPayment;
}

function syncPaymentReference() {
    const needed = paymentNeedsReference();
    paymentRef.required = needed;
    paymentRefLabel.textContent = needed ? 'Confirmation number *' : 'Confirmation number';

    // Three states, because the desk has to be told something different before it answers,
    // when it says cash, and when it says anything else.
    if (needed) {
        paymentRefHelp.textContent = 'The code from the payment alert, bank reference, or cheque number.';
    } else if (paymentSelect.value === cashPayment) {
        paymentRefHelp.textContent = 'Cash is counted here, so there is nothing to quote. Fill this in only if you have a number.';
        paymentRef.value = '';
    } else {
        paymentRefHelp.textContent = 'Ask it once the way of paying is chosen.';
    }

    syncStep3();
}
paymentSelect.addEventListener('change', syncPaymentReference);
syncPaymentReference();

// A step that refuses to move has to say which box it is waiting on, in the words that box uses,
// because a red flash alone leaves her hunting for what went wrong.
const STEP_HINTS = {
    sender_name: "Enter the sender's name.",
    receiver_name: "Enter the receiver's name.",
    agent_id: 'Select who took this money.',
    payment_method: 'Say how the customer paid.',
    payment_reference: 'Write the confirmation number from the payment alert.',
};

function validateStep(step) {
    const el = document.getElementById('step-' + step);
    const hint = el.querySelector('[data-step-hint]');
    for (const input of el.querySelectorAll('[required]')) {
        if (input.value.trim()) continue;
        input.focus();
        // The searchable select mirrors these onto the box on screen, and its border is what
        // actually reads as wrong once the focus glow is on the same element.
        input.classList.add('field-invalid', 'ring-2', 'ring-red-400');
        setTimeout(() => input.classList.remove('field-invalid', 'ring-2', 'ring-red-400'), 2000);
        if (hint) {
            const label = input.labels?.[0]?.textContent.replace(/[*?]/g, '').trim();
            hint.textContent = STEP_HINTS[input.name] || (label ? 'Answer ' + label + '.' : 'Answer this before continuing.');
        }
        return false;
    }
    if (hint) hint.textContent = '';
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

// Everything the pricing depends on, worked the same way the server works it: the charge comes out
// of the cash handed over, the dollars are divided out at full precision, and the yuan payout is
// priced from those unrounded dollars. Rounding happens only where a figure is shown or stored.
function figures() {
    const currency = document.getElementById('currency').value;
    const cash = parseMoney(document.getElementById('amount')) || 0;
    const charge = parseMoney(document.getElementById('charge_ugx')) || 0;
    const paidInUgx = currency === 'UGX';
    const travels = paidInUgx ? cash - charge : cash;
    const exact = travels > 0 ? (paidInUgx && usdSellRate > 0 ? travels / usdSellRate : travels) : 0;

    return {
        currency,
        cash,
        charge,
        paidInUgx,
        sent: travels > 0 ? travels : 0,
        exact,
        credited: Number(exact.toFixed(2)),
        payout: rmbSellRate > 0 ? Number((exact * rmbSellRate).toFixed(2)) : 0
    };
}

// The card shows the working as well as the answer: the same three operations the server runs,
// in the order the money moves. One renderer fills both panels, so the Payment step and the
// Review step cannot disagree with each other or with what gets saved.
const calcPanels = Array.from(document.querySelectorAll('[data-calc-panel]'));
const CALC_LABELS = {
    live: { cash: 'Handed over', charge: 'Service charge', travels: 'Amount that travels', usd: 'Converted to USD', rmb: 'Converted to RMB' },
    review: { cash: 'Money handed over', charge: 'Charge taken out', travels: 'Money that travels', usd: 'Dollars credited', rmb: 'RMB to receiver' },
};
let calcState = { ready: false, message: '' };

function two(n) {
    return n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function rateLabel(n) {
    return Number(trimRate(n)).toLocaleString(undefined, {maximumFractionDigits: 6});
}

function calcBump(el) {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    el.classList.remove('calc-bump');
    void el.offsetWidth;
    el.classList.add('calc-bump');
}

function calcSet(panel, key, value) {
    const el = panel.querySelector('[data-c="' + key + '"]');
    if (!el || el.textContent === value) return;
    el.textContent = value;
    if (key === 'rmb') calcBump(el);
}

function renderCalcPanels(f) {
    calcPanels.forEach(panel => {
        const body = panel.querySelector('[data-c="body"]');
        const empty = panel.querySelector('[data-c="empty"]');
        const steps = panel.querySelector('[data-c="steps"]');
        const collapsed = panel.dataset.collapsed === 'true';

        body.classList.toggle('hidden', !calcState.ready || collapsed);
        empty.classList.toggle('hidden', calcState.ready);
        steps.classList.toggle('hidden', collapsed);

        if (!calcState.ready) {
            empty.textContent = calcState.message;
            return;
        }

        const labels = CALC_LABELS[panel.dataset.calcPanel] || CALC_LABELS.live;

        calcSet(panel, 'cash-label', labels.cash);
        calcSet(panel, 'cash', showMoney(f.currency, f.cash));
        calcSet(panel, 'charge-label', f.paidInUgx ? labels.charge : labels.charge + ', collected on top');
        calcSet(panel, 'charge', (f.paidInUgx ? '− ' : '') + ugx(f.charge));
        calcSet(panel, 'travels-label', labels.travels);
        calcSet(panel, 'travels', showMoney(f.currency, f.sent));
        calcSet(panel, 'usd-label', labels.usd);
        calcSet(panel, 'usd', '$' + two(f.credited));
        calcSet(panel, 'usd-hint', f.paidInUgx
            ? ugx(f.sent) + ' ÷ ' + rateLabel(usdSellRate)
            : 'Paid in dollars, so nothing is divided');
        calcSet(panel, 'rmb-label', labels.rmb);
        calcSet(panel, 'rmb-line', '¥' + two(f.payout));
        calcSet(panel, 'rmb-hint', '$' + two(f.credited) + ' × ' + rateLabel(rmbSellRate));
        calcSet(panel, 'rates', (f.paidInUgx ? '1 USD = UGX ' + rateLabel(usdSellRate) + ' · ' : '') + '1 USD = ' + rateLabel(rmbSellRate) + ' RMB');
        calcSet(panel, 'rmb', two(f.payout));
    });
}

calcPanels.forEach(panel => {
    const toggle = panel.querySelector('[data-c="toggle"]');
    if (!toggle) return;
    toggle.addEventListener('click', () => {
        const collapsing = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!collapsing));
        toggle.textContent = collapsing ? 'Show calculation' : 'Hide calculation';
        panel.dataset.collapsed = collapsing ? 'true' : 'false';
        renderCalcPanels(figures());
    });
});

function calculate() {
    const f = figures();
    const amountEl = document.getElementById('amount');
    const cash = parseMoney(amountEl);

    document.getElementById('cash_help').textContent = f.paidInUgx
        ? 'Total received, charge included.'
        : 'All of these dollars travel.';

    // The figure the booking is priced from, shown beside the charge it came from rather than only
    // further down the page. It stays blank until there is cash to work with so it never reads as a
    // booked zero, and it carries no name attribute, so nothing here can be posted by mistake.
    const travelsEl = document.getElementById('travels_out');
    travelsEl.value = f.cash > 0 ? showMoney(f.currency, f.sent) : '';
    document.getElementById('travels_help').textContent = f.paidInUgx
        ? 'Money handed over minus the charge.'
        : 'Every dollar handed over, since the charge comes on top.';

    // An amount or a rate that is missing leaves one plain line, never a row of zeros.
    if (amountEl.value.trim() === '' || !Number.isFinite(cash) || cash <= 0) {
        calcState = { ready: false, message: 'Enter an amount to see the calculation.' };
    } else if (f.sent <= 0) {
        calcState = { ready: false, message: 'The charge is the whole of the money handed over, so nothing is left to send.' };
    } else if (usdSellRate <= 0 || rmbSellRate <= 0) {
        calcState = { ready: false, message: 'Set the dollar and yuan selling rates to see the calculation.' };
    } else {
        calcState = { ready: true, message: '' };
    }

    renderCalcPanels(f);
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
    if (!paymentSelect.value) return 'Say how the customer paid.';
    if (paymentNeedsReference() && !paymentRef.value.trim()) return 'Write the confirmation number from the payment alert.';

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
    document.getElementById('rev_receiver_name').textContent = document.querySelector('[name=receiver_name]').value;

    // A contact nobody typed is left out rather than announced as missing.
    ['sender', 'receiver'].forEach(who => {
        const filled = document.getElementById(who + '_contact').value.trim() !== '';
        document.getElementById('rev_' + who + '_contact_row').classList.toggle('hidden', !filled);
        document.getElementById('rev_' + who + '_contact').textContent = document.getElementById(who + '_full_contact').value;
    });

    const chosen = purposeSelect.value;
    const purpose = chosen === 'Other' ? (purposeOther.value.trim() || 'Other') : chosen;
    const purposeRow = document.getElementById('rev_purpose');
    purposeRow.classList.toggle('hidden', purpose === '');
    purposeRow.textContent = purpose ? 'Purpose: ' + purpose : '';

    const paymentRow = document.getElementById('rev_payment');
    const reference = paymentRef.value.trim();
    paymentRow.classList.toggle('hidden', paymentSelect.value === '');
    paymentRow.textContent = paymentSelect.value
        ? 'Paid by ' + paymentSelect.value + (reference ? ', confirmation ' + reference : '')
        : '';

    // The review names the hand the cash is in, read off the chosen option rather than the id
    // the form carries.
    const holder = document.getElementById('rev_holder');
    if (holder) {
        const picked = document.getElementById('agent_id');
        const opt = picked.options[picked.selectedIndex];
        holder.textContent = picked.value && opt ? opt.textContent.trim() : 'the office desk';
    }

    // The summary is the same card the Payment step fills, so it is repainted rather than
    // rebuilt here and the two cannot end up showing different figures.
    calculate();
}

document.getElementById('currency').addEventListener('change', calculate);
document.getElementById('amount').addEventListener('input', calculate);
document.getElementById('charge_ugx').addEventListener('input', calculate);
document.getElementById('purpose_other').addEventListener('input', syncStep3);
document.getElementById('payment_reference').addEventListener('input', syncStep3);
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
    btn.textContent = 'Booking...';
});

// ---- Draft save / restore (scoped to the signed-in user) ----
const DRAFT_KEY = @json($draftKey);
const KEEP_KEY = @json($keepKey);
localStorage.removeItem('pluspay_send_draft');
localStorage.removeItem('pluspay_keep_draft');
const DRAFT_FIELDS = ['sender_name', 'sender_contact', 'receiver_name', 'receiver_contact', 'currency', 'amount', 'charge_ugx', 'purpose', 'purpose_other', 'payment_method', 'payment_reference', 'notes', 'agent_id'];
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
