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
            'from_currency' => 'required|string|max:10',
            'to_currency' => 'required|string|max:10',
            'rate' => 'required|numeric|min:0',
        ]);

        ExchangeRate::where('from_currency', $validated['from_currency'])
            ->where('to_currency', $validated['to_currency'])
            ->update(['is_active' => false]);

        ExchangeRate::create([
            'from_currency' => $validated['from_currency'],
            'to_currency' => $validated['to_currency'],
            'rate' => $validated['rate'],
            'is_active' => true,
        ]);

        return back()->with('success', 'Exchange rate updated.');
    }

    public function toggle(ExchangeRate $exchangeRate)
    {
        $exchangeRate->update(['is_active' => !$exchangeRate->is_active]);

        return back()->with('success', 'Exchange rate status updated.');
    }
}
