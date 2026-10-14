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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_currency' => 'required|string|max:10|in:USD,RMB,UGX',
            'to_currency' => 'required|string|max:10|in:USD,RMB,UGX|different:from_currency',
            'rate' => 'required|numeric|min:0.000001|max:999999.999999',
            'buy_rate' => 'nullable|numeric|min:0.000001|max:999999.999999',
        ], [
            'from_currency.required' => 'Source currency is required.',
            'from_currency.in' => 'Invalid source currency.',
            'to_currency.required' => 'Target currency is required.',
            'to_currency.in' => 'Invalid target currency.',
            'to_currency.different' => 'Source and target currency must be different.',
            'rate.required' => 'Exchange rate is required.',
            'rate.min' => 'Exchange rate must be greater than zero.',
            'rate.max' => 'Exchange rate is too high.',
            'buy_rate.min' => 'The buying rate must be greater than zero.',
            'buy_rate.max' => 'The buying rate is too high.',
        ]);

        $existing = ExchangeRate::where('from_currency', $validated['from_currency'])
            ->where('to_currency', $validated['to_currency'])
            ->where('is_active', true)
            ->first();

        // Only a genuinely identical pair is refused. Re-saving the same selling rate
        // with a different buying price is a real edit, so it has to go through.
        $newBuy = $validated['buy_rate'] ?? $validated['rate'];

        if ($existing
            && (float) $existing->rate === (float) $validated['rate']
            && (float) ($existing->buy_rate ?? $existing->rate) === (float) $newBuy) {
            return back()->withErrors(['rate' => 'This rate and buying price are already active.']);
        }

        $previous = $existing ? (string) $existing->rate : 'none';

        ExchangeRate::retirePair($validated['from_currency'], $validated['to_currency']);

        // The single rate entered here is always the customer-facing (selling) price.
        ExchangeRate::create([
            'from_currency' => strtoupper($validated['from_currency']),
            'to_currency' => strtoupper($validated['to_currency']),
            'rate' => $validated['rate'],
            'buy_rate' => $validated['buy_rate'] ?? $validated['rate'],
            'sell_rate' => $validated['rate'],
            'is_active' => true,
            'created_by' => $request->user()->id,
        ]);

        $pair = strtoupper($validated['from_currency']).'-'.strtoupper($validated['to_currency']);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'exchange_rate_set',
            'description' => "Active rate for {$pair} set to {$validated['rate']} (was {$previous})",
            'old_value' => $previous,
            'new_value' => (string) $validated['rate'],
        ]);

        return back()->with('success', 'Exchange rate updated successfully.');
    }

    public function toggle(Request $request, ExchangeRate $exchangeRate)
    {
        if ($exchangeRate->is_active) {
            return back()->withErrors(['rate' => 'Cannot deactivate the only active rate for this pair. Set a new rate first.']);
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
