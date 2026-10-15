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
    $rule = \App\Services\ExchangeCalculator::rule($card, 'UGX', 'USD');
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
        <form method="POST" action="{{ route('exchanges.store') }}" class="space-y-5">
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
                                    @selected((int) old('in_account_id') === $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                            @endforeach
                        </select>
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
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="out_account_id">Comes out of</label>
                        <select name="out_account_id" id="out_account_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" data-currency="{{ $account->currency }}"
                                    @selected((int) old('out_account_id') === $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">The amount is filled in from the saved rates. Change it if the customer was given a different price, and the deal below follows your figure.</p>
            </div>

            <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-900 space-y-1">
                <p id="quote_rule">{{ $rule ?? 'No complete rate card is saved, so nothing can be quoted from the rates.' }}</p>
                <p class="text-xs text-green-800" id="quote_work"></p>
                <p class="font-semibold" id="quote_margin"></p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="exchange_date">Date</label>
                    <input type="date" name="exchange_date" id="exchange_date" value="{{ old('exchange_date', now()->toDateString()) }}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="notes">Notes</label>
                    <input type="text" name="notes" id="notes" value="{{ old('notes') }}" maxlength="500" placeholder="ID number, where the money came from"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <a href="{{ route('exchanges.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Cancel</a>
                <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700">Save Exchange</button>
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

    const inCurrency = document.getElementById('in_currency');
    const inAmount = document.getElementById('in_amount');
    const inAccount = document.getElementById('in_account_id');
    const outCurrency = document.getElementById('out_currency');
    const outAmount = document.getElementById('out_amount');
    const outAccount = document.getElementById('out_account_id');
    const customerSelect = document.getElementById('customer_id');
    const personName = document.getElementById('person_name');
    const personWrap = document.getElementById('person_wrap');

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

    function syncAccount(select, currency) {
        const chosen = select.options[select.selectedIndex];
        if (chosen && chosen.dataset.currency === currency) return;
        const preferred = DEFAULT_ACCOUNT[currency] ? String(DEFAULT_ACCOUNT[currency]) : null;
        const match = Array.from(select.options).find((o) => o.value === preferred)
            || Array.from(select.options).find((o) => o.dataset.currency === currency);
        if (match) select.value = match.value;
    }

    function refresh() {
        const inCur = inCurrency.value;
        const outCur = outCurrency.value;
        const amount = parseFloat(inAmount.value || '0');
        const handed = parseFloat(outAmount.value || '0');

        syncAccount(inAccount, inCur);
        syncAccount(outAccount, outCur);

        const chosen = customerSelect.value !== '';
        personWrap.classList.toggle('hidden', chosen);
        personName.disabled = chosen;
        if (chosen) {
            const picked = CUSTOMERS.find((c) => String(c.id) === customerSelect.value);
            if (picked) personName.value = picked.name;
        }

        const suggestion = suggestedOut(inCur, amount, outCur);
        if (suggestion !== null && !amountTyped) {
            outAmount.value = String(outCur === 'UGX' ? Math.round(suggestion) : Number(suggestion.toFixed(2)));
        }

        document.getElementById('quote_rule').textContent = rule(inCur, outCur);

        if (!@json(auth()->user()->can('view-profit'))) return;
        const received = worthInShillings(inCur, amount);
        const paid = costInShillings(outCur, handed);
        const work = document.getElementById('quote_work');
        const margin = document.getElementById('quote_margin');

        if (received === null || paid === null || inCur === outCur) {
            work.textContent = handed > 0 && amount > 0 && inCur === outCur
                ? 'The two sides have to be different currencies.' : '';
            margin.textContent = '';
            return;
        }

        work.textContent = 'Worth ' + show('UGX', received) + ' taken in, less ' + show('UGX', paid)
            + ' to replace the ' + show(outCur, handed) + ' handed over.';

        const gain = received - paid;
        margin.textContent = (gain < 0 ? 'This deal loses ' : 'This deal earns ')
            + 'UGX ' + Math.abs(Math.round(gain)).toLocaleString() + '.';
        margin.classList.toggle('text-red-700', gain < 0);
        margin.classList.toggle('text-green-900', gain >= 0);
    }

    outAmount.addEventListener('input', function () { amountTyped = outAmount.value !== ''; refresh(); });
    outCurrency.addEventListener('change', function () { amountTyped = false; outAmount.value = ''; refresh(); });
    [inCurrency, inAccount, outAccount, customerSelect].forEach((el) => el.addEventListener('change', refresh));
    inAmount.addEventListener('input', refresh);
    refresh();
})();
</script>
@endpush
@endsection
