<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Setting;
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
        return $this->form(null);
    }

    public function edit(Request $request, Expense $expense)
    {
        $this->authorizeEdit($expense);

        return $this->form($expense);
    }

    /**
     * One page for both jobs. Recording an expense and correcting the words of one already paid
     * ask for the same fields in the same order, and a second copy of the form is a second set of
     * words to get out of step with the first.
     */
    private function form(?Expense $expense)
    {
        $accounts = $this->payableAccounts();

        // An entry paid out of an account this desk no longer chooses from stays editable, and the
        // account it names has to be on the list or the page would read as a blank.
        if ($expense && $expense->account && ! collect($accounts)->contains('id', $expense->account_id)) {
            $accounts[] = $expense->account;
        }

        $local = auth()->user()->country === 'CN' ? 'RMB' : 'UGX';
        $default = collect($accounts)->first(fn (Account $a) => $a->id === $expense?->account_id)
            ?? collect($accounts)->first(fn (Account $a) => $a->currency === $local)
            ?? collect($accounts)->first();

        return view('expenses.form', [
            'expense' => $expense,
            'accounts' => $accounts,
            'defaultAccountId' => $default?->id,
            'seesAll' => $this->seesEveryEntry(),
            'categories' => $options = ExpenseCategory::options(),
            // The office opens on rent; a desk opens on the fare they just paid.
            'defaultCategoryId' => array_search($this->seesEveryEntry() ? 'Rent' : 'Transport', $options, true)
                ?: array_key_first($options),
            'paidVia' => Expense::PAID_VIA,
            'minimumAmount' => (float) Setting::get('min_transaction_amount', 100),
        ]);
    }

    /**
     * The person who may write an expense may fix its words; the office may fix anybody's. A
     * cancelled entry is closed: its money is already back in the account, so editing it would
     * describe a payment that no longer stands.
     */
    private function authorizeEdit(Expense $expense): void
    {
        abort_if($expense->isVoid(), 403, 'A cancelled entry is closed. Record the payment again instead.');
        abort_unless($this->seesEveryEntry() || (int) $expense->created_by === (int) auth()->id(), 403,
            'You can only edit an expense you recorded.');
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
            'amount.required' => 'Enter how much was paid out.',
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

        if ($refusal = $this->refusal($account, $amount)) {
            return back()->withErrors(['amount' => $refusal])->withInput();
        }

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

    /**
     * Two figures stop an entry before the books are touched. Nothing is spent for free, and nothing
     * is entered so small it is a typing mistake rather than a payment. And the money has to be in
     * the account named: a wallet that holds less than the figure cannot have paid it.
     *
     * The company's China accounts are deliberately left out of the second rule. That side pays the
     * receiver out of its own float before Uganda settles across, so a negative there is the honest
     * picture of who is owed what rather than a missing payment.
     */
    private function refusal(Account $account, string $amount): ?string
    {
        $minimum = (float) Setting::get('min_transaction_amount', 100);

        if (bccomp($amount, (string) $minimum, 2) < 0) {
            return 'The smallest amount this desk records is '.Account::format($account->currency, $minimum)
                .'. Enter what was actually paid out.';
        }

        if ($account->isWallet() && bccomp($amount, (string) $account->balance(), 2) > 0) {
            return $account->name.' holds '.Account::format($account->currency, $account->balance())
                .'. An expense cannot be more than the money in the account that paid it.';
        }

        return null;
    }

    /**
     * The words of an entry are corrected in place; the money it moved is not. The ledger line that
     * carries the payment is rewritten to match, because a line describing a fare when the note now
     * says airtime is two records of the same cash disagreeing with each other.
     */
    public function update(Request $request, Expense $expense)
    {
        $this->authorizeEdit($expense);

        $validated = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')->where('is_active', true)],
            'description' => ['required', 'string', 'max:255'],
            'expense_date' => ['required', 'date'],
            'paid_via' => ['nullable', Rule::in(Expense::PAID_VIA)],
            'notes' => ['nullable', 'string', 'max:500'],
            'receipt' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ], [
            'category_id.required' => 'Choose what kind of spending this was.',
            'description.required' => 'Say what the money was spent on.',
        ]);

        $head = ExpenseCategory::findOrFail($validated['category_id']);

        $expense->fill([
            'category_id' => $head->id,
            'category' => $head->name,
            'description' => trim($validated['description']),
            'expense_date' => $validated['expense_date'],
            'paid_via' => $validated['paid_via'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        if ($request->hasFile('receipt')) {
            if ($expense->receipt_file) {
                Storage::disk('local')->delete($expense->receipt_file);
            }

            $expense->receipt_file = $request->file('receipt')->store('receipts', 'local');
        }

        $expense->save();

        AccountEntry::where('reference', $expense->reference)
            ->where('direction', 'out')
            ->get()
            ->each(fn (AccountEntry $line) => $line->update([
                'entry_date' => $expense->expense_date->toDateString(),
                'description' => $expense->category.': '.$expense->description.' ('.$expense->reference.', paid via '
                    .strtolower((string) ($expense->paid_via ?: 'cash')).')',
            ]));

        return redirect()->route('expenses.show', $expense)
            ->with('success', $expense->reference.' updated. The '.Account::format($expense->currency, $expense->amount)
                .' it moved is unchanged.');
    }

    public function void(Request $request, Expense $expense)
    {
        // Cancelling puts money back into an account, so the same rule as reading the entry holds:
        // a desk may only close its own spending unless it may read the whole spending book.
        abort_unless($this->canRead($expense), 403, 'You can only cancel an expense you recorded.');

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
