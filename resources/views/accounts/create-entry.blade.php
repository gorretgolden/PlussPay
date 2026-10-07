@extends('layouts.app')

@section('title', 'Add Ledger Entry')

@section('content')
@php
    $kinds = [
        'opening_balance' => ['Opening balance', 'What was already in the account before the system started tracking it. Enter it once, dated the day you begin.'],
        'money_in' => ['Money received', 'Cash that came in from outside: a customer payment, mobile money, money from the office.'],
        'money_out' => ['Money paid out', 'Cash that left and is gone: a recipient payout in China, rent, mobile money charges, commission.'],
        'transfer' => ['Move between my accounts', 'The same currency travelling between your own accounts, such as dollars sent from Uganda to China. Nothing is gained or lost.'],
        'exchange' => ['Buy one currency with another', 'Dollars sold for RMB in China, or shillings used to buy dollars here. Write both sides and the system works out the rate you got.'],
        'adjustment_in' => ['Correction (adds money)', 'Only for fixing a mistake or matching the bank balance. Say what was wrong.'],
        'adjustment_out' => ['Correction (removes money)', 'Only for fixing a mistake or matching the bank balance. Say what was wrong.'],
    ];
    $selectedAccount = collect($accounts)->firstWhere('id', (int) $selected);
@endphp
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <p class="text-sm text-gray-500"><a href="{{ route('accounts.index') }}" class="underline hover:text-gray-700">System Accounts</a> / Add Entry</p>
        <h1 class="text-2xl font-bold text-gray-900">Add Ledger Entry</h1>
        <p class="text-gray-600">Completed transfers post themselves here. Use this form for everything else the business actually did with cash.</p>
    </div>

    @if($errors->any())
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-4 text-sm">
        {{ $errors->first() }}
    </div>
    @endif

    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" action="{{ route('accounts.entries.store') }}" class="space-y-5">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">What happened? *</label>
                <select name="kind" id="kind" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    @foreach($kinds as $key => [$label, $hint])
                        <option value="{{ $key }}" @selected(old('kind') === $key) data-hint="{{ $hint }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1" id="kind_hint">{{ $kinds[old('kind', 'money_in')][1] }}</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" id="account_label">Account *</label>
                    <select name="account_id" id="account_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" data-currency="{{ $account->currency }}"
                                data-country="{{ $account->country }}" @selected((int) old('account_id', $selected ?? 0) === $account->id)>
                                {{ $account->name }} ({{ $account->currency }})
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Balance: {{ $selectedAccount ? $selectedAccount->currency.' '.number_format((float) $selectedAccount->balance(), $selectedAccount->currency === 'UGX' ? 0 : 2) : 'choose an account' }}</p>
                </div>
                <div id="counterparty_wrap">
                    <label class="block text-sm font-medium text-gray-700 mb-1" id="counterparty_label">Other account *</label>
                    <select name="counterparty_id" id="counterparty_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        @foreach($accounts as $account)
                            <option value="{{ $account->id }}" @selected((int) old('counterparty_id') === $account->id)>{{ $account->name }} ({{ $account->currency }})</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1" id="counterparty_hint"></p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" id="amount_label">Amount *</label>
                    <input type="number" name="amount" id="amount" value="{{ old('amount') }}" step="0.01" min="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1" id="amount_hint">The money leaving the account above.</p>
                </div>
                <div id="second_wrap">
                    <label class="block text-sm font-medium text-gray-700 mb-1" id="second_label">Amount received *</label>
                    <input type="number" name="second_amount" id="second_amount" value="{{ old('second_amount') }}" step="0.01" min="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1" id="rate_hint"></p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="entry_date" value="{{ old('entry_date', now()->toDateString()) }}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1">The day the money actually moved, so the daily balances stay right.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Reference</label>
                    <input type="text" name="reference" value="{{ old('reference') }}" maxlength="60" placeholder="Receipt, bank or MoMo number"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">What was it for? *</label>
                <input type="text" name="description" value="{{ old('description') }}" maxlength="255" required
                    placeholder="e.g. Dollars bought from ABSA for the China account"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <a href="{{ route('accounts.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Cancel</a>
                <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700">Save Entry</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const kindSelect = document.getElementById('kind');
    const accountSelect = document.getElementById('account_id');
    const counterpartySelect = document.getElementById('counterparty_id');
    const counterpartyWrap = document.getElementById('counterparty_wrap');
    const secondWrap = document.getElementById('second_wrap');
    const amountInput = document.getElementById('amount');
    const secondInput = document.getElementById('second_amount');
    const accounts = @json(collect($accounts)->mapWithKeys(fn ($a) => [$a->id => ['name' => $a->name, 'currency' => $a->currency]]));

    function refresh() {
        const kind = kindSelect.value;
        const option = kindSelect.selectedOptions[0];
        const account = accounts[accountSelect.value];
        const other = accounts[counterpartySelect.value];
        const paired = kind === 'transfer' || kind === 'exchange';

        document.getElementById('kind_hint').textContent = option.dataset.hint;
        counterpartyWrap.classList.toggle('hidden', !paired);
        secondWrap.classList.toggle('hidden', kind !== 'exchange');
        document.getElementById('counterparty_label').textContent = kind === 'exchange' ? 'Account receiving the new currency *' : 'Destination account *';
        document.getElementById('account_label').textContent = paired ? 'Account money leaves *' : 'Account *';
        document.getElementById('amount_label').textContent = paired ? (kind === 'exchange' ? amountLabel(kind, 'leaves') : 'Amount moved *') : (kind.endsWith('_in') ? 'Amount received *' : 'Amount paid out *');
        document.getElementById('second_label').textContent = amountLabel(kind, 'enters');
        document.getElementById('amount_hint').textContent = account ? account.currency + ' leaving ' + account.name + '.' : '';

        const sameCurrency = account && other && account.currency === other.currency;
        document.getElementById('counterparty_hint').textContent = paired
            ? (kind === 'transfer'
                ? (sameCurrency ? 'Both hold ' + account.currency + ', so nothing is gained or lost.' : 'Choose an account that also holds ' + account.currency + ' for a transfer.')
                : (sameCurrency ? 'For an exchange the two accounts must hold different currencies.' : account.currency + ' out, ' + other.currency + ' in.'))
            : '';

        const out = parseFloat(amountInput.value || '0');
        const in_ = parseFloat(secondInput.value || '0');
        const rateText = out > 0 && in_ > 0 && account && other
            ? 'Rate you got: ' + (in_ / out).toFixed(4) + ' ' + other.currency + ' per 1 ' + account.currency + '.'
            : '';
        document.getElementById('rate_hint').textContent = kind === 'exchange' ? rateText : '';
    }

    function amountLabel(kind, side) {
        const account = side === 'enters' ? accounts[counterpartySelect.value] : accounts[accountSelect.value];
        return (side === 'enters' ? 'Amount received in ' : 'Amount given up in ') + (account ? account.currency : '') + ' *';
    }

    [kindSelect, accountSelect, counterpartySelect, amountInput, secondInput].forEach(function (el) {
        el.addEventListener('change', refresh);
    });
    amountInput.addEventListener('input', refresh);
    secondInput.addEventListener('input', refresh);
    refresh();
})();
</script>
@endsection
