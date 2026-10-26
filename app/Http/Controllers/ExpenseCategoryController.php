<?php

namespace App\Http\Controllers;

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
            'categories' => ExpenseCategory::withCount('expenses')->orderBy('name')->get(),
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
