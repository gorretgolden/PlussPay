@extends('layouts.app')

@section('title', 'Expense Categories')

@section('content')
<style>
    /* The shared validation banner reserves a 45px slot for long forms; inside a table row that
       slot would double the row height, so a rename keeps the banner compact and out of the way
       until it has something to say. */
    .compact-validation [data-error-summary] { margin: 0; min-height: 0; padding: 0; border: 0; }
    .compact-validation [data-error-summary][style*="hidden"] { display: none; }
    .compact-validation [data-error-summary][style*="visible"] { margin-bottom: .5rem; padding: .5rem; border: 1px solid #fecaca; }
</style>
<div class="max-w-6xl mx-auto">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-sm text-gray-500"><a href="{{ route('expenses.index') }}" class="underline hover:text-gray-700">Expenses</a> / Expense Categories</p>
            <h1 class="text-2xl font-bold text-gray-900">Expense Categories</h1>
            <p class="mt-1 text-sm text-gray-600">The list the expense form offers. A category with payments filed under it is retired, never cleared away.</p>
        </div>
        <a href="{{ route('expenses.index') }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">All Expenses</a>
    </div>

    @can('manage-expense-categories')
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach([
            ['Categories', $insights['total']],
            ['In use', $insights['active']],
            ['Retired', $insights['retired']],
            ['Payments filed', $insights['filed']],
        ] as [$label, $value])
        <div class="rounded-lg bg-white p-4 shadow">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ number_format($value) }}</p>
        </div>
        @endforeach
    </div>

    <div class="mb-6 rounded-lg bg-white p-4 shadow">
        <form method="POST" action="{{ route('expense-categories.store') }}" data-validate data-lock class="compact-validation flex items-start gap-2">
            @csrf
            <div class="min-w-0 flex-1" data-field>
                <label for="new-category-name" class="sr-only">New category name</label>
                <input type="text" id="new-category-name" name="name" value="{{ old('name') }}" maxlength="60" required
                    placeholder="Add a category, e.g. Courier"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-green-600">
                @error('name')<p data-field-error="name" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="shrink-0 rounded-md bg-green-800 px-4 py-2 text-sm font-medium text-white hover:bg-green-700">Add</button>
        </form>
    </div>

    <div class="overflow-hidden rounded-lg bg-white shadow">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-600">Category</th>
                        <th scope="col" class="hidden px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-600 sm:table-cell">Payments filed</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-600">Offered</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-600 lg:table-cell">Added</th>
                        <th scope="col" class="hidden px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-600 lg:table-cell">Updated</th>
                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-600">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($categories as $category)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <form id="save-{{ $category->id }}" method="POST" action="{{ route('expense-categories.update', $category) }}" data-validate data-lock class="compact-validation">
                                @csrf
                                @method('PUT')
                                <label for="name-{{ $category->id }}" class="sr-only">Name for {{ $category->name }}</label>
                                <input type="text" id="name-{{ $category->id }}" name="name" value="{{ $category->name }}" maxlength="60" required
                                    class="w-full max-w-xs rounded-md border border-transparent px-2 py-1 text-sm text-gray-900 hover:border-gray-300 focus:border-gray-300 focus:outline-none focus:ring-2 focus:ring-green-600">
                            </form>
                        </td>
                        <td class="hidden px-4 py-3 text-right text-sm tabular-nums text-gray-900 sm:table-cell">{{ number_format($category->expenses_count) }}</td>
                        <td class="px-4 py-3">
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="is_active" value="1" form="save-{{ $category->id }}" @checked($category->is_active) aria-label="Offer {{ $category->name }} on the expense form" class="rounded border-gray-300 text-green-700 focus:ring-green-600">
                                <span class="hidden sm:inline">{{ $category->is_active ? 'In use' : 'Retired' }}</span>
                            </label>
                        </td>
                        <td class="hidden px-4 py-3 text-sm lg:table-cell">
                            <div class="text-gray-900">{{ $category->creator?->name ?? '-' }}</div>
                            <time datetime="{{ $category->created_at->toIso8601String() }}" title="{{ $category->created_at->format('M d, Y H:i:s') }}" class="text-xs text-gray-600 whitespace-nowrap">{{ $category->created_at->format('M d, Y h:i A') }}</time>
                        </td>
                        <td class="hidden px-4 py-3 text-sm lg:table-cell">
                            <div class="text-gray-900">{{ $category->editor?->name ?? '-' }}</div>
                            <time datetime="{{ $category->updated_at->toIso8601String() }}" title="{{ $category->updated_at->format('M d, Y H:i:s') }}" class="text-xs text-gray-600 whitespace-nowrap">{{ $category->updated_at->format('M d, Y h:i A') }}</time>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('expenses.index', ['category' => $category->name]) }}" title="View payments filed under {{ $category->name }}" aria-label="View payments filed under {{ $category->name }}" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-gray-700 hover:bg-green-50 hover:text-green-800 focus-visible:ring-2 focus-visible:ring-green-600">
                                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                </a>
                                <button type="submit" form="save-{{ $category->id }}" title="Save {{ $category->name }}" aria-label="Save {{ $category->name }}" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-green-700 hover:bg-green-50 hover:text-green-800 focus-visible:ring-2 focus-visible:ring-green-600">
                                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </button>
                                @if($category->expenses_count === 0)
                                <form method="POST" action="{{ route('expense-categories.destroy', $category) }}"
                                    data-confirm-title="Delete {{ $category->name }}?"
                                    data-confirm="No payment is filed under it, so the category will be gone for good."
                                    data-confirm-ok="Delete" data-confirm-danger="1">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" title="Delete {{ $category->name }}" aria-label="Delete {{ $category->name }}" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-700 hover:bg-red-50 hover:text-red-800 focus-visible:ring-2 focus-visible:ring-green-600">
                                        <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                                @else
                                <span title="{{ $category->name }} has {{ $category->expenses_count }} {{ Str::plural('payment', $category->expenses_count) }} filed under it. Retire it instead; it cannot be deleted." class="inline-flex h-8 w-8 cursor-not-allowed items-center justify-center rounded-md text-gray-300">
                                    <svg aria-hidden="true" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4"/></svg>
                                </span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-6 py-8 text-center text-gray-600">No categories yet. Add the first one above.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($categories->hasPages())
        <div class="border-t border-gray-200 px-4 py-4">{{ $categories->links() }}</div>
        @endif
    </div>

    @if($unfiled->isNotEmpty())
    <p class="mt-3 text-xs text-gray-500">Spending already recorded under a name that is not on this list: {{ $unfiled->implode(', ') }}. Add the category if you want it offered again.</p>
    @endif
    @else
    <div class="overflow-hidden rounded-lg bg-white shadow">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-600">Category</th>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-600">Offered</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($categories as $category)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-sm text-gray-900">{{ $category->name }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $category->is_active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700' }}">{{ $category->is_active ? 'In use' : 'Retired' }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="2" class="px-6 py-8 text-center text-gray-600">No categories yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($categories->hasPages())
        <div class="border-t border-gray-200 px-4 py-4">{{ $categories->links() }}</div>
        @endif
    </div>
    @endcan
</div>
@endsection
