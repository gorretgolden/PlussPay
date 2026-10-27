<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\RateSnapshot;
use App\Models\User;
use App\Services\MarketRateService;
use App\Services\ProfitCalculator;
use App\Support\Branding;
use App\Support\MarketBoard;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use RuntimeException;

class ExchangeRateController extends Controller
{
    public function index(Request $request)
    {
        // Default to showing only the rates currently in use. The Status dropdown lets anyone
        // switch to Earlier or All with one click, so nothing is hidden permanently.
        if (! $request->has('status')) {
            $request->merge(['status' => 'active']);
        }

        $rates = ExchangeRate::with(['creator', 'editor'])
            ->filtered($request)
            ->latest()
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $creators = User::whereIn('id', ExchangeRate::whereNotNull('created_by')->distinct()->pluck('created_by'))
            ->orderBy('name')
            ->get();

        return view('exchange-rates.index', compact('rates', 'creators'))
            ->with('market', MarketBoard::readings())
            ->with('spreadWarnings', $this->spreadWarnings())
            ->with('outsideRule', ExchangeRate::liveRowsOutsideRule())
            ->with('unpricedPairs', ExchangeRate::unpricedDeskPairs());
    }

    /**
     * Pull a reading without changing any price. The desk can then look at the
     * figure and decide whether to put it into a rate.
     */
    public function pullMarket(MarketRateService $marketRates)
    {
        try {
            $result = $marketRates->pull();
        } catch (RuntimeException $exception) {
            return back()->withErrors(['market_rate' => $exception->getMessage()]);
        }

        $first = reset($result['quotes']);
        $message = 'Market rates pulled from '.$first->source.' at '.$first->fetched_at->format('h:i A').'.';

        if ($result['note']) {
            $message .= ' '.$result['note'];
        }

        return back()->with('success', $message);
    }

    /**
     * Put the latest reading into a new price row. The market is a mid figure, so it
     * replaces only the side of the pair where it cannot cross the spread: the
     * buying price for shillings, the selling price for yuan. The other side stays
     * exactly as the desk typed it.
     */
    public function useMarket(Request $request)
    {
        $validated = $request->validate([
            'quote' => ['required', 'in:UGX,RMB'],
        ], [
            'quote.in' => 'Choose the shilling or the yuan reading.',
        ]);

        $quote = $validated['quote'];
        $pair = 'USD → '.$quote;
        $latest = RateSnapshot::latestFor('USD', $quote);
        $live = ExchangeRate::getActiveRate('USD', $quote);

        if (! $latest) {
            return back()->withErrors(['market_rate' => 'There is no '.$pair.' market reading yet. Pull the rate first.']);
        }

        if ($latest->fetched_at->lt(now()->subHours(26))) {
            return back()->withErrors(['market_rate' => 'The last '.$pair.' reading is more than a day old. Pull the rate now before using it.']);
        }

        if (! $live) {
            return back()->withErrors(['market_rate' => 'There is no live '.$pair.' price to update. Set one in System Settings first.']);
        }

        $market = (float) $latest->rate;
        $sell = (float) ($live->sell_rate ?: $live->rate);
        $buy = $live->buy_rate === null ? null : (float) $live->buy_rate;
        $readAt = $latest->fetched_at->format('d M Y, h:i A');

        if ($quote === 'UGX') {
            if ($market >= $sell) {
                return back()->withErrors(['market_rate' => 'The '.$readAt.' reading is '.$this->trim($market).' UGX per USD, at or above your selling price of '.$this->trim($sell).'. Raise the selling price in System Settings first, or the pair would lose money.']);
            }

            if ($buy !== null && abs($buy - $market) < 0.0000005) {
                return back()->withErrors(['market_rate' => 'The market reading is already the USD → UGX buying price.']);
            }

            $newSell = $sell;
            $newBuy = $market;
            $summary = 'selling stays '.$this->trim($newSell).', buying becomes '.$this->trim($newBuy);
        } else {
            if ($buy === null) {
                return back()->withErrors(['market_rate' => 'The USD → RMB price has no buying figure. Set both prices in System Settings first.']);
            }

            if ($market >= $buy) {
                return back()->withErrors(['market_rate' => 'The '.$readAt.' reading is '.$this->trim($market).' RMB per USD, at or above your buying price of '.$this->trim($buy).'. The yuan buying price has to sit above the selling price, so raise the buying price in System Settings first.']);
            }

            if (abs($sell - $market) < 0.0000005) {
                return back()->withErrors(['market_rate' => 'The market reading is already the USD → RMB selling price.']);
            }

            $newSell = $market;
            $newBuy = $buy;
            $summary = 'selling becomes '.$this->trim($newSell).', buying stays '.$this->trim($newBuy);
        }

        ExchangeRate::retirePair('USD', $quote);

        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => $quote,
            'rate' => $newSell,
            'sell_rate' => $newSell,
            'buy_rate' => $newBuy,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'exchange_rate_used_market',
            'description' => "Live price for {$pair} taken from the market reading of {$readAt} ({$latest->source}): {$summary}",
            'old_value' => 'selling '.$this->trim($sell).', buying '.($buy === null ? 'not set' : $this->trim($buy)),
            'new_value' => 'selling '.$this->trim($newSell).', buying '.$this->trim($newBuy),
        ]);

        return back()->with('success', $pair.' now reads '.$summary.', taken from the '.$readAt.' market reading.');
    }

    /**
     * A PDF the browser saves straight to the downloads folder, with the logo from
     * System Settings, the report name and the generation time across the top.
     */
    public function pdf(Request $request)
    {
        $rates = ExchangeRate::with(['creator', 'editor'])
            ->filtered($request)
            ->latest()
            ->orderByDesc('id')
            ->get();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'exported',
            'description' => 'Downloaded exchange rates PDF ('.$rates->count().' row(s))',
            'new_value' => (string) $rates->count(),
        ]);

        $dompdf = new Dompdf([
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->loadHtml(view('exchange-rates.pdf', [
            'rates' => $rates,
            'logo' => Branding::logoDataUri(),
            'company' => Branding::companyName(),
            'filters' => $this->filterSummary($request),
            'generated' => now(),
        ])->render());
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="PlusPay_Exchange_Rates_'.now()->format('Ymd-His').'.pdf"',
        ]);
    }

    /**
     * The filters as words, so a saved PDF says which slice of the history it holds.
     *
     * @return array<int, string>
     */
    private function filterSummary(Request $request): array
    {
        $filters = [];

        if ($request->filled('search')) {
            $filters[] = 'Search: '.$request->search;
        }

        if ($request->filled('currency')) {
            $filters[] = 'Currency: '.$request->currency;
        }

        if ((string) $request->input('status') !== '') {
            $filters[] = 'Status: '.($request->status === 'active' ? 'In use' : 'Earlier');
        }

        if ($request->filled('created_by')) {
            $name = User::whereKey($request->created_by)->value('name');
            $filters[] = 'Added by: '.($name ?: 'unknown');
        }

        if ($request->filled('date_from') || $request->filled('date_to')) {
            $filters[] = 'Dates: '.($request->date_from ?: 'start').' to '.($request->date_to ?: 'today');
        }

        return $filters;
    }

    /**
     * The same rows as the Excel export, printed as a paper sheet. The page carries no layout so
     * the browser prints only the table, and the filters ride along in the query string so the
     * print matches what the person was looking at.
     */
    public function print(Request $request)
    {
        $rates = ExchangeRate::with(['creator', 'editor'])
            ->filtered($request)
            ->latest()
            ->orderByDesc('id')
            ->get();

        return view('exchange-rates.print', compact('rates'));
    }

    /**
     * The two legs run in opposite directions, so a pair that looks healthy on one is a
     * loss on the other. Shillings are bought cheap from the customer and sold dear to us,
     * so selling must sit above buying. Yuan are the reverse: the receiver is credited at the
     * selling price but the China side only settles with us at the buying price, so buying has
     * to sit above selling or every transfer loses money. A pair with no buying price recorded
     * is left alone here; the table already flags it as pending.
     *
     * @return array<int, string>
     */
    private function spreadWarnings(): array
    {
        $warnings = [];

        $shilling = ExchangeRate::getActiveRate('USD', 'UGX');
        if ($shilling && $shilling->buy_rate !== null) {
            $sell = (float) ($shilling->sell_rate ?: $shilling->rate);
            $buy = (float) $shilling->buy_rate;
            if ($sell <= $buy) {
                $warnings[] = sprintf(
                    'USD to UGX: the selling price (%s) is at or below the buying price (%s), so this pair earns nothing.',
                    $this->trim($sell),
                    $this->trim($buy),
                );
            }
        }

        $yuan = ExchangeRate::getActiveRate('USD', 'RMB');
        if ($yuan && $yuan->buy_rate !== null) {
            $sell = (float) ($yuan->sell_rate ?: $yuan->rate);
            $buy = (float) $yuan->buy_rate;
            if ($sell >= $buy) {
                $warnings[] = sprintf(
                    'USD to RMB: the selling price (%s) is at or above the buying price (%s), so each dollar sent loses money.',
                    $this->trim($sell),
                    $this->trim($buy),
                );
            }
        }

        return $warnings;
    }

    private function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    /**
     * Correct the two figures on a price that is already on the record. The pair, who entered it
     * and the dates stay where they are; only the numbers move. Editing the row that is live now
     * cannot disturb anything already booked, because every transfer keeps the figures it was
     * entered with. An older row is a different thing: it is what the books read back for the
     * transfers entered while it was the price, so that one asks to be confirmed.
     */
    public function update(Request $request, ExchangeRate $exchangeRate)
    {
        $rules = [
            'sell_rate' => ['required', 'numeric', 'min:0.000001', 'max:999999.999999'],
            'buy_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:999999.999999'],
        ];

        if (! $exchangeRate->is_active) {
            $rules['history_ok'] = ['accepted'];
        }

        $validated = $request->validate($rules, [
            'sell_rate.required' => 'Enter the selling price, the figure customers are quoted.',
            'sell_rate.numeric' => 'The selling price must be a number, e.g. 3760.',
            'sell_rate.min' => 'The selling price must be greater than zero.',
            'sell_rate.max' => 'The selling price is too high.',
            'buy_rate.numeric' => 'The buying price must be a number, e.g. 3750, or left empty.',
            'buy_rate.min' => 'The buying price must be greater than zero.',
            'buy_rate.max' => 'The buying price is too high.',
            'history_ok.accepted' => 'Confirm that this is an earlier price, so correcting it changes how the transfers entered under it read.',
        ]);

        $sell = $this->figure($validated['sell_rate']);
        $buy = filled($validated['buy_rate'] ?? null) ? $this->figure($validated['buy_rate']) : $sell;

        $liveSell = (string) ($exchangeRate->sell_rate ?: $exchangeRate->rate);
        $liveBuy = $exchangeRate->buy_rate === null ? null : (string) $exchangeRate->buy_rate;

        if (bccomp($sell, $liveSell, 6) === 0 && bccomp($buy, (string) ($liveBuy ?? $liveSell), 6) === 0) {
            return back()->withErrors(['sell_rate' => 'Those are the figures already on this row, so nothing was changed.']);
        }

        $pair = $exchangeRate->from_currency.' → '.$exchangeRate->to_currency;
        $before = 'selling '.$this->trim((float) $liveSell).', buying '.($liveBuy === null ? 'not set' : $this->trim((float) $liveBuy));
        $after = 'selling '.$this->trim((float) $sell).', buying '.$this->trim((float) $buy);

        $exchangeRate->update(['rate' => $sell, 'sell_rate' => $sell, 'buy_rate' => $buy]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'exchange_rate_edited',
            'description' => ($exchangeRate->is_active ? "Live price" : "Price from ".$exchangeRate->created_at->format('M d, Y'))
                ." for {$pair} corrected from {$before} to {$after}",
            'old_value' => $before,
            'new_value' => $after,
        ]);

        return back()->with('success', $pair.' corrected to '.$after.'. '.$this->editOutcome($exchangeRate));
    }

    /**
     * Whether the corrected row is the one new bookings read, said shortly so the flash names the
     * clash instead of leaving a duplicate price looking like the live one.
     */
    private function editOutcome(ExchangeRate $rate): string
    {
        if (! ExchangeRate::isDeskPair($rate->from_currency, $rate->to_currency)) {
            return 'This direction prices nothing.';
        }

        $read = ExchangeRate::getActiveRate($rate->from_currency, $rate->to_currency);

        if ($read && $read->id === $rate->id) {
            return 'It is the price in use.';
        }

        return $rate->is_active
            ? 'Another price for this pair is the one in use.'
            : 'It is an earlier price.';
    }

    private function figure(string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    /**
     * Switch a price between in use and earlier. Bringing one in always sets the previous price for
     * that pair aside, so a pair can never hold two current prices, and only the two desk pairs can
     * be brought in at all: a reverse-direction row would quietly stand in for a price the desk
     * reads with figures nobody set.
     */
    public function toggle(Request $request, ExchangeRate $exchangeRate)
    {
        $pair = $exchangeRate->from_currency.' → '.$exchangeRate->to_currency;
        $price = $this->trim((float) ($exchangeRate->sell_rate ?: $exchangeRate->rate));

        if ($exchangeRate->is_active) {
            // A desk pair with no price in use cannot price a booking at all, so the last live
            // row for one is protected rather than leaving the counter to discover the gap.
            if (ExchangeRate::isDeskPair($exchangeRate->from_currency, $exchangeRate->to_currency)
                && ! ExchangeRate::where('from_currency', $exchangeRate->from_currency)
                    ->where('to_currency', $exchangeRate->to_currency)
                    ->where('is_active', true)
                    ->where('id', '!=', $exchangeRate->id)
                    ->exists()) {
                return back()->withErrors(['error' => $pair.' '.$price.' is the only price in use for '.$pair.'. Bookings cannot be priced without it, so set another price for the pair first.']);
            }

            $exchangeRate->setAside();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'exchange_rate_deactivated',
                'description' => "Price {$pair} {$price} taken out of use",
                'old_value' => 'in use',
                'new_value' => 'earlier',
            ]);

            return back()->with('success', $pair.' '.$price.' is out of use.');
        }

        if (! ExchangeRate::isDeskPair($exchangeRate->from_currency, $exchangeRate->to_currency)) {
            return back()->withErrors(['error' => $pair.' cannot be set as current. Only '.ExchangeRate::deskPairsList().' price bookings.']);
        }

        $current = ExchangeRate::getActiveRate($exchangeRate->from_currency, $exchangeRate->to_currency);
        $exchangeRate->putIntoUse();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'exchange_rate_activated',
            'description' => "Rate for {$pair} switched to {$exchangeRate->rate} (was ".($current ? $current->rate : 'none').')',
            'old_value' => $current ? (string) $current->rate : 'none',
            'new_value' => (string) $exchangeRate->rate,
        ]);

        return back()->with('success', $current
            ? $pair.' '.$price.' is the price in use, and '.$this->trim((float) ($current->sell_rate ?: $current->rate)).' is now an earlier price.'
            : $pair.' '.$price.' is the price in use.');
    }
}
