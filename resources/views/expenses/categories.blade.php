@extends('layouts.app')

@section('title', 'Expense Categories')

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="mb-6">
        <p class="text-sm text-gray-500"><a href="{{ route('expenses.index') }}" class="underline hover:text-gray-700">Expenses</a> / Expense categories</p>
        <h1 class="text-2xl font-bold text-gray-900">Expense Categories</h1>
        <p class="text-gray-600">The list the expense form offers. Retire a category rather than clearing it away, so the payments already filed under it stay findable.</p>
    </div>

    @can('manage-expense-categories')
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Add a category</h2>
        <form method="POST" action="{{ route('expense-categories.store') }}" data-validate data-lock class="flex flex-col sm:flex-row gap-3">
            @csrf
            <div class="flex-1" data-field>
                <label for="new-category-name" class="mb-1 block text-sm text-gray-700">Category name</label>
                <input type="text" id="new-category-name" name="name" value="{{ old('name') }}" maxlength="60" required
                    placeholder="e.g. Courier"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                @error('name')<p data-field-error="name" class="text-sm text-red-700 mt-1">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="bg-green-800 text-white px-5 py-2 rounded-md text-sm hover:bg-green-700 self-start">Add</button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <form id="bulk-delete-form" method="POST" action="{{ route('expense-categories.bulk-delete') }}"
            data-confirm-title="Delete the selected categories"
            data-confirm="Categories with no payments filed under them will be gone for good."
            data-confirm-ok="Delete" data-confirm-danger="1">
            @csrf
        </form>
        <div class="px-6 py-3 border-b border-gray-200 flex items-center gap-3">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" id="select-all-categories" class="rounded border-gray-300 text-green-700 focus:ring-green-500">
                Select all
            </label>
            <button type="submit" id="bulk-delete-button" form="bulk-delete-form" disabled
                class="ml-auto rounded-md bg-red-700 px-4 py-2 text-sm text-white hover:bg-red-800 disabled:opacity-50">Delete selected</button>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><span class="sr-only">Pick</span></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Payments filed</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Offered on the form</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created by</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Edited by</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($categories as $category)
                <tr>
                    <td class="px-4 py-3 align-middle">
                        <input type="checkbox" name="category_ids[]" value="{{ $category->id }}" form="bulk-delete-form"
                            aria-label="Pick {{ $category->name }}"
                            class="rounded border-gray-300 text-green-700 focus:ring-green-500">
                    </td>
                    <td class="px-4 py-3">
                        <form id="save-{{ $category->id }}" method="POST" action="{{ route('expense-categories.update', $category) }}" data-validate data-lock>
                            @csrf
                            @method('PUT')
                            <label for="name-{{ $category->id }}" class="sr-only">Category name</label>
                            <input type="text" id="name-{{ $category->id }}" name="name" value="{{ $category->name }}" maxlength="60" required
                                class="w-full max-w-xs px-2 py-1 border border-transparent hover:border-gray-300 rounded-md text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-gray-300">
                        </form>
                    </td>
                    <td class="px-4 py-3 text-right text-sm text-gray-900 align-middle">{{ number_format($category->expenses_count) }}</td>
                    <td class="px-4 py-3 text-center align-middle">
                        <input type="checkbox" name="is_active" value="1" form="save-{{ $category->id }}" @checked($category->is_active)
                            class="rounded border-gray-300 text-green-700 focus:ring-green-500">
                    </td>
                    <td class="px-4 py-3 align-middle">
                        <div><div class="text-gray-900 text-sm">{{ $category->creator?->name ?? '-' }}</div><time datetime="{{ $category->created_at->toIso8601String() }}" class="text-xs text-gray-600 whitespace-nowrap">{{ $category->created_at->format('M d, Y h:i A') }}</time></div>
                    </td>
                    <td class="px-4 py-3 align-middle">
                        <div><div class="text-gray-900 text-sm">{{ $category->editor?->name ?? '-' }}</div><time datetime="{{ $category->updated_at->toIso8601String() }}" class="text-xs text-gray-600 whitespace-nowrap">{{ $category->updated_at->format('M d, Y h:i A') }}</time></div>
                    </td>
                    <td class="px-4 py-3 text-right align-middle">
                        <div class="flex items-center justify-end gap-3">
                            <button type="submit" form="save-{{ $category->id }}" class="text-green-800 text-sm underline hover:text-green-700">Save</button>
                            @if($category->expenses_count === 0)
                            <form method="POST" action="{{ route('expense-categories.destroy', $category) }}"
                                data-confirm-title="Delete {{ $category->name }}?"
                                data-confirm="The category will be gone for good."
                                data-confirm-ok="Delete" data-confirm-danger="1">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="text-red-700 text-sm underline hover:text-red-800">Delete</button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-6 py-8 text-center text-gray-600">No categories yet. Add the first one above.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <script>
    (function () {
        var form = document.getElementById('bulk-delete-form');
        if (!form) return;
        var checkboxes = Array.from(form.elements).filter(function (el) { return el.name === 'category_ids[]'; });
        var selectAll = document.getElementById('select-all-categories');
        var button = document.getElementById('bulk-delete-button');
        var update = function () {
            var checked = checkboxes.filter(function (c) { return c.checked; });
            if (selectAll) {
                selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
                selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
            }
            if (button) button.disabled = checked.length === 0;
        };
        checkboxes.forEach(function (c) { c.addEventListener('change', update); });
        if (selectAll) selectAll.addEventListener('change', function () {
            checkboxes.forEach(function (c) { c.checked = selectAll.checked; });
            update();
        });
        update();
    })();
    </script>
    @else
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Payments filed</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Offered on the form</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created by</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($categories as $category)
                <tr>
                    <td class="px-6 py-3 text-sm text-gray-900">{{ $category->name }}</td>
                    <td class="px-6 py-3 text-right text-sm text-gray-900">{{ number_format($category->expenses_count) }}</td>
                    <td class="px-6 py-3 text-center align-middle">
                        @if($category->is_active)
                        <span class="inline-flex items-center rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-800">In use</span>
                        @else
                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">Retired</span>
                        @endif
                    </td>
                    <td class="px-6 py-3 align-middle">
                        <div><div class="text-gray-900 text-sm">{{ $category->creator?->name ?? '-' }}</div><time datetime="{{ $category->created_at->toIso8601String() }}" class="text-xs text-gray-600 whitespace-nowrap">{{ $category->created_at->format('M d, Y h:i A') }}</time></div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-6 py-8 text-center text-gray-600">No categories yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endcan

    @if($unfiled->isNotEmpty())
    <p class="text-xs text-gray-500 mt-3">Spending already recorded under a name that is not on this list: {{ $unfiled->implode(', ') }}. @can('manage-expense-categories')Add the category if you want it offered again.@endcan</p>
    @endif
</div>
@endsection
