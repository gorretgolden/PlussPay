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
            ->with('sendExample', $this->sendExample());
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
                    'USD to UGX: the selling price (%s) is at or below the buying price (%s). '
                    . 'Shillings make money only when we sell above what we pay, so this pair earns nothing as it stands.',
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
                    'USD to RMB: the selling price (%s) is at or above the buying price (%s). '
                    . 'We credit the receiver at the selling price but the China side settles at the buying price, so each dollar sent now loses money.',
                    $this->trim($sell),
                    $this->trim($buy),
                );
            }
        }

        return $warnings;
    }

    /**
     * One real transfer priced through the same calculator a booking uses, so the warning
     * is not abstract: it shows the charge, the exchange profit and the overall profit for a
     * concrete hand-over of shillings. Null when the USD to UGX pair is missing, since then
     * nothing prices.
     *
     * @return array<string, string>|null
     */
    private function sendExample(): ?array
    {
        $pair = ExchangeRate::usdUgxPair();
        if (! $pair || $pair['sell'] <= 0) {
            return null;
        }

        // A send that leaves exactly a thousand dollars behind the counter: the charge the
        // settings rule gives for those dollars, added to the shillings they are sold at.
        $charge = ProfitCalculator::suggestedCharge('USD', '1000') ?? '0.00';
        $cash = bcadd(bcmul('1000', (string) $pair['sell'], 2), $charge, 2);
        $quote = ProfitCalculator::quote('UGX', $cash, $charge);
        if ($quote === null) {
            return null;
        }

        $shilling = function (string $value): string {
            $figure = (float) $value;

            return $figure < 0
                ? 'Loss UGX ' . number_format(abs($figure), 2)
                : 'UGX ' . number_format($figure, 2);
        };

        return [
            'hands_over' => $shilling($cash),
            'travels' => $shilling($quote['amount']),
            'dollars' => 'USD ' . number_format((float) $quote['total_usd_equivalent'], 1),
            'charge' => $shilling($quote['charge_ugx']),
            'exchange' => $shilling(bcadd($quote['fx_profit_ugx'], $quote['usd_rmb_profit_ugx'], 2)),
            'total' => $shilling($quote['total_profit_ugx']),
        ];
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

        $pair = $exchangeRate->from_currency.'-'.$exchangeRate->to_currency;
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

        return back()->with('success', $pair.' corrected to '.$after.'. '
            .($exchangeRate->is_active
                ? 'It is the price now live, so every new booking and quote uses it.'
                : 'It is an earlier price, so it is what the books read back for transfers entered under it.'));
    }

    private function figure(string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    public function toggle(Request $request, ExchangeRate $exchangeRate)
    {
        // A pair with no price at all stops every conversion working, so the live row cannot be
        // switched off. The way to change a price is to set a new one, or to bring an older one back.
        if ($exchangeRate->is_active) {
            return back()->withErrors(['rate' => 'This is the price in use now. Change the prices in Settings, or bring an older price back by activating that row.']);
        }

        $pair = $exchangeRate->from_currency.'-'.$exchangeRate->to_currency;
        $current = ExchangeRate::where('from_currency', $exchangeRate->from_currency)
            ->where('to_currency', $exchangeRate->to_currency)
            ->where('is_active', true)
            ->first();

        ExchangeRate::retirePair($exchangeRate->from_currency, $exchangeRate->to_currency);

        $exchangeRate->update(['is_active' => true]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'exchange_rate_activated',
            'description' => "Rate for {$pair} switched to {$exchangeRate->rate} (was ".($current ? $current->rate : 'none').')',
            'old_value' => $current ? (string) $current->rate : 'none',
            'new_value' => (string) $exchangeRate->rate,
        ]);

        return back()->with('success', 'Exchange rate activated.');
    }
}
