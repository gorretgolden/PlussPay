<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The expense categories she keeps on the go. A category is retired rather than deleted once it has been
 * used, because the payments filed under it have to stay findable.
 */
class ExpenseCategoryController extends Controller
{
    public function index()
    {
        return view('expenses.categories', [
            'categories' => ExpenseCategory::with(['creator', 'editor'])->withCount('expenses')->orderBy('name')->get(),
            'unfiled' => Expense::whereNull('category_id')->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateName($request);

        ExpenseCategory::create([
            'name' => $validated['name'],
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('expense-categories.index')
            ->with('success', $validated['name'].' is now an expense category. It can be picked the next time money goes out.');
    }

    public function update(Request $request, ExpenseCategory $category)
    {
        $validated = $this->validateName($request, $category);

        $before = $category->name;
        $category->update([
            'name' => $validated['name'],
            'is_active' => $request->has('is_active'),
        ]);

        $filed = $category->expenses()->count();

        $change = $before === $category->name
            ? ($category->is_active
                ? $before.' is back in use.'
                : $before.' is retired. It no longer appears on the expense form, and the '.($filed === 1 ? 'payment' : $filed.' payments').' already filed under it stay where they are.')
            : $before.' is now '.$category->name.'. The '.($filed === 1 ? 'payment' : $filed.' payments').' already filed under it move with the name.';

        return redirect()->route('expense-categories.index')->with('success', $change);
    }

    public function destroy(ExpenseCategory $category)
    {
        $filed = $category->expenses()->count();

        if ($filed > 0) {
            return back()->withErrors(['error' => $category->name.' has '.$filed.($filed === 1 ? ' payment' : ' payments').' filed under it, so it cannot be deleted. Retire it instead and those payments stay findable.']);
        }

        $name = $category->name;
        $category->delete();

        ActivityLog::create([
            ...ActivityLog::subjectAttributes($category),
            'action' => 'expense_category_deleted',
            'description' => 'Expense category "'.$name.'" was deleted',
        ]);

        return redirect()->route('expense-categories.index')->with('success', $name.' was deleted. No payment was ever filed under it.');
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['required', 'integer', 'exists:expense_categories,id'],
        ], [
            'category_ids.required' => 'Pick at least one category to delete.',
        ]);

        $categories = ExpenseCategory::withCount('expenses')->whereIn('id', $validated['category_ids'])->get();

        $inUse = $categories->filter(fn ($c) => $c->expenses_count > 0);

        if ($inUse->isNotEmpty()) {
            $names = $inUse->pluck('name')->sort()->implode(', ');

            return back()->withErrors(['error' => ($inUse->count() === 1 ? $names.' has payments filed under it, so it stays.' : $names.' have payments filed under them, so they stay.').' Nothing in the batch was deleted: a category in use is retired, never cleared away.']);
        }

        $names = $categories->pluck('name')->sort()->implode(', ');
        $count = $categories->count();

        ExpenseCategory::whereIn('id', $categories->pluck('id'))->delete();

        ActivityLog::create([
            'action' => 'expense_categories_bulk_deleted',
            'description' => $count.($count === 1 ? ' expense category deleted' : ' expense categories deleted').': '.$names,
        ]);

        return redirect()->route('expense-categories.index')->with('success', ($count === 1 ? $names.' was deleted.' : $count.' categories were deleted.').' None of them had a payment filed under it.');
    }

    private function validateName(Request $request, ?ExpenseCategory $category = null): array
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);

        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('expense_categories', 'name')->ignore($category?->id),
            ],
        ], [
            'name.required' => 'Give the category a name.',
            'name.unique' => 'That category is already on the list.',
        ]);
    }
}
