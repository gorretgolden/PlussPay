@extends('layouts.app')

@section('title', 'New Transaction')

@section('content')
@php
    $serverRepopulated = $errors->any();
    $errorFields = $errors->keys();
    $draftKey = 'pluspay_send_draft_u' . (auth()->id() ?? 'guest');
    $keepKey = 'pluspay_keep_draft_u' . (auth()->id() ?? 'guest');
    $trimRate = fn ($r) => rtrim(rtrim(number_format((float) $r, 6, '.', ''), '0'), '.');

    // Which way the money is going is decided before the first box is answered, because it changes
    // what money the desk takes in, what the receiver is handed, whose number comes first and what
    // the booking is even called. A hand who books only their own desk is pinned to the road that
    // desk takes cash in; someone who sees the whole book picks the road from the card they
    // followed. A rejected booking keeps the route rather than dropping it back to China.
    $chosen = old('destination', $destination ?? \App\Models\Transaction::TO_CHINA);
    $destination = $canChooseRoute
        ? ($chosen === \App\Models\Transaction::TO_UGANDA
            ? \App\Models\Transaction::TO_UGANDA
            : \App\Models\Transaction::TO_CHINA)
        : \App\Models\Transaction::destinationForDesk(auth()->user()?->country);
    $toUganda = $destination === \App\Models\Transaction::TO_UGANDA;

    // A receiver in Uganda is always paid shillings out of the till there, so that route has one
    // payout and nothing to choose; the China route lets the desk pick yuan or dollars.
    $intakeChoices = $toUganda ? ['RMB', 'USD'] : ['UGX', 'USD'];
    $payoutChoices = $toUganda ? ['UGX'] : ['RMB', 'USD'];
    $payoutOld = old('payout_currency');
    $payoutCurrency = in_array($payoutOld, $payoutChoices, true) ? $payoutOld : $payoutChoices[0];
@endphp
<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-900" id="page_title">{{ $toUganda ? 'Send Money to Uganda' : 'Send Money to China' }}</h1>
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
        <button type="button" class="flex flex-col items-center step-indicator cursor-pointer rounded-lg px-1 py-1 transition-opacity hover:opacity-80 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:ring-offset-2" data-step="1" onclick="goToStep(1)" aria-label="Go to step 1: Sender" aria-current="step">
            <span class="w-10 h-10 rounded-full bg-green-800 text-white flex items-center justify-center font-bold text-sm step-circle">1</span>
            <span class="text-xs mt-1 font-medium text-green-800 step-label">Sender</span>
        </button>
        <div class="flex-1 border-t-2 border-dashed border-gray-300 mx-2 step-line" data-line="1"></div>
        <button type="button" class="flex flex-col items-center step-indicator cursor-pointer rounded-lg px-1 py-1 transition-opacity hover:opacity-80 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:ring-offset-2" data-step="2" onclick="goToStep(2)" aria-label="Go to step 2: Receiver">
            <span class="w-10 h-10 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center font-bold text-sm step-circle">2</span>
            <span class="text-xs mt-1 font-medium text-gray-500 step-label">Receiver</span>
        </button>
        <div class="flex-1 border-t-2 border-dashed border-gray-300 mx-2 step-line" data-line="2"></div>
        <button type="button" class="flex flex-col items-center step-indicator cursor-pointer rounded-lg px-1 py-1 transition-opacity hover:opacity-80 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:ring-offset-2" data-step="3" onclick="goToStep(3)" aria-label="Go to step 3: Payment">
            <span class="w-10 h-10 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center font-bold text-sm step-circle">3</span>
            <span class="text-xs mt-1 font-medium text-gray-500 step-label">Payment</span>
        </button>
        <div class="flex-1 border-t-2 border-dashed border-gray-300 mx-2 step-line" data-line="3"></div>
        <button type="button" class="flex flex-col items-center step-indicator cursor-pointer rounded-lg px-1 py-1 transition-opacity hover:opacity-80 focus:outline-none focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:ring-offset-2" data-step="4" onclick="goToStep(4)" aria-label="Go to step 4: Confirm">
            <span class="w-10 h-10 rounded-full bg-gray-300 text-gray-600 flex items-center justify-center font-bold text-sm step-circle">4</span>
            <span class="text-xs mt-1 font-medium text-gray-500 step-label">Confirm</span>
        </button>
    </div>

    <form method="POST" action="{{ route('transactions.store') }}" id="transactionForm" data-lock data-lock-label="Booking...">
        @csrf
        <input type="hidden" name="form_id" value="{{ $formId }}">

        {{-- Step 1: Sender --}}
        <div class="wizard-step bg-white rounded-lg shadow p-8" id="step-1">
            <h2 class="text-lg font-semibold text-gray-800 mb-6">Sender Details</h2>

            <div class="mb-6">
                @if($canChooseRoute)
                <div class="flex items-center justify-between gap-2 mb-2">
                    <span class="block text-sm font-medium text-gray-700">Which way is the money going? *</span>
                    @include('transactions.partials.field-tip', [
                        'id' => 'destination-help-tip',
                        'text' => 'This fixes the rest of the form. Money going to China is handed over here in Uganda, in shillings or dollars, and the receiver is paid there. Money going to Uganda is handed over in China, in yuan or dollars, and the receiver is paid shillings out of the till here.',
                    ])
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="route-card flex items-start gap-3 rounded-lg border-2 bg-white p-4 cursor-pointer" data-dest="CN">
                        <input type="radio" name="destination" value="CN" class="mt-1 h-4 w-4 shrink-0 accent-green-800" {{ $destination === 'CN' ? 'checked' : '' }}>
                        <span>
                            <span class="block text-sm font-semibold text-gray-900">Money to a receiver in China</span>
                            <span class="block mt-1 text-xs text-gray-600">Cash is handed over here in Uganda, in shillings or dollars.</span>
                        </span>
                    </label>
                    <label class="route-card flex items-start gap-3 rounded-lg border-2 bg-white p-4 cursor-pointer" data-dest="UG">
                        <input type="radio" name="destination" value="UG" class="mt-1 h-4 w-4 shrink-0 accent-green-800" {{ $destination === 'UG' ? 'checked' : '' }}>
                        <span>
                            <span class="block text-sm font-semibold text-gray-900">Money to a receiver in Uganda</span>
                            <span class="block mt-1 text-xs text-gray-600">Cash is handed over in China, in yuan or dollars.</span>
                        </span>
                    </label>
                </div>
                @else
                {{-- The desk has one road, so there is nothing to choose. The hidden answer keeps
                     the rest of the form, the draft and the server all reading the same route. --}}
                <input type="hidden" name="destination" value="{{ $destination }}">
                <div class="rounded-lg border border-green-200 bg-green-50/60 px-4 py-3">
                    <p class="text-sm text-gray-700">
                        {{ $toUganda
                            ? 'Cash is handed over here in China, in yuan or dollars.'
                            : 'Cash is handed over here in Uganda, in shillings or dollars.' }}
                    </p>
                </div>
                @endif
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sender's Name *</label>
                    <input type="text" name="sender_name" value="{{ old('sender_name') }}" required maxlength="100"
                        placeholder="{{ $toUganda ? 'e.g. Zhang Wei' : 'e.g. John Okello' }}"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Sender's Phone</label>
                    <div class="flex">
                        @include('transactions.partials.prefix-picker', ['who' => 'sender', 'default' => $toUganda ? '+86' : '+256'])
                        <input type="tel" name="sender_contact" id="sender_contact" value="{{ old('sender_contact') }}" maxlength="15"
                            placeholder="{{ $toUganda ? '138 0000 0000' : '700 000 000' }}"
                            class="flex-1 min-w-0 px-4 py-3 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
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
                        placeholder="{{ $toUganda ? 'e.g. John Okello' : 'e.g. Zhang Wei' }}"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Receiver's Phone</label>
                    <div class="flex">
                        @include('transactions.partials.prefix-picker', ['who' => 'receiver', 'default' => $toUganda ? '+256' : '+86'])
                        <input type="tel" name="receiver_contact" id="receiver_contact" value="{{ old('receiver_contact') }}" maxlength="15"
                            placeholder="{{ $toUganda ? '700 000 000' : '138 0000 0000' }}"
                            class="flex-1 min-w-0 px-4 py-3 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
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
                        <label class="block text-sm font-medium text-gray-700" for="currency">Handed over in *</label>
                        @include('transactions.partials.field-tip', [
                            'id' => 'currency-help-tip',
                            'text' => 'Money going to China is handed over here in Uganda: shillings, where the charge comes out of that money, or dollars, where all of them travel and the charge is added on top in shillings. Money going to Uganda is handed over in China: yuan or dollars. Shillings are not offered there because that route pays shillings out rather than taking them in.',
                        ])
                    </div>
                    <select name="currency" id="currency" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        <option value="UGX" data-routes="CN" {{ old('currency') === 'UGX' ? 'selected' : '' }}>UGX</option>
                        <option value="USD" data-routes="CN,UG" {{ old('currency') === 'USD' ? 'selected' : '' }}>USD</option>
                        <option value="RMB" data-routes="UG" {{ old('currency') === 'RMB' ? 'selected' : '' }}>RMB</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1" id="currency_help">Choose what the customer pays in.</p>
                </div>
                <div>
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label class="block text-sm font-medium text-gray-700" id="cash_label" for="amount">Money handed over *</label>
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
                        <label class="block text-sm font-medium text-gray-700" id="charge_label" for="charge_ugx">Charge (UGX) *</label>
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
                    <p class="text-xs text-gray-500 mt-1" id="charge_help">{{ $defaultCharge !== '' ? 'Starts at the default charge from Settings.' : 'Type what you kept from this customer.' }}</p>
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
                <div id="payout_wrap">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label class="block text-sm font-medium text-gray-700" for="payout_currency">Receiver gets *</label>
                        @include('transactions.partials.field-tip', [
                            'id' => 'payout-help-tip',
                            'text' => 'Yuan is the usual payout in China: the dollars that transfer is worth go across, are changed there, and the receiver is handed yuan. Dollars when the receiver wants dollars, which means the money stops as dollars and is never changed. A receiver in Uganda is always paid shillings out of the till here, so there is nothing to choose on that route.',
                        ])
                    </div>
                    <select name="payout_currency" id="payout_currency" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-green-500 focus:border-green-500">
                        <option value="RMB" data-routes="CN" {{ $payoutCurrency === 'RMB' ? 'selected' : '' }}>RMB</option>
                        <option value="USD" data-routes="CN" {{ $payoutCurrency === 'USD' ? 'selected' : '' }}>USD</option>
                        <option value="UGX" data-routes="UG" {{ $payoutCurrency === 'UGX' ? 'selected' : '' }}>UGX</option>
                    </select>
                    <p class="text-xs text-gray-500 mt-1" id="payout_help">Choose what the receiver is handed.</p>
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
                <div id="payment_reference_wrap" class="{{ in_array(old('payment_method'), \App\Models\Transaction::MOBILE_MONEY_METHODS, true) ? '' : 'hidden' }}">
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="payment_reference" id="payment-reference-label">Confirmation number *</label>
                    <input type="text" name="payment_reference" id="payment_reference" maxlength="60" value="{{ old('payment_reference') }}"
                        placeholder="e.g. MoMo ID 48219355" autocomplete="off"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    <p class="text-xs text-gray-500 mt-1" id="payment_reference_help">The code from the mobile money alert.</p>
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
                    <input type="text" name="notes" id="notes" value="{{ old('notes', $toUganda ? 'Sending money to Uganda' : 'Sending money to China') }}" maxlength="500"
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

            <p class="mt-3 text-sm font-medium text-gray-700" id="rev_route"></p>
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

    /* The route the desk is on has to be obvious from across the counter, and the box the keyboard
       is on must show too, so both states are drawn here rather than left to the radio dot. */
    .route-card { border-color: #e5e7eb; }
    .route-card-on { border-color: #166534; background: #f0fdf4; }
    .route-card:focus-within { box-shadow: 0 0 0 3px rgb(22 163 74 / .35); }
</style>
@endpush

@push('scripts')
<script>
const usdSellRate = {{ $usdUgx && $usdUgx['sell'] > 0 ? $usdUgx['sell'] : 0 }};
const usdBuyRate = {{ $usdUgx && $usdUgx['buy'] > 0 ? $usdUgx['buy'] : 0 }};
const rmbSellRate = {{ $usdRmb && $usdRmb['sell'] > 0 ? $usdRmb['sell'] : 0 }};
const rmbBuyRate = {{ $usdRmb && $usdRmb['buy'] > 0 ? $usdRmb['buy'] : 0 }};
const errorFields = @json($errorFields);
let currentStep = 1;

// The route is decided before anything is typed, and it is the same choice the server prices on:
// money going to China is taken in here in shillings or dollars and handed over there in yuan or
// dollars; money going to Uganda is taken in there in yuan or dollars and handed over here in
// shillings. Everything on this page that says a word about money is read off these two records.
const ROUTES = {
    CN: {
        intake: ['UGX', 'USD'],
        payout: ['RMB', 'USD'],
        payoutChoice: true,
        title: 'Send Money to China',
        notes: 'Sending money to China',
        cashLabel: 'Money handed over *',
        cashHelp: { UGX: 'Total received, charge included.', USD: 'All of these dollars travel.' },
        travelsHelp: { UGX: 'Money handed over minus the charge.', USD: 'Every dollar handed over, since the charge comes on top.' },
        currencyHelp: 'Choose what the customer pays in.',
        chargeNote: 'Taken out of the money handed over.',
        phone: { sender: ['+256', '700 000 000'], receiver: ['+86', '138 0000 0000'] },
        name: { sender: 'e.g. John Okello', receiver: 'e.g. Zhang Wei' },
    },
    UG: {
        intake: ['RMB', 'USD'],
        payout: ['UGX'],
        payoutChoice: false,
        title: 'Send Money to Uganda',
        notes: 'Sending money to Uganda',
        cashLabel: 'Money handed over in China *',
        cashHelp: { RMB: 'Yuan counted over there. The whole of it is taken in.', USD: 'Dollars counted over there. The whole of them are taken in.' },
        travelsHelp: { RMB: 'The whole of the yuan crosses. The charge comes out of the payout instead.', USD: 'The whole of the dollars cross. The charge comes out of the payout instead.' },
        currencyHelp: 'Choose what the customer handed over in China.',
        chargeNote: 'Taken out of the shillings paid to the receiver.',
        phone: { sender: ['+86', '138 0000 0000'], receiver: ['+256', '700 000 000'] },
        name: { sender: 'e.g. Zhang Wei', receiver: 'e.g. John Okello' },
    },
};

const MONEY_NAME = { UGX: 'shillings', USD: 'dollars', RMB: 'yuan' };
const MONEY_GLYPH = { UGX: 'UGX ', USD: '$', RMB: '¥' };

// What the charge box was told before the route had anything to say about it.
const chargeHelpBase = document.getElementById('charge_help').textContent.trim();

function chosenRoute() {
    // An office picks the route from the two cards; a desk that only has one road carries it in a
    // hidden box, which is never checked and would otherwise read as China on every desk.
    const picked = document.querySelector('[name=destination]:checked')
        || document.querySelector('input[type=hidden][name=destination]');

    return picked && ROUTES[picked.value] ? picked.value : 'CN';
}

function pickedPayout(route) {
    const choices = ROUTES[route].payout;

    if (choices.length === 1) return choices[0];

    const picked = document.getElementById('payout_currency').value;

    return choices.includes(picked) ? picked : choices[0];
}

// Only the option the route cannot take is hidden, so the desk never has to work out which money
// belongs to which corridor, and a value the route no longer allows is moved off it rather than
// sent to the server to be refused.
function narrowOptions(select, allowed) {
    Array.from(select.options).forEach(opt => {
        opt.hidden = !allowed.includes(opt.value);
        opt.disabled = opt.hidden;
    });

    if (!allowed.includes(select.value)) {
        select.value = allowed[0];
    }
}

function applyRoute() {
    const route = chosenRoute();
    const other = route === 'CN' ? 'UG' : 'CN';
    const r = ROUTES[route];

    document.querySelectorAll('.route-card').forEach(card => {
        card.classList.toggle('route-card-on', card.dataset.dest === route);
    });

    narrowOptions(document.getElementById('currency'), r.intake);
    document.getElementById('payout_wrap').classList.toggle('hidden', !r.payoutChoice);
    narrowOptions(document.getElementById('payout_currency'), r.payout);

    document.querySelector('[name=sender_name]').placeholder = r.name.sender;
    document.querySelector('[name=receiver_name]').placeholder = r.name.receiver;
    document.getElementById('sender_contact').placeholder = r.phone.sender[1];
    document.getElementById('receiver_contact').placeholder = r.phone.receiver[1];

    // The code boxes follow the route only while they still hold the one the other route opens
    // with, so a prefix chosen by hand is never walked over.
    ['sender', 'receiver'].forEach(who => {
        const hidden = document.getElementById(who + '_prefix');
        if (hidden.value === ROUTES[other].phone[who][0]) pickPrefix(who, r.phone[who][0]);
    });

    document.getElementById('cash_label').textContent = r.cashLabel;
    document.getElementById('charge_label').textContent = route === 'UG'
        ? 'Charge, out of the payout (UGX) *'
        : 'Charge (UGX) *';
    document.getElementById('currency_help').textContent = r.currencyHelp;
    document.getElementById('charge_help').textContent = chargeHelpBase + ' ' + r.chargeNote;

    // The note the page opens with names the corridor, so it is only rewritten while it still says
    // what the other corridor would have said, or nothing at all.
    const notes = document.getElementById('notes');
    if (notes.value === '' || notes.value === ROUTES[other].notes) notes.value = r.notes;

    document.getElementById('page_title').textContent = r.title;

    calculate();
}

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

// Only the two mobile money answers arrive with a confirmation number. Cash is counted here, and
// a bank transfer or an answer under Other is booked without one, so the box stays out of sight
// until one of those two is chosen.
const mobileMoneyPayments = @json(\App\Models\Transaction::MOBILE_MONEY_METHODS);
const paymentSelect = document.getElementById('payment_method');
const paymentRef = document.getElementById('payment_reference');
const paymentRefWrap = document.getElementById('payment_reference_wrap');

function paymentNeedsReference() {
    return mobileMoneyPayments.includes(paymentSelect.value);
}

function syncPaymentReference() {
    const needed = paymentNeedsReference();
    paymentRefWrap.classList.toggle('hidden', !needed);
    paymentRef.required = needed;
    if (!needed) paymentRef.value = '';
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
        if (s === step) {
            ind.setAttribute('aria-current', 'step');
        } else {
            ind.removeAttribute('aria-current');
        }
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

// A step can be clicked from the strip. Going back is always allowed; going forward has to clear
// every step on the way, so a click cannot skip an empty box or a charge that leaves nothing to
// send.
function goToStep(target) {
    if (target === currentStep) return;

    if (target < currentStep) {
        showStep(target);
        return;
    }

    for (let step = currentStep; step < target; step++) {
        showStep(step);
        if (!validateStep(step)) return;
        if (step === 3 && step3Hint() !== '') return;
        if (step + 1 === 4) populateReview();
    }

    showStep(target);
}

function trimRate(n) {
    return parseFloat(Number(n).toFixed(6)).toString();
}

function ugx(n) {
    return 'UGX ' + n.toLocaleString(undefined, {maximumFractionDigits: 2});
}

function showMoney(currency, n) {
    if (currency === 'UGX') return ugx(n);
    if (currency === 'RMB') return '¥' + n.toLocaleString(undefined, {maximumFractionDigits: 2});

    return '$' + n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
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

// Everything the pricing depends on, worked the same way the server works it. Two shapes: money
// going to China has the charge taken out of what is handed over here, is divided into dollars at
// full precision, and those unrounded dollars price whatever the receiver is handed; money going
// to Uganda keeps the whole of what is handed over there, and the charge comes out of the shillings
// paid over here instead. Rounding happens only where a figure is shown or stored.
function figures() {
    const route = chosenRoute();
    const toUganda = route === 'UG';
    const currency = document.getElementById('currency').value;
    const payout = pickedPayout(route);
    const cash = parseMoney(document.getElementById('amount')) || 0;
    const charge = parseMoney(document.getElementById('charge_ugx')) || 0;
    const paidInUgx = currency === 'UGX';
    const inYuan = currency === 'RMB';

    // The figure the booking is priced from, in the money it was handed over.
    const travels = toUganda ? cash : (paidInUgx ? cash - charge : cash);

    // What that money is worth in dollars, the book's own measure, at full precision: shillings are
    // divided by the price the desk sells dollars at, yuan in China by the price it buys dollars
    // at, and money already in dollars is counted as it stands.
    const exact = travels > 0
        ? (paidInUgx && usdSellRate > 0 ? travels / usdSellRate : inYuan && rmbBuyRate > 0 ? travels / rmbBuyRate : travels)
        : 0;
    const dollars = Number(exact.toFixed(2));

    // The shillings those dollars are worth at the price the desk obtains them for, which is the
    // figure the payout into Uganda is cut from, and the yuan those dollars change into at the
    // price the desk hands them over for.
    const shillings = Number((exact * usdBuyRate).toFixed(2));
    const yuan = Number((exact * rmbSellRate).toFixed(2));
    const payoutAmount = toUganda
        ? Number((shillings - charge).toFixed(2))
        : (payout === 'USD' ? dollars : yuan);

    return {
        route, toUganda, currency, payout, cash, charge, paidInUgx, inYuan,
        turnsToYuan: !toUganda && payout === 'RMB',
        dollarsOut: !toUganda && payout === 'USD',
        travels: travels > 0 ? travels : 0,
        exact, dollars, shillings, yuan,
        payoutAmount: payoutAmount > 0 ? payoutAmount : 0,
    };
}

// Which price this route cannot be priced without. A route that never turns money does not need the
// price it never turns it by.
function missingPrices(f) {
    const missing = [];

    if (f.toUganda) {
        if (f.inYuan && rmbBuyRate <= 0) missing.push('USD to RMB');
        if (usdBuyRate <= 0) missing.push('USD to UGX');
    } else {
        if (f.paidInUgx && usdSellRate <= 0) missing.push('USD to UGX');
        if (f.turnsToYuan && rmbSellRate <= 0) missing.push('USD to RMB');
    }

    return missing;
}

function missingPriceMessage(f) {
    return 'No price is in use for ' + missingPrices(f).join(' or ')
        + '. Set it in System Settings, or bring an earlier price back on the Currency Rates page.';
}

// The card shows the working as well as the answer: the same operations the server runs, in the
// order the money moves. One renderer fills both panels, so the Payment step and the Review step
// cannot disagree with each other or with what gets saved.
const calcPanels = Array.from(document.querySelectorAll('[data-calc-panel]'));

// What each line of the card is called. The card is the same on both routes, so only the words
// move: money going to China is turned over and ends in the money the receiver is handed there,
// and money going to Uganda is priced from what was handed over there and ends in shillings here.
function words(panel, f) {
    const live = panel === 'live';

    if (f.toUganda) {
        return {
            cash: live ? (f.inYuan ? 'Yuan handed over' : 'Dollars handed over') : 'Money handed over in China',
            charge: live ? 'Service charge, out of the payout' : 'Charge out of the payout',
            travels: live ? 'Money that travels' : 'Money that crosses to Uganda',
            dollars: live ? 'Turned into dollars' : 'Dollars the money is worth',
            payout: live ? 'Shillings to the receiver' : 'UGX to receiver',
        };
    }

    return {
        cash: live ? 'Handed over' : 'Money handed over',
        charge: live ? 'Service charge' : 'Charge taken out',
        travels: live ? 'Amount that travels' : 'Money that travels',
        dollars: live ? 'Converted to USD' : 'Dollars credited',
        payout: f.dollarsOut
            ? (live ? 'Paid out as dollars' : 'USD to receiver')
            : (live ? 'Converted to RMB' : 'RMB to receiver'),
    };
}

function dollarHint(f) {
    if (f.toUganda) {
        return f.inYuan
            ? '¥' + two(f.cash) + ' ÷ ' + rateLabel(rmbBuyRate)
            : 'Handed over in dollars, so nothing is divided';
    }

    return f.paidInUgx
        ? ugx(f.travels) + ' ÷ ' + rateLabel(usdSellRate)
        : 'Paid in dollars, so nothing is divided';
}

function payoutHint(f) {
    if (f.toUganda) {
        return '$' + two(f.dollars) + ' × ' + rateLabel(usdBuyRate) + ' = ' + ugx(f.shillings) + ' − ' + ugx(f.charge);
    }

    return '$' + two(f.dollars) + ' × ' + rateLabel(rmbSellRate);
}

// The prices the booking was turned at, named by which side of the spread they are, because the
// desk earns on the difference between them and the working has to say which one it used.
function priceNotes(f) {
    const parts = [];

    if (f.toUganda) {
        if (f.inYuan) parts.push('1 USD = ' + rateLabel(rmbBuyRate) + ' yuan');
        if (usdBuyRate > 0) parts.push('1 USD = UGX ' + rateLabel(usdBuyRate));

        return parts.length ? parts.join(' · ') + ', the prices we take money in at' : 'Nothing is turned on this booking.';
    }

    if (f.paidInUgx && usdSellRate > 0) parts.push('1 USD = UGX ' + rateLabel(usdSellRate));
    if (f.turnsToYuan && rmbSellRate > 0) parts.push('1 USD = ' + rateLabel(rmbSellRate) + ' yuan');

    return parts.length
        ? parts.join(' · ') + ', the prices we pay out at'
        : 'Dollars handed over and dollars paid out, so no price is read.';
}

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
    if (key === 'payout-total') calcBump(el);
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

        const w = words(panel.dataset.calcPanel, f);
        const chargeComesOut = f.paidInUgx || f.toUganda;

        calcSet(panel, 'cash-label', w.cash);
        calcSet(panel, 'cash', showMoney(f.currency, f.cash));
        calcSet(panel, 'charge-label', chargeComesOut ? w.charge : w.charge + ', collected on top');
        calcSet(panel, 'charge', (chargeComesOut ? '− ' : '') + ugx(f.charge));
        calcSet(panel, 'travels-label', w.travels);
        calcSet(panel, 'travels', showMoney(f.currency, f.travels));
        calcSet(panel, 'usd-label', w.dollars);
        calcSet(panel, 'usd', '$' + two(f.dollars));
        calcSet(panel, 'usd-hint', dollarHint(f));

        // A receiver paid in dollars is paid the dollars step 2 already showed, so the third
        // working is the same figure twice and the step is dropped instead.
        panel.querySelector('[data-c="payout-step"]').classList.toggle('hidden', f.dollarsOut);

        calcSet(panel, 'payout-label', w.payout);
        calcSet(panel, 'payout-line', showMoney(f.payout, f.payoutAmount));
        calcSet(panel, 'payout-hint', f.dollarsOut ? '' : payoutHint(f));
        calcSet(panel, 'payout-glyph', MONEY_GLYPH[f.payout]);
        calcSet(panel, 'payout-total', f.payout === 'UGX'
            ? f.payoutAmount.toLocaleString(undefined, { maximumFractionDigits: 0 })
            : two(f.payoutAmount));
        calcSet(panel, 'rates', priceNotes(f));
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
    const r = ROUTES[f.route];

    document.getElementById('cash_help').textContent = r.cashHelp[f.currency] || '';

    // The figure the booking is priced from, shown beside the charge it came from rather than only
    // further down the page. It stays blank until there is cash to work with so it never reads as a
    // booked zero, and it carries no name attribute, so nothing here can be posted by mistake.
    const travelsEl = document.getElementById('travels_out');
    travelsEl.value = f.cash > 0 ? showMoney(f.currency, f.travels) : '';
    document.getElementById('travels_help').textContent = r.travelsHelp[f.currency] || '';

    // An amount or a price that is missing leaves one plain line, never a row of zeros.
    if (amountEl.value.trim() === '' || !Number.isFinite(cash) || cash <= 0) {
        calcState = { ready: false, message: 'Enter an amount to see the calculation.' };
    } else if (f.travels <= 0) {
        calcState = { ready: false, message: 'The charge is the whole of the money handed over, so nothing is left to send.' };
    } else if (missingPrices(f).length) {
        calcState = { ready: false, message: missingPriceMessage(f) };
    } else if (f.payoutAmount <= 0) {
        calcState = { ready: false, message: 'The charge is more than the money this booking can pay over, so nothing would be left for the receiver.' };
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
    const f = figures();

    if (!amount.value.trim()) return 'Enter the money handed over.';
    if (!Number.isFinite(cash)) return 'The money handed over must be a number.';
    if (cash < min) return 'The smallest booking is ' + showMoney(f.currency, min) + '.';
    if (!charge.value.trim()) return 'Enter the charge, or 0 if none was taken.';
    if (!Number.isFinite(fee) || fee < 0) return 'The charge cannot be negative.';
    if (f.paidInUgx && cash - fee <= 0) return 'The charge must be less than the money handed over.';
    if (missingPrices(f).length) return missingPriceMessage(f);
    if (f.payoutAmount <= 0) {
        return 'The charge is more than the ' + MONEY_NAME[f.payout] + ' this booking can pay over, so nothing would be left for the receiver.';
    }
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

    // The route is repeated in the desk's own words ahead of the money, because it is the one
    // choice on this booking that changes every figure under it, and a booking on the wrong corridor
    // is the one mistake nobody catches by looking at a total.
    const f = figures();
    document.getElementById('rev_route').textContent = 'Taken in as ' + MONEY_NAME[f.currency]
        + ' in ' + (f.toUganda ? 'China' : 'Uganda') + ', paid out as ' + MONEY_NAME[f.payout]
        + ' in ' + (f.toUganda ? 'Uganda' : 'China') + '.';

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

document.querySelectorAll('[name=destination]').forEach(radio => {
    radio.addEventListener('change', applyRoute);
});
document.getElementById('currency').addEventListener('change', calculate);
document.getElementById('payout_currency').addEventListener('change', calculate);
document.getElementById('amount').addEventListener('input', calculate);
document.getElementById('charge_ugx').addEventListener('input', calculate);
document.getElementById('purpose_other').addEventListener('input', syncStep3);
document.getElementById('payment_reference').addEventListener('input', syncStep3);
applyRoute();

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
});

// ---- Draft save / restore (scoped to the signed-in user) ----
const DRAFT_KEY = @json($draftKey);
const KEEP_KEY = @json($keepKey);
localStorage.removeItem('pluspay_send_draft');
localStorage.removeItem('pluspay_keep_draft');
const DRAFT_FIELDS = ['sender_name', 'sender_contact', 'receiver_name', 'receiver_contact', 'currency', 'payout_currency', 'amount', 'charge_ugx', 'purpose', 'purpose_other', 'payment_method', 'payment_reference', 'notes', 'agent_id'];
let draftCleared = false;

function collectDraft() {
    const data = { step: currentStep, destination: chosenRoute() };
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
        // These two boxes hold a choice even when nobody has typed anything, so they cannot be the
        // proof that a draft is somebody's unfinished booking.
        const alwaysFilled = ['currency', 'payout_currency'];
        const hasContent = DRAFT_FIELDS.some(name => !alwaysFilled.includes(name)
            && data[name] && data[name].trim() !== '');
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

    // The route comes back before anything the route rewrites, so the corridor the desk was on is
    // the one that lays out the boxes, and the drafted money codes win over its defaults.
    const drafted = document.querySelector('[name=destination][value="' + (data.destination || 'CN') + '"]');
    if (drafted) drafted.checked = true;
    applyRoute();

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
