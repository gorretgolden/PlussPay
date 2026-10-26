@extends('layouts.app')

@section('title', 'Expense Categories')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="mb-6">
        <p class="text-sm text-gray-500"><a href="{{ route('expenses.index') }}" class="underline hover:text-gray-700">Expenses</a> / Expense categories</p>
        <h1 class="text-2xl font-bold text-gray-900">Expense Categories</h1>
        <p class="text-gray-600">The list the expense form offers. Retire a category rather than clearing it away, so the payments already filed under it stay findable.</p>
    </div>

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Add a category</h2>
        <form method="POST" action="{{ route('expense-categories.store') }}" class="flex flex-col sm:flex-row gap-3">
            @csrf
            <div class="flex-1">
                <input type="text" name="name" value="{{ old('name') }}" maxlength="60" required
                    placeholder="e.g. Courier"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-green-500">
                @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="bg-green-800 text-white px-5 py-2 rounded-md text-sm hover:bg-green-700 self-start">Add</button>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Payments filed</th>
                    <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Offered on the form</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider"></th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach($categories as $category)
                <tr>
                    <td class="px-6 py-3">
                        <input type="text" name="name" form="head-{{ $category->id }}" value="{{ $category->name }}" maxlength="60" aria-label="Name"
                            class="w-full max-w-xs px-2 py-1 border border-transparent hover:border-gray-300 rounded-md text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-gray-300">
                    </td>
                    <td class="px-6 py-3 text-right text-sm text-gray-900 align-middle">{{ number_format($category->expenses_count) }}</td>
                    <td class="px-6 py-3 text-center align-middle">
                        <input type="checkbox" name="is_active" value="1" form="head-{{ $category->id }}" @checked($category->is_active)
                            class="rounded border-gray-300 text-green-700 focus:ring-green-500">
                    </td>
                    <td class="px-6 py-3 text-right align-middle">
                        <form id="head-{{ $category->id }}" method="POST" action="{{ route('expense-categories.update', $category) }}">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="text-green-800 text-sm underline hover:text-green-700">Save</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($unfiled->isNotEmpty())
    <p class="text-xs text-gray-500 mt-3">Spending already recorded under a name that is not on this list: {{ $unfiled->implode(', ') }}. Add the category if you want it offered again.</p>
    @endif
</div>
@endsection
