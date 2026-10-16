@extends('layouts.app')

@section('title', 'Record Expense')

@section('content')
@php
    $byId = collect($accounts)->keyBy('id');
    $balances = $byId->map(fn ($a) => ['currency' => $a->currency, 'balance' => (float) $a->balance()])->all();
    $chosen = $byId->get((int) old('account_id', $defaultAccountId));
@endphp
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <p class="text-sm text-gray-500"><a href="{{ route('expenses.index') }}" class="underline hover:text-gray-700">Expenses</a> / New</p>
        <h1 class="text-2xl font-bold text-gray-900">Record Expense</h1>
        <p class="text-gray-600">{{ $seesAll
            ? 'Money the business spent on itself. Saving this takes the amount straight out of the account that paid for it.'
            : 'Money you paid out while working. Saving this takes the amount straight out of the cash you are holding, so it comes off what you hand in.' }}</p>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" action="{{ route('expenses.store') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="category_id">What was it spent on? *</label>
                    <select name="category_id" id="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        @foreach($categories as $id => $name)
                            <option value="{{ $id }}" @selected((int) old('category_id', $defaultCategoryId) === (int) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Pick the head it falls under. Anything that does not fit goes under Other with the detail in the description. @can('manage-expense-categories')<a href="{{ route('expense-categories.index') }}" class="underline hover:text-gray-700">Manage the heads</a>@endcan</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="expense_date">Date paid *</label>
                    <input type="date" name="expense_date" id="expense_date" value="{{ old('expense_date', now()->toDateString()) }}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="description">Details *</label>
                <input type="text" name="description" id="description" value="{{ old('description') }}" maxlength="255" required
                    placeholder="{{ $seesAll ? 'e.g. Kampala office rent for October' : 'e.g. Boda to the customer in Mbale' }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="amount">Amount paid <span class="text-red-500" id="amount_unit">{{ $chosen?->currency ?? 'UGX' }}</span> *</label>
                    <input type="number" name="amount" id="amount" value="{{ old('amount') }}" step="0.01" min="0.01"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="paid_via">Paid how?</label>
                    <select name="paid_via" id="paid_via" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        <option value="">Not recorded</option>
                        @foreach($paidVia as $method)
                            <option value="{{ $method }}" @selected(old('paid_via') === $method)>{{ $method }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="account_id">Paid out of *</label>
                <select name="account_id" id="account_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    @foreach($accounts as $account)
                        {{-- A wallet is already named after its currency; a company account is not. --}}
                        <option value="{{ $account->id }}" data-currency="{{ $account->currency }}" @selected((int) old('account_id', $defaultAccountId) === $account->id)>
                            {{ $account->name }}{{ str_contains($account->name, $account->currency) ? '' : ' ('.$account->currency.')' }}
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1" id="account_note"></p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="notes">Note</label>
                    <input type="text" name="notes" id="notes" value="{{ old('notes') }}" maxlength="500" placeholder="Invoice or MoMo number, who was paid"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="receipt">Slip or invoice</label>
                    <input type="file" name="receipt" id="receipt" accept=".pdf,.jpg,.jpeg,.png"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm bg-white file:mr-3 file:px-3 file:py-1 file:rounded file:border-0 file:bg-green-50 file:text-green-800 file:text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1">Optional. A PDF or a photo of the slip, up to 5MB. Keep it with the entry.</p>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <a href="{{ route('expenses.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Cancel</a>
                <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700">Save Expense</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const BALANCES = @json($balances);
    const accountSelect = document.getElementById('account_id');
    const amountInput = document.getElementById('amount');

    function show(currency, value) {
        if (currency === 'UGX') return 'UGX ' + Math.round(value).toLocaleString();
        if (currency === 'USD') return '$' + value.toFixed(2);
        if (currency === 'RMB') return '¥' + value.toFixed(2);
        return currency + ' ' + value.toFixed(2);
    }

    function refresh() {
        const held = BALANCES[accountSelect.value];
        const amount = parseFloat(amountInput.value || '0');
        document.getElementById('amount_unit').textContent = held ? held.currency : '';

        if (!held) return;

        const after = held.balance - amount;
        document.getElementById('account_note').textContent = amount > 0
            ? 'Holds ' + show(held.currency, held.balance) + ' now, and ' + show(held.currency, after) + ' after this is paid.'
            : 'Holds ' + show(held.currency, held.balance) + ' now.';
    }

    accountSelect.addEventListener('change', refresh);
    amountInput.addEventListener('input', refresh);
    refresh();
})();
</script>
@endpush
@endsection
