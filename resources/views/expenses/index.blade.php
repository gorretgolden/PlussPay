@extends('layouts.app')

@section('title', 'Expenses')

@section('content')
@php
    $filtered = request()->filled('search') || request()->filled('category') || request()->filled('paid_via')
        || request()->filled('account_id') || request()->filled('status') || request()->filled('desk')
        || request()->filled('created_by')
        || request()->filled('date_from') || request()->filled('date_to');
@endphp
<div class="mb-6 flex flex-wrap justify-between items-start gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Expenses</h1>
        <p class="text-gray-600 text-sm">{{ $seesAll
            ? 'What the business spent on itself. Every entry takes the money out of the account that paid for it.'
            : 'What you spent out of the cash you are holding. Every entry takes the amount off your balance.' }}</p>
    </div>
    <div class="flex gap-2">
        @can('create-expense')
        <a href="{{ route('expenses.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Record Expense</a>
        @endcan
        @can('manage-expense-categories')
        <a href="{{ route('expense-categories.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Spending Heads</a>
        @endcan
        @can('export-expenses')
        <a href="{{ route('export.expenses', request()->query()) }}" class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
        @endcan
    </div>
</div>

<div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Entries {{ $filtered ? 'on this filter' : ($seesAll ? 'on all expenses' : 'you recorded') }}</p>
        <p class="text-xl font-semibold text-gray-900">{{ number_format($liveCount) }}@if($voidCount > 0) <span class="text-sm font-normal text-gray-500">(+{{ number_format($voidCount) }} cancelled)</span>@endif</p>
    </div>
    @foreach($totals as $currency => $total)
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Spent in {{ \App\Models\Account::currencyLabel($currency) }}</p>
        <p class="text-xl font-semibold text-gray-900">{{ \App\Models\Account::format($currency, $total) }}</p>
    </div>
    @endforeach
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[12rem]">
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Details, reference or note"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Spent on</label>
            <select name="category" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Any category</option>
                @foreach($categories as $category)
                    <option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </div>
        @if($seesAll)
        <div>
            <label class="block text-xs text-gray-500 mb-1">Desk</label>
            <select name="desk" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Any desk</option>
                @foreach($desks as $code => $label)
                    <option value="{{ $code }}" @selected(request('desk') === $code)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div>
            <label class="block text-xs text-gray-500 mb-1">Paid from</label>
            {{-- The list is the one the entry can actually be paid out of, so a filter never offers
                 a row a person could not have recorded. --}}
            <select name="account_id" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Any account</option>
                @foreach($payableAccounts as $account)
                    <option value="{{ $account->id }}" @selected((int) request('account_id') === $account->id)>{{ $account->name }}{{ str_contains($account->name, $account->currency) ? '' : ' ('.$account->currency.')' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Paid how</label>
            <select name="paid_via" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Any method</option>
                @foreach(\App\Models\Expense::PAID_VIA as $method)
                    <option value="{{ $method }}" @selected(request('paid_via') === $method)>{{ $method }}</option>
                @endforeach
            </select>
        </div>
        @if($seesAll)
        <div>
            <label class="block text-xs text-gray-500 mb-1">Entered by</label>
            <select name="created_by" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Anyone</option>
                @foreach($recordists as $person)
                    <option value="{{ $person->id }}" @selected((int) request('created_by') === $person->id)>{{ $person->name }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div>
            <label class="block text-xs text-gray-500 mb-1">Status</label>
            <select name="status" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Active and cancelled</option>
                <option value="active" @selected(request('status') === 'active')>Active only</option>
                <option value="void" @selected(request('status') === 'void')>Cancelled only</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
        <a href="{{ route('expenses.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear</a>
    </form>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Reference</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Spent on</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Details</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Amount</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Paid out of</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Paid how</th>
                @if($seesAll)
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entered By</th>
                @endif
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($expenses as $expense)
            <tr class="{{ $expense->isVoid() ? 'bg-gray-50 text-gray-400' : '' }}">
                <td class="px-4 py-3 text-sm whitespace-nowrap">{{ $expense->expense_date->format('M d, Y') }}</td>
                <td class="px-4 py-3 text-sm whitespace-nowrap">
                    <a href="{{ route('expenses.show', $expense) }}" class="text-green-800 hover:underline">{{ $expense->reference }}</a>
                    @if($expense->isVoid())
                        <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-red-50 text-red-700">Cancelled</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-gray-900">{{ $expense->category_name }}</td>
                <td class="px-4 py-3 text-sm text-gray-700">
                    {{ $expense->description }}
                    @if($expense->notes)
                        <span class="block text-xs text-gray-400">{{ $expense->notes }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ \App\Models\Account::format($expense->currency, $expense->amount) }}</td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ $expense->account?->name ?? 'Deleted account' }}</td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ $expense->paid_via ?? '-' }}</td>
                @if($seesAll)
                <td class="px-4 py-3 text-sm text-gray-500">{{ $expense->creator?->name ?? 'System' }}</td>
                @endif
                <td class="px-4 py-3 text-sm text-right relative" data-row-menu-host>
                    <button type="button" onclick="toggleMenu('exp{{ $expense->id }}')"
                        class="text-gray-500 hover:text-gray-800 border border-gray-300 rounded px-2 py-1 text-xs">More</button>
                    <div id="menu-exp{{ $expense->id }}" data-row-menu class="hidden absolute right-0 mt-1 w-44 bg-white border border-gray-200 rounded-md shadow-lg text-left z-20">
                        <a href="{{ route('expenses.show', $expense) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Open entry</a>
                        @if(! $expense->isVoid() && auth()->user()->can('create-expense') && ($seesAll || (int) $expense->created_by === (int) auth()->id()))
                        <a href="{{ route('expenses.edit', $expense) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Edit the words</a>
                        @endif
                        @can('view-accounts')
                        <a href="{{ route('accounts.movements', ['search' => $expense->reference]) }}" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Ledger lines</a>
                        @endcan
                        @can('void-expense')
                        @if(! $expense->isVoid())
                        <button type="button" onclick="openVoidModal(this)"
                            data-action="{{ route('expenses.void', $expense) }}"
                            data-deal="{{ $expense->reference }} &middot; {{ $expense->category_name }}"
                            data-first="{{ \App\Models\Account::format($expense->currency, $expense->amount) }}"
                            class="w-full text-left px-4 py-2 text-sm text-red-700 hover:bg-red-50">Cancel entry</button>
                        @endif
                        @endcan
                    </div>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="{{ $seesAll ? 9 : 8 }}" class="px-4 py-8 text-center text-gray-500">
                    {{ $seesAll ? 'No expenses have been recorded yet.' : 'You have not recorded an expense yet.' }}
                    @can('create-expense')
                    <a href="{{ route('expenses.create') }}" class="text-green-700 underline">Record the first one</a>.
                    @endcan
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $expenses->links() }}</div>

@include('components.void-modal', ['void' => [
    'subject' => 'expense',
    'first' => 'Paid out',
    'second' => null,
    'note' => 'The amount goes back into the account that paid it. The entry stays on the trail with this reason against it.',
]])
@endsection
