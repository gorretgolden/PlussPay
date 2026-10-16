<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    /**
     * The book has two depths. view-expenses opens the screen, view-all-expenses is what makes
     * every entry in it readable, and a desk agent is given the screen without it, so their page
     * of the book holds the rows they entered and nothing else.
     */
    private function seesEveryEntry(): bool
    {
        return auth()->user()->can('view-all-expenses');
    }

    /**
     * Which accounts may be paid out of. The office spends its own cash; an agent can only spend
     * the money already in their hand, which is what their wallets hold. Keeping that list short
     * is also what keeps an agent's transport fare from quietly moving the office's balance.
     *
     * @return array<int, Account>
     */
    private function payableAccounts(): array
    {
        if ($this->seesEveryEntry()) {
            return Account::ordered();
        }

        return array_values(Account::walletsFor(auth()->user()));
    }

    private function canRead(Expense $expense): bool
    {
        return $this->seesEveryEntry() || (int) $expense->created_by === (int) auth()->id();
    }

    public function index(Request $request)
    {
        $own = $this->seesEveryEntry() ? null : auth()->id();

        // Applied after the filters, so a hand-typed created_by in the query string can only ever
        // narrow an agent's own rows further.
        $rows = Expense::with(['account', 'creator', 'categoryGroup'])
            ->filtered($request)
            ->when($own, fn (Builder $q) => $q->where('created_by', $own))
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
            'seesAll' => $this->seesEveryEntry(),
            'payableAccounts' => $this->payableAccounts(),
            'categories' => ExpenseCategory::orderBy('name')->pluck('name')->all(),
            'desks' => User::COUNTRIES,
            'recordists' => $own
                ? collect()
                : User::whereIn('id', Expense::whereNotNull('created_by')->distinct()->pluck('created_by'))
                    ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Expense $expense)
    {
        abort_unless($this->canRead($expense), 403);

        $expense->load(['account', 'creator', 'categoryGroup']);

        return view('expenses.show', [
            'expense' => $expense,
            'legs' => $expense->legs(),
            'seesAll' => $this->seesEveryEntry(),
        ]);
    }

    /**
     * The slip is kept off the public disk on purpose: it is handed out through this route, which
     * only opens for someone allowed to see the expense it belongs to.
     */
    public function receipt(Expense $expense)
    {
        abort_unless($this->canRead($expense), 403);

        abort_if(! $expense->receipt_file, 404);

        $path = Storage::disk('local')->path($expense->receipt_file);

        abort_unless(is_file($path), 404);

        return response()->download($path, $expense->reference.'-receipt'.strrchr($expense->receipt_file, '.'));
    }

    public function create()
    {
        $accounts = $this->payableAccounts();
        $local = auth()->user()->country === 'CN' ? 'RMB' : 'UGX';
        $default = collect($accounts)->first(fn (Account $a) => $a->currency === $local)
            ?? collect($accounts)->first();

        return view('expenses.create', [
            'accounts' => $accounts,
            'defaultAccountId' => $default?->id,
            'seesAll' => $this->seesEveryEntry(),
            'categories' => $options = ExpenseCategory::options(),
            // The office opens on rent; a desk opens on the fare they just paid.
            'defaultCategoryId' => array_search($this->seesEveryEntry() ? 'Rent' : 'Transport', $options, true)
                ?: array_key_first($options),
            'paidVia' => Expense::PAID_VIA,
        ]);
    }

    public function store(Request $request)
    {
        $payable = array_map(fn (Account $account) => (int) $account->id, $this->payableAccounts());

        $validated = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['required', 'integer', Rule::in($payable)],
            'expense_date' => ['required', 'date'],
            'paid_via' => ['nullable', Rule::in(Expense::PAID_VIA)],
            'notes' => ['nullable', 'string', 'max:500'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [
            'category_id.required' => 'Choose what kind of spending this was.',
            'description.required' => 'Say what the money was spent on.',
            'amount.min' => 'Enter how much was paid out.',
            'account_id.required' => 'Choose which account paid for it.',
            'account_id.in' => $this->seesEveryEntry()
                ? 'Choose which account paid for it.'
                : 'Choose which of your wallets paid for it. An expense of yours comes out of the cash you are holding.',
            'receipt.mimes' => 'Attach the slip as a PDF or a photo.',
            'receipt.max' => 'The slip file is too large. A photo of it usually fits.',
        ]);

        $account = Account::findOrFail($validated['account_id']);
        $amount = number_format((float) $validated['amount'], 2, '.', '');
        $head = ExpenseCategory::findOrFail($validated['category_id']);

        try {
            $expense = Expense::record([
                'expense_date' => $validated['expense_date'],
                'category_id' => $head->id,
                'category' => $head->name,
                'description' => trim($validated['description']),
                'currency' => $account->currency,
                'amount' => $amount,
                'account_id' => $account->id,
                'paid_via' => $validated['paid_via'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'receipt_file' => $request->hasFile('receipt') ? $request->file('receipt')->store('receipts', 'local') : null,
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
