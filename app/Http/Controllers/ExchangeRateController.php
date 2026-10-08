<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\User;
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

        return view('exchange-rates.index', compact('rates', 'creators'));
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

        if ($existing && (float) $existing->rate === (float) $validated['rate']) {
            return back()->withErrors(['rate' => 'This exchange rate is already active.']);
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
