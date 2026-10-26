@extends('layouts.app')

@section('title', $expense ? 'Edit Expense' : 'Record Expense')

@section('content')
@php
    $byId = collect($accounts)->keyBy('id');
    $balances = $byId->map(fn ($a) => [
        'currency' => $a->currency,
        'balance' => (float) $a->balance(),
        'wallet' => $a->isWallet(),
    ])->all();
    $chosen = $byId->get((int) old('account_id', $expense?->account_id ?? $defaultAccountId));

    // A wallet is already named after its currency, so the figure inside its brackets drops the
    // code and reads as the amount of money it is.
    $heldLabel = fn ($account) => str_replace(
        $account->currency.' ',
        '',
        \App\Models\Account::format($account->currency, $account->balance()),
    );
@endphp
<div class="max-w-2xl mx-auto">
    <div class="mb-6">
        <p class="text-sm text-gray-500"><a href="{{ route('expenses.index') }}" class="underline hover:text-gray-700">Expenses</a> / {{ $expense ? $expense->reference : 'New' }}</p>
        <h1 class="text-2xl font-bold text-gray-900">{{ $expense ? 'Edit Expense' : 'Record Expense' }}</h1>
        @if($expense)
            <p class="text-gray-600">Fixing what this entry says: the category it falls under, the details, the date, how it was paid and the slip. The money it moved is already on the books and stays as it was posted.</p>
        @else
        <p class="text-gray-600">{{ $seesAll
            ? 'Money the business spent on itself. Saving this takes the amount straight out of the account that paid for it.'
            : 'Money you paid out while working. Saving this takes the amount straight out of the cash you are holding, so it comes off what you hand in.' }}</p>
        @endif
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" action="{{ $expense ? route('expenses.update', $expense) : route('expenses.store') }}" enctype="multipart/form-data" class="space-y-5" data-lock data-lock-label="Saving...">
            @csrf
            @if($expense) @method('PUT') @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="category_id">What was it spent on? *</label>
                    <select name="category_id" id="category_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        @foreach($categories as $id => $name)
                            <option value="{{ $id }}" @selected((int) old('category_id', $expense?->category_id ?? $defaultCategoryId) === (int) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Pick the category it falls under. Anything that does not fit goes under Other with the detail in the description. @can('manage-expense-categories')<a href="{{ route('expense-categories.index') }}" class="underline hover:text-gray-700">Manage the categories</a>@endcan</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="expense_date">Date paid *</label>
                    <input type="date" name="expense_date" id="expense_date" value="{{ old('expense_date', $expense?->expense_date?->toDateString() ?? now()->toDateString()) }}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="description">Details *</label>
                <input type="text" name="description" id="description" value="{{ old('description', $expense?->description) }}" maxlength="255" required
                    placeholder="{{ $seesAll ? 'e.g. Kampala office rent for October' : 'e.g. Boda to the customer in Mbale' }}"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>

            @if($expense)
            {{-- The cash has already left, so the figure and the account it left from are part of the
                 trail. Fixing a wrong amount means cancelling this entry and recording it again,
                 which keeps both the mistake and its correction on the books. --}}
            <div class="bg-gray-50 border border-gray-200 rounded-md p-4">
                <dl class="text-sm space-y-1">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600">Amount paid</dt>
                        <dd class="text-gray-900 tabular-nums font-medium">{{ \App\Models\Account::format($expense->currency, $expense->amount) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-600">Paid out of</dt>
                        <dd class="text-gray-900">{{ $expense->account?->name ?? 'Deleted account' }}</dd>
                    </div>
                </dl>
                <p class="text-xs text-gray-500 mt-2">The amount and the account it came out of stay as they were paid. If either is wrong, cancel this entry and record it again so the books keep both lines.</p>
            </div>
            @else
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="account_id">Paid out of *</label>
                <select name="account_id" id="account_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    @foreach($accounts as $account)
                        {{-- The drawer names itself and says what is in it, because the currency of
                             the amount below is decided here and nowhere else. --}}
                        <option value="{{ $account->id }}" data-currency="{{ $account->currency }}" data-balance="{{ (float) $account->balance() }}"
                            @selected((int) old('account_id', $defaultAccountId) === $account->id)>
                            {{ $account->name }} ({{ $heldLabel($account) }})
                        </option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-1" id="account_note"></p>
            </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @if(! $expense)
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="amount">Amount paid <span class="text-red-500" id="amount_unit">{{ $chosen?->currency ?? 'UGX' }}</span> *</label>
                    <input type="number" name="amount" id="amount" value="{{ old('amount') }}" step="0.01" min="{{ $bounds[$chosen?->currency ?? 'UGX']['min'] ?? 0 }}"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1">Smallest entry this desk takes: <span id="amount_floor">{{ \App\Models\Account::format($chosen?->currency ?? 'UGX', $bounds[$chosen?->currency ?? 'UGX']['min'] ?? 0) }}</span>.</p>
                </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="paid_via">Paid how?</label>
                    <select name="paid_via" id="paid_via" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                        <option value="">Choose how it was paid</option>
                        @foreach($paidVia as $method)
                            <option value="{{ $method }}" @selected(old('paid_via', $expense?->paid_via) === $method)>{{ $method }}</option>
                        @endforeach
                    </select>
                    @if($expense)<p class="text-xs text-gray-500 mt-1">How it was settled, as far as you know it.</p>@endif
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="notes">Note</label>
                    <input type="text" name="notes" id="notes" value="{{ old('notes', $expense?->notes) }}" maxlength="500" placeholder="Invoice or MoMo number, who was paid"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="receipt">Slip or invoice</label>
                    @if($expense?->receipt_file)
                        <p class="text-sm text-gray-900"><a href="{{ route('expenses.receipt', $expense) }}" class="text-green-800 hover:underline">The slip on this entry</a> is kept. Choosing a new file replaces it.</p>
                    @endif
                    <input type="file" name="receipt" id="receipt" accept=".pdf,.jpg,.jpeg,.png"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm bg-white file:mr-3 file:px-3 file:py-1 file:rounded file:border-0 file:bg-green-50 file:text-green-800 file:text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                    <p class="text-xs text-gray-500 mt-1">Optional. A PDF or a photo of the slip, up to 5MB. Keep it with the entry.</p>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <a href="{{ $expense ? route('expenses.show', $expense) : route('expenses.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Cancel</a>
                <button type="submit" class="bg-green-800 text-white px-6 py-2 rounded-md hover:bg-green-700">{{ $expense ? 'Save changes' : 'Save Expense' }}</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const BALANCES = @json($balances);
    const BOUNDS = @json($bounds);
    const accountSelect = document.getElementById('account_id');
    const amountInput = document.getElementById('amount');

    // An edit changes the words, not the money, so the boxes that decide the money are not on
    // the page at all and there is nothing here to keep in step.
    if (!accountSelect || !amountInput) return;

    function show(currency, value) {
        const grouped = currency === 'UGX'
            ? Math.round(value).toLocaleString()
            : value.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});

        if (currency === 'UGX') return 'UGX ' + grouped;
        if (currency === 'USD') return '$' + grouped;
        if (currency === 'RMB') return '¥' + grouped;
        return currency + ' ' + grouped;
    }

    function refresh() {
        const held = BALANCES[accountSelect.value];
        const bounds = BOUNDS[held.currency] || {min: 0, max: 0};
        const amount = parseFloat(amountInput.value || '0');
        if (!held) return;

        document.getElementById('amount_unit').textContent = held.currency;
        document.getElementById('amount_floor').textContent = show(held.currency, bounds.min);
        amountInput.min = bounds.min;
        // A wallet's ceiling is its own balance; every other account answers to the
        // desk's largest figure for its currency.
        amountInput.max = held.wallet ? held.balance : (bounds.max || '');

        const note = document.getElementById('account_note');
        const after = held.balance - amount;

        if (amount > 0 && after < 0) {
            note.textContent = 'That is ' + show(held.currency, -after) + ' more than it holds'
                + (held.wallet ? ', so this cannot be saved from here.' : '.');
            note.className = 'text-xs text-red-600 mt-1 font-medium';
        } else {
            note.textContent = amount > 0
                ? 'And ' + show(held.currency, after) + ' left in it after this is paid.'
                : 'This is the money that will be reduced.';
            note.className = 'text-xs text-gray-500 mt-1';
        }
    }

    accountSelect.addEventListener('change', refresh);
    amountInput.addEventListener('input', refresh);
    refresh();
})();
</script>
@endpush
@endsection
