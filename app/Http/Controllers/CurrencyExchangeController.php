<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientFundsException;
use App\Models\Account;
use App\Models\CurrencyExchange;
use App\Models\Customer;
use App\Services\ExchangeCalculator;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class CurrencyExchangeController extends Controller
{
    public function index(Request $request)
    {
        $rows = CurrencyExchange::with(['inAccount', 'outAccount', 'customer', 'creator'])
            ->visibleTo($request->user())
            ->filtered($request)
            ->get()
            ->sortByDesc('id')
            ->sortByDesc('exchange_date')
            ->values();

        $live = $rows->where('status', CurrencyExchange::ACTIVE);
        $page = max(1, (int) $request->input('page', 1));

        // The two sides of a deal sit in different currencies, so only the margin adds up across
        // rows. A deal the card cannot price is counted rather than folded into the total at zero.
        $marginTotal = 0.0;
        $unpriced = 0;
        foreach ($live as $exchange) {
            if ($exchange->margin_ugx === null) {
                $unpriced++;
            } else {
                $marginTotal += (float) $exchange->margin_ugx;
            }
        }

        return view('exchanges.index', [
            'exchanges' => new LengthAwarePaginator(
                $rows->slice(($page - 1) * 20, 20)->values(),
                $rows->count(),
                20,
                $page,
                ['path' => route('exchanges.index'), 'query' => $request->query()]
            ),
            'liveCount' => $live->count(),
            'voidCount' => $rows->count() - $live->count(),
            'marginTotal' => $marginTotal,
            'unpriced' => $unpriced,
            'card' => ExchangeCalculator::card(),
        ]);
    }

    public function create()
    {
        $viewer = auth()->user();

        // The office books are only half of the desk. A person in Uganda converts the shillings
        // they are actually holding into dollars before that transfer can be sent to China, and a
        // desk in China turns the dollars it was sent into yuan, so both sides of the list carry
        // this person's own wallets. Left out, the only accounts to choose from are the office's
        // and the cash in the desk's own hand cannot be moved.
        $accounts = array_merge(Account::ordered(), array_values(Account::walletsFor($viewer)));
        $holdings = [];
        foreach ($accounts as $account) {
            $holdings[$account->id] = Account::format($account->currency, $account->balance());
        }

        return view('exchanges.create', [
            'accounts' => $accounts,
            'holdings' => $holdings,
            'customers' => auth()->user()->can('view-customers') ? Customer::where('is_active', true)->orderBy('name')->get(['id', 'name', 'phone']) : collect(),
            'card' => ExchangeCalculator::card(),
            'currencies' => ExchangeCalculator::CURRENCIES,
            'paymentMethods' => CurrencyExchange::PAYMENT_METHODS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'person_name' => ['nullable', 'string', 'max:120'],
            'exchange_date' => ['required', 'date'],
            'in_currency' => ['required', Rule::in(ExchangeCalculator::CURRENCIES)],
            'in_amount' => ['required', 'numeric', 'min:0.01'],
            'in_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'out_currency' => ['required', 'different:in_currency', Rule::in(ExchangeCalculator::CURRENCIES)],
            'out_amount' => ['required', 'numeric', 'min:0.01'],
            'out_account_id' => ['required', 'integer', 'different:in_account_id', 'exists:accounts,id'],
            'payment_method' => ['nullable', Rule::in(CurrencyExchange::PAYMENT_METHODS)],
            'rate_reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'out_currency.different' => 'The two sides have to be different currencies. Take one currency in and hand another back.',
            'in_account_id.required' => 'Choose which account the money came into.',
            'out_account_id.required' => 'Choose which account the money was handed over from.',
            'out_account_id.different' => 'The money cannot leave the account it came into.',
            'in_amount.min' => 'Enter how much they handed over.',
            'out_amount.min' => 'Enter how much you handed back.',
            'fee.min' => 'The commission cannot be less than nothing.',
        ]);

        abort_if(!empty($validated['customer_id']) && !$request->user()->can('view-customers'), 403);
        $customer = isset($validated['customer_id']) ? Customer::find($validated['customer_id']) : null;
        $person = $customer?->name ?: trim((string) $validated['person_name']);

        if ($person === '') {
            return back()->withErrors(['person_name' => 'Say who the money was exchanged with.'])->withInput();
        }

        $inAccount = Account::findOrFail($validated['in_account_id']);
        $outAccount = Account::findOrFail($validated['out_account_id']);
        $inCurrency = strtoupper($validated['in_currency']);
        $outCurrency = strtoupper($validated['out_currency']);

        if ($inAccount->currency !== $inCurrency || $outAccount->currency !== $outCurrency) {
            $wrong = $inAccount->currency !== $inCurrency ? 'in_account_id' : 'out_account_id';
            $account = $wrong === 'in_account_id' ? $inAccount : $outAccount;
            $wanted = $wrong === 'in_account_id' ? $inCurrency : $outCurrency;

            return back()->withErrors([
                $wrong => $account->name.' holds '.$account->currency.', not '.$wanted
                    .'. Pick the account this money actually moved through.',
            ])->withInput();
        }

        $inAmount = number_format((float) $validated['in_amount'], 2, '.', '');
        $fee = empty($validated['fee'])
            ? '0.00'
            : number_format((float) $validated['fee'], 2, '.', '');

        if (bccomp($fee, $inAmount, 2) >= 0) {
            return back()->withErrors([
                'fee' => 'The commission is taken out of the '.($inCurrency).' handed over, so it has to be less than '
                    .Account::format($inCurrency, $inAmount).'.',
            ])->withInput();
        }

        $card = ExchangeCalculator::card();
        // The price is worked out on what is left after the commission, at full precision, and only
        // the answer is written the way that currency is written.
        $travels = bcsub($inAmount, $fee, 2);
        $atCard = ExchangeCalculator::suggest($card, $inCurrency, $travels, $outCurrency);
        $priced = $atCard === null ? null : ExchangeCalculator::settle($outCurrency, $atCard);

        $outAmount = ExchangeCalculator::settle($outCurrency, number_format((float) $validated['out_amount'], 2, '.', ''));

        // A figure the card gives anyway is the card's price. Anything else was agreed by hand at
        // the counter and has to say so, whoever typed it.
        $overridden = $priced !== null && $outAmount !== $priced;

        if ($overridden) {
            abort_unless($request->user()->can('override-exchange-rate'), 403);

            if (trim((string) ($validated['rate_reason'] ?? '')) === '') {
                return back()->withErrors([
                    'rate_reason' => 'This price is not the one on the card ('.Account::format($outCurrency, $priced)
                        .' for '.Account::format($inCurrency, $travels).' after the commission). '
                        .'Say what the different price was agreed on; it goes on the record.',
                ])->withInput();
            }
        }

        try {
            $exchange = CurrencyExchange::record([
                'exchange_date' => $validated['exchange_date'],
                'customer_id' => $customer?->id,
                'person_name' => $person,
                'in_currency' => $inCurrency,
                'in_amount' => $inAmount,
                'in_account_id' => $inAccount->id,
                'out_currency' => $outCurrency,
                'out_amount' => $outAmount,
                'out_account_id' => $outAccount->id,
                'fee' => $fee === '0.00' ? null : $fee,
                'payment_method' => $validated['payment_method'] ?? null,
                'rate' => $this->unitRate($travels, $outAmount),
                'rate_reason' => $overridden ? trim($validated['rate_reason']) : null,
                'margin_ugx' => ExchangeCalculator::margin($card, $inCurrency, $inAmount, $outCurrency, $outAmount),
                'notes' => $validated['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
        } catch (InsufficientFundsException $e) {
            return back()->withErrors(['out_amount' => $e->getMessage()])->withInput();
        }

        return redirect()->route('exchanges.show', $exchange)->with('success', $exchange->reference
            .' saved: '.Account::format($inCurrency, $inAmount).' taken in and '
            .Account::format($outCurrency, $outAmount).' handed to '.$person.'.');
    }

    /**
     * The deal saved as one number per unit of the other side, whichever way round gives a figure
     * of at least one. A counter deal of UGX 1,000,000 for $265.96 is kept as 3,759.96 shillings to
     * the dollar rather than 0.000265 dollars to the shilling, which a six-decimal column would
     * round away.
     */
    private function unitRate(string $inAmount, string $outAmount): string
    {
        return bccomp($inAmount, $outAmount, 2) >= 0
            ? bcdiv($inAmount, $outAmount, 6)
            : bcdiv($outAmount, $inAmount, 6);
    }

    public function show(CurrencyExchange $exchange)
    {
        abort_unless(CurrencyExchange::visibleTo(auth()->user())->whereKey($exchange->id)->exists(), 404);
        $exchange->load(['inAccount', 'outAccount', 'customer', 'creator']);

        return view('exchanges.show', [
            'exchange' => $exchange,
            'legs' => $exchange->legs(),
            'rule' => ExchangeCalculator::rule(ExchangeCalculator::card(), $exchange->in_currency, $exchange->out_currency),
        ]);
    }

    /**
     * The slip handed across the counter. Every figure on it comes off the deal's own saved
     * prices rather than the current card, so a reprint a year later still says what the customer
     * was actually given that day.
     */
    public function receipt(CurrencyExchange $exchange)
    {
        abort_unless(CurrencyExchange::visibleTo(auth()->user())->whereKey($exchange->id)->exists(), 404);
        $exchange->load('inAccount');

        return view('exchanges.receipt', ['exchange' => $exchange]);
    }

    public function void(Request $request, CurrencyExchange $exchange)
    {
        abort_unless(CurrencyExchange::visibleTo($request->user())->whereKey($exchange->id)->exists(), 404);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Say why this exchange is being cancelled. The reason goes on the ledger.',
        ]);

        if ($exchange->isVoid()) {
            return back()->withErrors(['reason' => $exchange->reference.' has already been cancelled.']);
        }

        $exchange->void($validated['reason']);

        return redirect()->route('exchanges.show', $exchange)
            ->with('success', $exchange->reference.' cancelled. Both accounts have been put back where they were.');
    }
}
