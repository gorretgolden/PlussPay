<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
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
            'company_phone' => 'nullable|string|max:60',
            'company_email' => 'nullable|email|max:100',
            'company_website' => 'nullable|string|max:100',
            'service_fee_ugx' => 'nullable|numeric|min:0',
            'service_fee_usd' => 'nullable|numeric|min:0.01',
            'min_transaction_amount' => 'nullable|numeric|min:0',
            'max_transaction_amount' => 'nullable|numeric|min:0',
            'receipt_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'usd_ugx_rate' => 'nullable|numeric|min:0.01|max:999999.99',
            'usd_rmb_rate' => 'nullable|numeric|min:0.000001|max:999999.999999',
        ], [
            'receipt_prefix.regex' => 'The receipt prefix can only contain letters and numbers, e.g. PS.',
            'usd_ugx_rate.numeric' => 'The dollar rate must be a number, e.g. 3700.',
            'usd_ugx_rate.min' => 'The dollar rate must be at least 0.01.',
            'usd_ugx_rate.max' => 'The dollar rate is too high.',
            'usd_rmb_rate.numeric' => 'The RMB per dollar rate must be a number, e.g. 7.24.',
            'service_fee_usd.min' => 'The USD amount must be greater than zero, e.g. 100.',
        ]);

        // Rates live in the exchange rate table, not settings, so every screen converts with the same figure.
        $usdUgx = $validated['usd_ugx_rate'] ?? null;
        $usdRmb = $validated['usd_rmb_rate'] ?? null;
        unset($validated['usd_ugx_rate'], $validated['usd_rmb_rate']);

        $changes = [];

        foreach ($validated as $key => $value) {
            $type = in_array($key, ['service_fee_ugx', 'service_fee_usd', 'min_transaction_amount', 'max_transaction_amount']) ? 'float' : 'string';
            $group = str_starts_with($key, 'company_') ? 'company' : 'transaction';

            $previous = Setting::get($key);
            if ((string) $previous === (string) $value) {
                continue;
            }

            Setting::set($key, $value, $type, $group);
            $changes[] = "{$key} changed from ".($previous ?? 'unset').' to '.($value ?? 'blank');
        }

        if ($usdUgx !== null && $usdUgx !== '') {
            $before = ExchangeRate::usdToUgx();
            ExchangeRate::setPair('USD', 'UGX', (float) $usdUgx);
            $changes[] = 'USD to UGX rate changed from '.($before ?? 'unset').' to '.$usdUgx;
        }

        if ($usdRmb !== null && $usdRmb !== '') {
            $before = ExchangeRate::getActiveRate('USD', 'RMB');
            ExchangeRate::setPair('USD', 'RMB', (float) $usdRmb);
            $changes[] = 'USD to RMB rate changed from '.($before?->rate ?? 'unset').' to '.$usdRmb;
        }

        if ($changes) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'settings_updated',
                'description' => implode('; ', $changes),
                'new_value' => (string) count($changes),
            ]);
        }

        return back()->with('success', 'Settings updated successfully.');
    }
}
