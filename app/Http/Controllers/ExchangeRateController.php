<?php

namespace App\Http\Controllers;

use App\Models\ExchangeRate;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    public function index()
    {
        $rates = ExchangeRate::latest()->get();

        return view('exchange-rates.index', compact('rates'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_currency' => 'required|string|max:10|in:USD,RMB,UGX',
            'to_currency' => 'required|string|max:10|in:USD,RMB,UGX|different:from_currency',
            'rate' => 'required|numeric|min:0.000001|max:999999.999999',
        ], [
            'from_currency.required' => 'Source currency is required.',
            'from_currency.in' => 'Invalid source currency.',
            'to_currency.required' => 'Target currency is required.',
            'to_currency.in' => 'Invalid target currency.',
            'to_currency.different' => 'Source and target currency must be different.',
            'rate.required' => 'Exchange rate is required.',
            'rate.min' => 'Exchange rate must be greater than zero.',
            'rate.max' => 'Exchange rate is too high.',
        ]);

        $existing = ExchangeRate::where('from_currency', $validated['from_currency'])
            ->where('to_currency', $validated['to_currency'])
            ->where('is_active', true)
            ->first();

        if ($existing && (float) $existing->rate === (float) $validated['rate']) {
            return back()->withErrors(['rate' => 'This exchange rate is already active.']);
        }

        ExchangeRate::where('from_currency', $validated['from_currency'])
            ->where('to_currency', $validated['to_currency'])
            ->update(['is_active' => false]);

        ExchangeRate::create([
            'from_currency' => strtoupper($validated['from_currency']),
            'to_currency' => strtoupper($validated['to_currency']),
            'rate' => $validated['rate'],
            'is_active' => true,
        ]);

        return back()->with('success', 'Exchange rate updated successfully.');
    }

    public function toggle(ExchangeRate $exchangeRate)
    {
        if ($exchangeRate->is_active) {
            return back()->withErrors(['rate' => 'Cannot deactivate the only active rate for this pair. Set a new rate first.']);
        }

        ExchangeRate::where('from_currency', $exchangeRate->from_currency)
            ->where('to_currency', $exchangeRate->to_currency)
            ->update(['is_active' => false]);

        $exchangeRate->update(['is_active' => true]);

        return back()->with('success', 'Exchange rate activated.');
    }
}
