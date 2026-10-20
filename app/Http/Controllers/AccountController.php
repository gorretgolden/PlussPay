<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Services\AccountLedger;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class AccountController extends Controller
{
    public function index()
    {
        $accounts = Account::ordered();
        $today = now()->toDateString();

        return view('accounts.index', [
            'accounts' => $accounts,
            'balances' => collect($accounts)->mapWithKeys(fn (Account $a) => [$a->id => $a->balance()]),
            'todayMovements' => collect($accounts)->mapWithKeys(fn (Account $a) => [
                $a->id => AccountEntry::where('account_id', $a->id)->whereDate('entry_date', $today)->count(),
            ]),
            'countries' => $this->countryTotals($accounts, $today),
            'date' => $today,
        ]);
    }

    public function show(Request $request, Account $account)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // The running figure is what she reads off her own sheet, so every line is totalled
        // from the start of the account rather than from the page she happens to be on.
        $all = $account->entries()->filtered($request)->get();

        $opening = $dateFrom ? $account->balanceBefore($dateFrom) : (string) $account->opening_balance;

        $running = $opening;
        $entries = $all->map(function (AccountEntry $entry) use (&$running) {
            $running = $entry->direction === 'in'
                ? bcadd($running, (string) $entry->amount, 2)
                : bcsub($running, (string) $entry->amount, 2);

            return ['entry' => $entry, 'balance' => $running];
        });

        $page = max(1, (int) $request->input('page', 1));
        $perPage = 50;

        return view('accounts.show', [
            'account' => $account,
            'entries' => new LengthAwarePaginator(
                $entries->slice(($page - 1) * $perPage, $perPage)->values(),
                $entries->count(),
                $perPage,
                $page,
                ['path' => route('accounts.show', $account), 'query' => $request->query()]
            ),
            'opening' => $opening,
            'closing' => $running,
            'totals' => [
                'in' => (string) $all->where('direction', 'in')->sum('amount'),
                'out' => (string) $all->where('direction', 'out')->sum('amount'),
            ],
        ]);
    }

    public function createEntry(Request $request)
    {
        return view('accounts.create-entry', [
            'accounts' => Account::ordered(),
            'selected' => $request->input('account_id'),
            'prefill' => $request->only(['kind', 'amount', 'second_amount', 'reference', 'description']),
        ]);
    }

    /**
     * Every line in the cash books on one page, whichever account it belongs to, with the account
     * that went up named next to the one that came down. Reading one account at a time left the
     * other half of a deal invisible, which is what this page answers.
     */
    public function movements(Request $request)
    {
        $query = AccountEntry::with(['account', 'counterparty', 'creator', 'transaction'])
            ->filtered($request);

        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->input('account_id'));
        }

        return view('accounts.movements', [
            'entries' => $query->latest('entry_date')->latest('id')->paginate(50)->withQueryString(),
            'accounts' => Account::ordered(),
        ]);
    }

    public function storeEntry(Request $request)
    {
        $kind = $request->input('kind');

        $validated = $request->validate([
            'kind' => 'required|in:opening_balance,money_in,money_out,transfer,exchange,adjustment_in,adjustment_out',
            'account_id' => 'required|integer|exists:accounts,id',
            'counterparty_id' => 'nullable|required_if:kind,transfer,exchange|integer|exists:accounts,id|different:account_id',
            'amount' => 'required|numeric|min:0.01',
            'second_amount' => 'nullable|required_if:kind,exchange|numeric|min:0.01',
            'entry_date' => 'nullable|date',
            'reference' => 'nullable|string|max:60',
            'description' => 'required|string|max:255',
        ], [
            'account_id.required' => 'Choose which account this movement belongs to.',
            'counterparty_id.required' => 'Choose the other account.',
            'counterparty_id.different' => 'The two accounts must be different.',
            'amount.min' => 'The amount must be greater than zero.',
            'second_amount.required' => 'Enter the amount received in the other currency.',
            'description.required' => 'Say what this money was for.',
        ]);

        $account = Account::findOrFail($validated['account_id']);
        $counterparty = isset($validated['counterparty_id']) ? Account::findOrFail($validated['counterparty_id']) : null;
        $date = $validated['entry_date'] ?? now()->toDateString();

        $before = $account->balance();

        try {
            match ($validated['kind']) {
                'transfer' => AccountLedger::transfer($account, $counterparty, (string) $validated['amount'], $validated['description'], $date, $validated['reference'] ?? null),
                'exchange' => AccountLedger::exchange($account, $counterparty, (string) $validated['amount'], (string) $validated['second_amount'], $validated['description'], $date, $validated['reference'] ?? null),
                default => AccountLedger::record($account, $validated['kind'], (string) $validated['amount'], $validated['description'], $date, $validated['reference'] ?? null),
            };
        } catch (InsufficientFundsException $e) {
            return back()->withErrors(['amount' => $e->getMessage()])->withInput();
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['kind' => $e->getMessage()])->withInput();
        }

        // A money-out line written in by hand against a receipt number is still the desk putting
        // cash into a receiver's hand, so it stamps the transfer as paid exactly as the China Desk
        // screen does. Which transfers a line can close depends on the money the account holds,
        // because that is the money the receiver was promised in. Without this the money would
        // leave the account while every board still counted it as owed.
        if ($validated['kind'] === 'money_out') {
            Transaction::where('receipt_number', trim($validated['reference'] ?? ''))
                ->payingIn($account->currency)
                ->whereNull('delivered_at')
                ->get()
                ->each(fn (Transaction $t) => $t->forceFill(['delivered_at' => now()])->save());
        }

        // A manual ledger movement changes the cash books, so it is auditable in its own
        // right: what the account held before, and what it holds now.
        ActivityLog::create([
            'user_id' => auth()->id(),
            ...ActivityLog::subjectAttributes($account),
            'action' => 'wallet_adjusted',
            'description' => $validated['description'],
            'old_value' => json_encode(['balance' => $before]),
            'new_value' => json_encode([
                'balance' => $account->balance(),
                'movement' => AccountEntry::labelFor($validated['kind']),
                'amount' => number_format((float) $validated['amount'], 2),
                'currency' => $account->currency,
            ]),
        ]);

        return redirect()->route('accounts.show', $account)->with('success', 'Ledger entry saved.');
    }

    public function dailyBalances(Request $request)
    {
        $from = $request->input('date_from', now()->subDays(13)->toDateString());
        $to = $request->input('date_to', now()->toDateString());
        $accounts = Account::ordered();

        $dates = Account::dateRange($from, $to);

        // One pass per account walking the range day by day, which is how the closing
        // figure on each of her sheets was written out by hand.
        $rows = [];
        foreach ($accounts as $account) {
            foreach ($account->dailyClosing($from, $dates) as $day => $values) {
                $rows[$day][$account->id] = $values;
            }
        }

        return view('accounts.daily-balances', [
            'accounts' => $accounts,
            'rows' => $rows,
            'dates' => $dates,
            'from' => $from,
            'to' => $to,
            'countries' => collect($rows)->map(fn ($day) => $this->countryTotals($accounts, null, $day)),
        ]);
    }

    /**
     * Uganda is read in shillings and China in RMB, so each country's subtotal is the cash it
     * holds plus whatever dollars it is holding, priced at the selling rate the business uses.
     *
     * @param  array<int, array<int|string, array{closing: string}>>  $dayRow
     */
    private function countryTotals(array $accounts, ?string $asOf, ?array $dayRow = null): array
    {
        $native = [];
        foreach ($accounts as $account) {
            $native[$account->code] = $dayRow !== null
                ? ($dayRow[$account->id]['closing'] ?? '0.00')
                : $account->balance($asOf);
        }

        $ugxRate = (string) (ExchangeRate::usdToUgx() ?? 0);
        $rmbRate = (string) (ExchangeRate::pair('USD', 'RMB')['sell'] ?? 0);

        return [
            'UG' => [
                'label' => 'Uganda',
                'currency' => 'UGX',
                'rate' => $ugxRate,
                'dollars' => $native[Account::USD_UGANDA] ?? '0.00',
                'total' => $ugxRate > 0
                    ? bcadd($native[Account::UGX_UGANDA] ?? '0.00', bcmul($native[Account::USD_UGANDA] ?? '0.00', $ugxRate, 2), 2)
                    : '0.00',
            ],
            'CN' => [
                'label' => 'China',
                'currency' => 'RMB',
                'rate' => $rmbRate,
                'dollars' => $native[Account::USD_CHINA] ?? '0.00',
                'total' => $rmbRate > 0
                    ? bcadd($native[Account::RMB_CHINA] ?? '0.00', bcmul($native[Account::USD_CHINA] ?? '0.00', $rmbRate, 2), 2)
                    : '0.00',
            ],
        ];
    }
}
