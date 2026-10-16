@extends('layouts.app')

@section('title', 'Expense '.$expense->reference)

@section('content')
@php
    $paid = \App\Models\Account::format($expense->currency, $expense->amount);
@endphp
<div class="mb-6 flex flex-wrap justify-between items-start gap-3">
    <div>
        <p class="text-sm text-gray-500"><a href="{{ route('expenses.index') }}" class="underline hover:text-gray-700">Expenses</a> / {{ $expense->reference }}</p>
        <h1 class="text-2xl font-bold text-gray-900">
            {{ $expense->reference }}
            @if($expense->isVoid())
                <span class="ml-2 align-middle px-2 py-1 text-xs rounded-full bg-red-50 text-red-700">Cancelled</span>
            @endif
        </h1>
        <p class="text-gray-600 text-sm">
            {{ $expense->expense_date->format('M d, Y') }} &middot; {{ $expense->category_name }} &middot;
            {{ $expense->description }}
        </p>
    </div>
    @if(!$expense->isVoid() && auth()->user()->can('void-expense'))
    <button type="button" onclick="openVoidModal(this)"
        data-action="{{ route('expenses.void', $expense) }}"
        data-deal="{{ $expense->reference }} &middot; {{ $expense->category_name }}"
        data-first="{{ $paid }}"
        class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Cancel entry</button>
    @endif
</div>

<div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Paid out</p>
        <p class="text-xl font-semibold text-gray-900">{{ $paid }}</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Paid from</p>
        <p class="text-sm font-medium text-gray-900 mt-1">
            @if($expense->account)
                {{-- The account page is a different door (view-accounts), so the name is only a link
                     for someone who can walk through it. --}}
                @can('view-accounts')
                    <a href="{{ route('accounts.show', $expense->account) }}" class="text-green-800 hover:underline">{{ $expense->account->name }}</a>
                @else
                    {{ $expense->account->name }}
                @endcan
            @else
                Deleted account
            @endif
        </p>
        @if($expense->account)
            <p class="text-xs text-gray-500 mt-1">Holds {{ \App\Models\Account::format($expense->currency, $expense->account->balance()) }} now.</p>
        @endif
    </div>
    <div class="bg-white rounded-lg shadow p-4">
        <p class="text-xs uppercase tracking-wide text-gray-500">Paid how</p>
        <p class="text-sm font-medium text-gray-900 mt-1">{{ $expense->paid_via ?? 'Not recorded' }}</p>
    </div>
</div>

<div class="bg-white rounded-lg shadow overflow-x-auto mb-6">
    <div class="px-6 py-4 border-b">
        <h2 class="text-sm font-semibold text-gray-900">Which accounts moved</h2>
        <p class="text-xs text-gray-500 mt-1">The same payment written in the cash books. A cancelled entry adds the refund as its own line rather than rubbing out the first one.</p>
    </div>
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Movement</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Goes up</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Goes down</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">As written in the book</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($legs as $leg)
            <tr>
                <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">{{ $leg->entry_date->format('M d, Y') }}</td>
                <td class="px-4 py-3 text-sm text-gray-900">
                    @if($leg->account)
                        @can('view-accounts')
                            <a href="{{ route('accounts.show', $leg->account) }}" class="text-green-800 hover:underline">{{ $leg->account->name }}</a>
                        @else
                            {{ $leg->account->name }}
                        @endcan
                    @else
                        Deleted account
                    @endif
                </td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ \App\Models\AccountEntry::labelFor($leg->type) }}</td>
                <td class="px-4 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ $leg->direction === 'in' ? \App\Models\Account::format($leg->account?->currency, $leg->amount) : '' }}</td>
                <td class="px-4 py-3 text-sm text-right text-gray-900 whitespace-nowrap">{{ $leg->direction === 'out' ? \App\Models\Account::format($leg->account?->currency, $leg->amount) : '' }}</td>
                <td class="px-4 py-3 text-sm text-gray-700">{{ $leg->description }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-4 py-6 text-center text-gray-500 text-sm">
                    This entry has no cash-book line against it. It was recorded before the accounts were set up.
                </td>
            </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="bg-white rounded-lg shadow p-6 max-w-3xl">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
        @if($seesAll)
        <div><span class="text-gray-500">Entered by:</span> {{ $expense->creator?->name ?? 'System' }}</div>
        @endif
        <div><span class="text-gray-500">Saved on:</span> {{ $expense->created_at->format('M d, Y h:i A') }}</div>
        @if($expense->notes)
        <div class="sm:col-span-2"><span class="text-gray-500">Note:</span> {{ $expense->notes }}</div>
        @endif
        @if($expense->receipt_file)
        <div class="sm:col-span-2">
            <a href="{{ route('expenses.receipt', $expense) }}" class="text-green-800 hover:underline">Download the slip</a>
            <span class="text-xs text-gray-500">kept with this entry, not on the public part of the site</span>
        </div>
        @endif
    </div>
</div>

@include('components.void-modal', ['void' => [
    'subject' => 'expense',
    'first' => 'Paid out',
    'second' => null,
    'note' => 'The amount goes back into the account that paid it. The entry stays on the trail with this reason against it.',
]])
@endsection
