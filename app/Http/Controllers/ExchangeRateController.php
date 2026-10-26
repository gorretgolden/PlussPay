<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\ProfitCalculator;
use Illuminate\Http\Request;

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
            ->with('spreadWarnings', $this->spreadWarnings())
            ->with('outsideRule', ExchangeRate::liveRowsOutsideRule())
            ->with('unpricedPairs', ExchangeRate::unpricedDeskPairs());
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
