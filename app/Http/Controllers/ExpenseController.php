<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $rows = Expense::with(['account', 'creator'])
            ->filtered($request)
            ->get()
            ->sortByDesc('id')
            ->sortByDesc('expense_date')
            ->values();

        $live = $rows->where('status', Expense::ACTIVE);
        $page = max(1, (int) $request->input('page', 1));

        return view('expenses.index', [
            'expenses' => new LengthAwarePaginator(
                $rows->slice(($page - 1) * 20, 20)->values(),
                $rows->count(),
                20,
                $page,
                ['path' => route('expenses.index'), 'query' => $request->query()]
            ),
            'totals' => $live->groupBy('currency')
                ->map(fn ($group) => (string) $group->sum('amount'))
                ->all(),
            'liveCount' => $live->count(),
            'voidCount' => $rows->count() - $live->count(),
            'categories' => Expense::CATEGORIES,
        ]);
    }

    public function create()
    {
        $accounts = Account::ordered();
        $default = auth()->user()?->country === 'CN'
            ? Account::RMB_CHINA
            : Account::UGX_UGANDA;

        return view('expenses.create', [
            'accounts' => $accounts,
            'defaultAccountId' => collect($accounts)->firstWhere('code', $default)?->id,
            'categories' => Expense::CATEGORIES,
            'paidVia' => Expense::PAID_VIA,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'expense_date' => ['required', 'date'],
            'paid_via' => ['nullable', Rule::in(Expense::PAID_VIA)],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'category.required' => 'Choose what kind of spending this was.',
            'description.required' => 'Say what the money was spent on.',
            'amount.min' => 'Enter how much was paid out.',
            'account_id.required' => 'Choose which account paid for it.',
        ]);

        $account = Account::findOrFail($validated['account_id']);
        $amount = number_format((float) $validated['amount'], 2, '.', '');

        try {
            $expense = Expense::record([
                'expense_date' => $validated['expense_date'],
                'category' => $validated['category'],
                'description' => trim($validated['description']),
                'currency' => $account->currency,
                'amount' => $amount,
                'account_id' => $account->id,
                'paid_via' => $validated['paid_via'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
        } catch (InsufficientFundsException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        }

        return redirect()->route('expenses.index')
            ->with('success', $expense->reference.' saved: '.Account::format($account->currency, $amount)
                .' taken out of '.$account->name.'.');
    }

    public function void(Request $request, Expense $expense)
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Say why this expense is being cancelled. The reason goes on the ledger.',
        ]);

        if ($expense->isVoid()) {
            return back()->withErrors(['reason' => $expense->reference.' has already been cancelled.']);
        }

        $expense->void($validated['reason']);

        return redirect()->route('expenses.index')
            ->with('success', $expense->reference.' cancelled. '.Account::format($expense->currency, $expense->amount)
                .' is back in '.($expense->account?->name ?: 'the account').'.');
    }
}
