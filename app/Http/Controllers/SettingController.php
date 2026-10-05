<?php

namespace App\Http\Controllers;

use App\Models\ExchangeRate;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->groupBy('group');
        $usdToUgx = ExchangeRate::usdToUgx();
        $usdToRmb = ExchangeRate::getActiveRate('USD', 'RMB');

        return view('settings.index', compact('settings', 'usdToUgx', 'usdToRmb'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'company_name' => 'nullable|string|max:100',
            'company_address' => 'nullable|string|max:255',
            'company_phone' => 'nullable|string|max:20',
            'company_email' => 'nullable|email|max:100',
            'default_charge_rate' => 'nullable|numeric|min:0|max:100',
            'min_transaction_amount' => 'nullable|numeric|min:0',
            'max_transaction_amount' => 'nullable|numeric|min:0',
            'receipt_prefix' => 'nullable|string|max:10',
            'usd_ugx_rate' => 'nullable|numeric|min:0.01|max:999999.99',
            'usd_rmb_rate' => 'nullable|numeric|min:0.000001|max:999999.999999',
        ], [
            'usd_ugx_rate.numeric' => 'The dollar rate must be a number, e.g. 3700.',
            'usd_ugx_rate.min' => 'The dollar rate must be at least 0.01.',
            'usd_ugx_rate.max' => 'The dollar rate is too high.',
            'usd_rmb_rate.numeric' => 'The RMB per dollar rate must be a number, e.g. 7.24.',
        ]);

        // Rates live in the exchange rate table, not settings, so every screen converts with the same figure.
        $usdUgx = $validated['usd_ugx_rate'] ?? null;
        $usdRmb = $validated['usd_rmb_rate'] ?? null;
        unset($validated['usd_ugx_rate'], $validated['usd_rmb_rate']);

        foreach ($validated as $key => $value) {
            $type = in_array($key, ['default_charge_rate', 'min_transaction_amount', 'max_transaction_amount']) ? 'float' : 'string';
            $group = str_starts_with($key, 'company_') ? 'company' : 'transaction';
            Setting::set($key, $value, $type, $group);
        }

        if ($usdUgx !== null && $usdUgx !== '') {
            ExchangeRate::setPair('USD', 'UGX', (float) $usdUgx);
        }

        if ($usdRmb !== null && $usdRmb !== '') {
            ExchangeRate::setPair('USD', 'RMB', (float) $usdRmb);
        }

        return back()->with('success', 'Settings updated successfully.');
    }
}
