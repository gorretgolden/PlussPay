@extends('layouts.app')

@section('title', 'Record Currency Exchange')

@section('content')
@php
    $actor = auth()->user();
    $defaultAccounts = collect($accounts)->mapWithKeys(fn ($a) => [
        $a->currency => $a->id,
    ]);
    // Dollars sit in two books, so the desk's own country picks which one the form opens on.
    $usdCode = ($actor->country ?? 'UG') === 'CN' ? \App\Models\Account::USD_CHINA : \App\Models\Account::USD_UGANDA;
    $defaults = [
        'UGX' => $defaultAccounts[\App\Models\Account::UGX_UGANDA] ?? null,
        'USD' => collect($accounts)->firstWhere('code', $usdCode)?->id,
        'RMB' => $defaultAccounts[\App\Models\Account::RMB_CHINA] ?? null,
    ];
@endphp
<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <p class="text-sm text-gray-500"><a href="{{ route('exchanges.index') }}" class="underline hover:text-gray-700">Currency Exchanges</a> / New</p>
        <h1 class="text-2xl font-bold text-gray-900">Record Currency Exchange</h1>
        <p class="text-gray-600">Money taken in and money handed back, the way the bureau does it. Both sides are written into the cash books as one deal.</p>
    </div>

    @unless($card)
    <div class="rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800 mb-4">
        No complete rate card is saved, so nothing can be suggested here. Enter both sides of the deal by hand. The margin will be left unpriced until the buying and selling rates are in Currency Rates.
    </div>
    @endunless

    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" action="{{ route('exchanges.store') }}" class="space-y-5" id="exchange-form">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="customer_id">Who did you exchange with?</label>
                    <select name="customer_id" id="customer_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        <option value="">Someone not in the directory</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}" data-name="{{ $customer->name }}"
                                @selected((int) old('customer_id', request('customer_id')) === $customer->id)>{{ $customer->name }}@if($customer->phone) &middot; {{ $customer->phone }}@endif</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Pick them to see everything exchanged for that person in one place.</p>
                </div>
                <div id="person_wrap">
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="person_name">Their name *</label>
                    <input type="text" name="person_name" id="person_name" value="{{ old('person_name') }}" maxlength="120"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1">Written on the record, so the deal still reads correctly if the directory changes later.</p>
                </div>
            </div>

            <div class="border-t pt-5">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">What they handed over</h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="in_currency">Currency</label>
                        <select name="in_currency" id="in_currency" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            @foreach($currencies as $currency)
                                <option value="{{ $currency }}" @selected(old('in_currency', 'UGX') === $currency)>{{ $currency }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="in_amount">Amount received <span class="text-red-500" id="in_amount_unit">UGX</span> *</label>
                        <input type="number" name="in_amount" id="in_amount" value="{{ old('in_amount') }}" step="0.01" min="0.01"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="in_account_id">Goes into</label>
                        <select name="in_account_id" id="in_account_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" data-currency="{{ $account->currency }}"
                                    @selected((int) old('in_account_id') === $account->id)>{{ $account->name }}, {{ $holdings[$account->id] }} held</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="fee">Commission <span class="text-red-500" id="fee_unit">UGX</span></label>
                        <input type="number" name="fee" id="fee" value="{{ old('fee') }}" step="0.01" min="0"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        <p class="text-xs text-gray-500 mt-1">Charged on the deal and kept out of this side. Leave empty when nothing was charged.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="payment_method">Paid how?</label>
                        <select name="payment_method" id="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            <option value="">Not recorded</option>
                            @foreach($paymentMethods as $method)
                                <option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ $method }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="exchange_date">Date</label>
                        <input type="date" name="exchange_date" id="exchange_date" value="{{ old('exchange_date', now()->toDateString()) }}"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    </div>
                </div>
            </div>

            <div class="border-t pt-5">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">What you handed back</h2>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="out_currency">Currency</label>
                        <select name="out_currency" id="out_currency" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            @foreach($currencies as $currency)
                                <option value="{{ $currency }}" @selected(old('out_currency', 'USD') === $currency)>{{ $currency }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="out_amount">Amount handed over <span class="text-red-500" id="out_amount_unit">USD</span> *</label>
                        <input type="number" name="out_amount" id="out_amount" value="{{ old('out_amount') }}" step="0.01" min="0.01"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        @cannot('override-exchange-rate')
                            <p class="text-xs text-gray-500 mt-1">Locked to the card price. An Admin can agree a different price with a customer.</p>
                        @endcannot
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="out_account_id">Comes out of</label>
                        <select name="out_account_id" id="out_account_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" data-currency="{{ $account->currency }}"
                                    @selected((int) old('out_account_id') === $account->id)>{{ $account->name }}, {{ $holdings[$account->id] }} held</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">The amount is filled in from the saved rates and the commission. Change it if the customer was given a different price, and say what that price was agreed on.</p>

                <div class="hidden mt-4" id="rate_reason_wrap">
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="rate_reason">Why is this price different from the card? *</label>
                    <input type="text" name="rate_reason" id="rate_reason" value="{{ old('rate_reason') }}" maxlength="255"
                        placeholder="e.g. Regular customer, price agreed with the manager"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1">Goes on the deal and in the trail. The card price it differs from is shown above.</p>
                </div>
            </div>

            <div class="rounded-2xl border border-green-200 bg-green-50 p-5 sm:p-6">
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <h4 class="text-sm font-semibold text-green-800">Live Calculation</h4>
                    @if($card)
                        <p class="text-xs text-green-700 tabular-nums" id="card_rates"></p>
                    @endif
                </div>

                <p class="mt-2 text-sm text-gray-600" id="card_empty">Enter what they handed over to see the calculation.</p>

                <div class="hidden" id="card_body">
                    <div class="mt-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                        <div>
                            <span class="block text-[11px] font-semibold uppercase tracking-wider text-gray-500">They get</span>
                            <div class="mt-1 text-3xl font-bold leading-none text-purple-700">
                                <span class="font-medium text-purple-400" id="hero_unit">$</span><span class="tabular-nums" id="hero_amount">0.00</span>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 tabular-nums sm:text-right" id="card_price"></p>
                    </div>

                    <ol class="mt-5 text-sm">
                        <li class="flex gap-3">
                            <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-800 text-xs font-bold text-white">1</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-4">
                                    <span class="text-gray-600">Handed over</span>
                                    <span class="font-semibold text-gray-900 tabular-nums" id="step_in">-</span>
                                </div>
                                <div class="mt-1 flex items-baseline justify-between gap-4">
                                    <span class="text-gray-600">Commission</span>
                                    <span class="font-semibold text-red-600 tabular-nums" id="step_fee">-</span>
                                </div>
                                <div class="mt-2 flex items-baseline justify-between gap-4 border-t-2 border-green-300 pt-2">
                                    <span class="text-gray-600">Amount that is priced</span>
                                    <span class="font-bold text-gray-900 tabular-nums" id="step_travels">-</span>
                                </div>
                            </div>
                        </li>
                        <li class="mt-3 flex gap-3 border-t border-green-200 pt-3">
                            <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-800 text-xs font-bold text-white">2</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-4">
                                    <span class="text-gray-600">Priced at the card</span>
                                    <span class="font-bold text-gray-900 tabular-nums" id="step_card">-</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500 tabular-nums" id="step_rule"></p>
                            </div>
                        </li>
                        <li class="mt-3 flex gap-3 border-t border-green-200 pt-3">
                            <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-800 text-xs font-bold text-white">3</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline justify-between gap-4">
                                    <span class="text-gray-600">Handed over to the customer</span>
                                    <span class="font-bold text-gray-900 tabular-nums" id="step_out">-</span>
                                </div>
                                <p class="mt-1 text-xs text-gray-500 tabular-nums" id="step_differs"></p>
                            </div>
                        </li>
                    </ol>

                    @can('view-profit')
                    <div class="mt-4 border-t border-green-200 pt-3">
                        <p class="text-xs text-green-800 tabular-nums" id="quote_work"></p>
                        <p class="font-semibold" id="quote_margin"></p>
                    </div>
                    @endcan
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="notes">Notes</label>
                    <input type="text" name="notes" id="notes" value="{{ old('notes') }}" maxlength="500" placeholder="ID number, where the money came from"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
            </div>

            <div class="hidden border-t pt-5" id="review">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">Review the deal</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Exchanged with</dt><dd class="text-gray-900 font-medium" id="r_person">-</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Date</dt><dd class="text-gray-900 font-medium" id="r_date">-</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Taken in</dt><dd class="text-gray-900 font-medium" id="r_in">-</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Commission kept</dt><dd class="text-gray-900 font-medium" id="r_fee">-</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Handed back</dt><dd class="text-gray-900 font-medium" id="r_out">-</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Price given</dt><dd class="text-gray-900 font-medium" id="r_rate">-</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Goes into</dt><dd class="text-gray-900 font-medium" id="r_in_account">-</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Comes out of</dt><dd class="text-gray-900 font-medium" id="r_out_account">-</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Paid how</dt><dd class="text-gray-900 font-medium" id="r_method">-</dd></div>
                    <div class="flex justify-between gap-3 sm:col-span-2" id="r_reason_row"><dt class="text-gray-500">Different price because</dt><dd class="text-gray-900 font-medium" id="r_reason">-</dd></div>
                </dl>
                <p class="text-xs text-gray-500 mt-3">Saving writes both sides into the cash books and checks the account handing over holds the money. Nothing is posted until you confirm.</p>
            </div>

            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <a href="{{ route('exchanges.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Cancel</a>
                <button type="button" id="back_step" class="hidden bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Back to editing</button>
                <button type="button" id="review_step" class="hidden bg-indigo-700 text-white px-6 py-2 rounded-md hover:bg-indigo-600">Review deal</button>
                <button type="submit" id="submit_btn" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700">Save Exchange</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const CARD = @json($card);
    const DEFAULT_ACCOUNT = @json($defaults);
    const CUSTOMERS = @json($customers->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->all());
    const CAN_SEE_PROFIT = @json($actor->can('view-profit'));
    const CAN_OVERRIDE = @json($actor->can('override-exchange-rate'));

    const inCurrency = document.getElementById('in_currency');
    const inAmount = document.getElementById('in_amount');
    const inAccount = document.getElementById('in_account_id');
    const feeInput = document.getElementById('fee');
    const outCurrency = document.getElementById('out_currency');
    const outAmount = document.getElementById('out_amount');
    const outAccount = document.getElementById('out_account_id');
    const customerSelect = document.getElementById('customer_id');
    const personName = document.getElementById('person_name');
    const personWrap = document.getElementById('person_wrap');
    const methodSelect = document.getElementById('payment_method');
    const dateInput = document.getElementById('exchange_date');
    const reasonWrap = document.getElementById('rate_reason_wrap');
    const reasonInput = document.getElementById('rate_reason');

    let amountTyped = outAmount.value !== '';

    // These four figures come off the saved card. The server works the same maths out again when
    // the deal is saved, so what is on screen is what gets stored.
    function worthInShillings(currency, amount) {
        if (!CARD || !(amount > 0)) return null;
        if (currency === 'UGX') return amount;
        if (currency === 'USD') return amount * CARD.usd_sell;
        if (currency === 'RMB') return (amount / CARD.rmb_buy) * CARD.usd_sell;
        return null;
    }

    function costInShillings(currency, amount) {
        if (!CARD || !(amount > 0)) return null;
        if (currency === 'UGX') return amount;
        if (currency === 'USD') return amount * CARD.usd_buy;
        if (currency === 'RMB') return (amount / CARD.rmb_sell) * CARD.usd_buy;
        return null;
    }

    function suggestedOut(inCur, amount, outCur) {
        if (!CARD || !(amount > 0) || inCur === outCur) return null;
        const pair = inCur + '->' + outCur;
        if (pair === 'UGX->USD') return amount / CARD.usd_sell;
        if (pair === 'USD->UGX') return amount * CARD.usd_buy;
        if (pair === 'UGX->RMB') return (amount / CARD.usd_sell) * CARD.rmb_sell;
        if (pair === 'RMB->UGX') return (amount / CARD.rmb_buy) * CARD.usd_buy;
        if (pair === 'USD->RMB') return amount * CARD.rmb_sell;
        if (pair === 'RMB->USD') return amount / CARD.rmb_buy;
        return null;
    }

    function rule(inCur, outCur) {
        if (!CARD || inCur === outCur) return 'No complete rate card is saved, so nothing can be quoted from the rates.';
        const sell = Math.round(CARD.usd_sell).toLocaleString();
        const buy = Math.round(CARD.usd_buy).toLocaleString();
        const pair = inCur + '->' + outCur;
        if (pair === 'UGX->USD') return 'Dollars are sold to customers at UGX ' + sell + ' each, so shillings divided by ' + sell + ' gives the dollars.';
        if (pair === 'USD->UGX') return 'Dollars are bought from customers at UGX ' + buy + ' each, so dollars times ' + buy + ' gives the shillings.';
        if (pair === 'UGX->RMB') return 'Shillings are turned into dollars at UGX ' + sell + ' to the dollar, and each dollar is paid out as ' + CARD.rmb_sell + ' yuan.';
        if (pair === 'RMB->UGX') return 'Yuan are turned into dollars at ' + CARD.rmb_buy + ' yuan to the dollar, and each dollar is worth UGX ' + buy + '.';
        if (pair === 'USD->RMB') return 'Each dollar is paid out as ' + CARD.rmb_sell + ' yuan.';
        if (pair === 'RMB->USD') return 'Each dollar costs ' + CARD.rmb_buy + ' yuan, so yuan divided by ' + CARD.rmb_buy + ' gives the dollars.';
        return '';
    }

    function show(currency, value) {
        if (value === null || value === undefined) return '';
        if (currency === 'UGX') return 'UGX ' + Math.round(value).toLocaleString();
        if (currency === 'USD') return '$' + value.toFixed(2);
        if (currency === 'RMB') return '¥' + value.toFixed(2);
        return currency + ' ' + value.toFixed(2);
    }

    function plain(currency, value) {
        if (value === null || value === undefined) return null;
        return currency === 'UGX' ? Math.round(value) : Number(value.toFixed(2));
    }

    function syncAccount(select, currency) {
        const chosen = select.options[select.selectedIndex];
        if (chosen && chosen.dataset.currency === currency) return;
        const preferred = DEFAULT_ACCOUNT[currency] ? String(DEFAULT_ACCOUNT[currency]) : null;
        const match = Array.from(select.options).find((o) => o.value === preferred)
            || Array.from(select.options).find((o) => o.dataset.currency === currency);
        if (match) select.value = match.value;
    }

    function label(select) {
        const option = select.options[select.selectedIndex];
        return option ? option.textContent.trim() : '-';
    }

    // One pass, and the review panel reads the same figures the card shows, so the two can never
    // disagree about what is about to be saved.
    let last = {};

    function refresh() {
        const inCur = inCurrency.value;
        const outCur = outCurrency.value;
        const handed = parseFloat(inAmount.value || '0');
        const fee = Math.max(0, parseFloat(feeInput.value || '0'));
        let paidOut = parseFloat(outAmount.value || '0');
        const travels = Math.max(0, handed - fee);

        syncAccount(inAccount, inCur);
        syncAccount(outAccount, outCur);

        const chosen = customerSelect.value !== '';
        personWrap.classList.toggle('hidden', chosen);
        personName.disabled = chosen;
        if (chosen) {
            const picked = CUSTOMERS.find((c) => String(c.id) === customerSelect.value);
            if (picked) personName.value = picked.name;
        }

        document.getElementById('in_amount_unit').textContent = inCur;
        document.getElementById('fee_unit').textContent = inCur;
        document.getElementById('out_amount_unit').textContent = outCur;
        document.getElementById('hero_unit').textContent = outCur === 'USD' ? '$' : (outCur === 'RMB' ? '¥' : 'UGX');

        const suggestion = suggestedOut(inCur, travels, outCur);
        if (suggestion !== null && !amountTyped) {
            outAmount.value = String(plain(outCur, suggestion));
            paidOut = parseFloat(outAmount.value || '0');
        }

        const pricedOut = suggestion === null ? null : plain(outCur, suggestion);
        const differs = pricedOut !== null && outAmount.value !== '' && Number(outAmount.value) !== pricedOut;

        reasonWrap.classList.toggle('hidden', !differs);
        reasonInput.disabled = !differs;

        last = {
            inCur: inCur, outCur: outCur, handed: handed, fee: fee, travels: travels,
            paidOut: paidOut, pricedOut: pricedOut, differs: differs,
        };

        const empty = document.getElementById('card_empty');
        const body = document.getElementById('card_body');
        const ready = handed > 0 && inCur !== outCur;

        empty.classList.toggle('hidden', ready);
        body.classList.toggle('hidden', !ready);

        if (ready) {
            document.getElementById('card_rates').textContent = CARD
                ? 'Card now: UGX ' + Math.round(CARD.usd_buy).toLocaleString() + ' / ' + Math.round(CARD.usd_sell).toLocaleString()
                    + ' a dollar, ' + CARD.rmb_buy + ' / ' + CARD.rmb_sell + ' yuan a dollar'
                : '';
            document.getElementById('step_in').textContent = show(inCur, handed);
            document.getElementById('step_fee').textContent = fee > 0 ? '- ' + show(inCur, fee) : 'None';
            document.getElementById('step_travels').textContent = show(inCur, travels);
            document.getElementById('step_card').textContent = pricedOut === null ? 'No card price' : show(outCur, pricedOut);
            document.getElementById('step_rule').textContent = rule(inCur, outCur);
            document.getElementById('step_out').textContent = paidOut > 0 ? show(outCur, paidOut) : '-';
            document.getElementById('hero_amount').textContent = paidOut > 0
                ? paidOut.toLocaleString(undefined, { minimumFractionDigits: outCur === 'UGX' ? 0 : 2, maximumFractionDigits: outCur === 'UGX' ? 0 : 2 })
                : '0.00';
            document.getElementById('step_differs').textContent = differs
                ? 'Agreed at the counter, ' + show(outCur, Math.abs(paidOut - pricedOut))
                    + (paidOut > pricedOut ? ' more than the card.' : ' less than the card.')
                : (paidOut > 0 && pricedOut !== null ? 'The card price, exactly.' : '');

            const rate = travels > 0 && paidOut > 0
                ? (travels >= paidOut ? travels / paidOut : paidOut / travels)
                : null;
            document.getElementById('card_price').textContent = rate
                ? (travels >= paidOut ? inCur + ' ' + rate.toFixed(4) + ' per 1 ' + outCur
                    : outCur + ' ' + rate.toFixed(4) + ' per 1 ' + inCur)
                : '';

            if (CAN_SEE_PROFIT) {
                const received = worthInShillings(inCur, handed);
                const cost = costInShillings(outCur, paidOut);
                const work = document.getElementById('quote_work');
                const margin = document.getElementById('quote_margin');

                if (received === null || cost === null) {
                    work.textContent = '';
                    margin.textContent = '';
                } else {
                    work.textContent = 'Worth ' + show('UGX', received) + ' taken in, less ' + show('UGX', cost)
                        + ' to replace the ' + show(outCur, paidOut) + ' handed over.';
                    const gain = received - cost;
                    margin.textContent = (gain < 0 ? 'This deal loses ' : 'This deal earns ')
                        + 'UGX ' + Math.abs(Math.round(gain)).toLocaleString() + '.';
                    margin.classList.toggle('text-red-700', gain < 0);
                    margin.classList.toggle('text-green-900', gain >= 0);
                }
            }
        }

        if (!reviewing) return;
        fillReview();
    }

    function fillReview() {
        document.getElementById('r_person').textContent = customerSelect.value !== '' || personName.value
            ? (personName.value || label(customerSelect)) : '-';
        document.getElementById('r_date').textContent = dateInput.value || '-';
        document.getElementById('r_in').textContent = show(last.inCur, last.handed) || '-';
        document.getElementById('r_fee').textContent = last.fee > 0 ? show(last.inCur, last.fee) : 'None';
        document.getElementById('r_out').textContent = show(last.outCur, last.paidOut) || '-';
        document.getElementById('r_rate').textContent = document.getElementById('card_price').textContent || '-';
        document.getElementById('r_in_account').textContent = label(inAccount);
        document.getElementById('r_out_account').textContent = label(outAccount);
        document.getElementById('r_method').textContent = methodSelect.value || 'Not recorded';
        document.getElementById('r_reason_row').classList.toggle('hidden', !last.differs);
        document.getElementById('r_reason').textContent = reasonInput.value || '-';
    }

    // Review is a step on the same page, not a second form: what is confirmed is exactly what the
    // card worked out, and nothing is posted until the confirm button is used.
    let reviewing = false;

    function setStep(toReview) {
        reviewing = toReview;
        document.getElementById('review').classList.toggle('hidden', !toReview);
        document.getElementById('back_step').classList.toggle('hidden', !toReview);
        document.getElementById('review_step').classList.toggle('hidden', toReview);
        document.getElementById('submit_btn').textContent = toReview ? 'Confirm and save' : 'Save Exchange';

        if (toReview) {
            fillReview();
            document.getElementById('review').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    document.getElementById('review_step').addEventListener('click', function () {
        if (!(last.handed > 0) || !(last.paidOut > 0) || last.inCur === last.outCur) {
            inAmount.focus();
            return;
        }

        if (last.differs && reasonInput.value.trim() === '') {
            reasonWrap.classList.remove('hidden');
            reasonInput.disabled = false;
            reasonInput.focus();
            return;
        }

        setStep(true);
    });

    document.getElementById('back_step').addEventListener('click', function () {
        setStep(false);
    });

    outAmount.addEventListener('input', function () { amountTyped = outAmount.value !== ''; refresh(); });
    outCurrency.addEventListener('change', function () { amountTyped = false; outAmount.value = ''; refresh(); });
    [inCurrency, inAccount, outAccount, customerSelect, methodSelect, dateInput].forEach((el) => el.addEventListener('change', refresh));
    [inAmount, feeInput, reasonInput].forEach((el) => el.addEventListener('input', refresh));

    // Someone without the ability to price off the card still sees the deal work itself out, but
    // the figure they hand over is the figure the card gives. The server checks it again on save.
    if (!CAN_OVERRIDE) {
        outAmount.readOnly = true;
        outAmount.classList.add('bg-gray-50');
    }

    refresh();
})();
</script>
@endpush
@endsection
