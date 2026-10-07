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
        $usdUgxPair = ExchangeRate::usdUgxPair();
        $usdToRmb = ExchangeRate::getActiveRate('USD', 'RMB');
        $usdRmbPair = ExchangeRate::pair('USD', 'RMB');

        return view('settings.index', compact(
            'settings',
            'usdToUgx',
            'usdUgxPair',
            'usdToRmb',
            'usdRmbPair'
        ));
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
            'usd_ugx_buying' => 'nullable|numeric|min:0.01|max:999999.99',
            'usd_ugx_selling' => 'nullable|numeric|min:0.01|max:999999.99',
            'usd_rmb_buying' => 'nullable|numeric|min:0.000001|max:999999.999999',
            'usd_rmb_selling' => 'nullable|numeric|min:0.000001|max:999999.999999',
        ], [
            'receipt_prefix.regex' => 'The receipt prefix can only contain letters and numbers, e.g. PS.',
            'usd_ugx_buying.numeric' => 'The UGX buying rate must be a number, e.g. 3750.',
            'usd_ugx_selling.numeric' => 'The UGX selling rate must be a number, e.g. 3760.',
            'usd_ugx_buying.min' => 'The UGX buying rate must be at least 0.01.',
            'usd_ugx_selling.min' => 'The UGX selling rate must be at least 0.01.',
            'usd_ugx_selling.max' => 'The UGX rate is too high.',
            'usd_rmb_buying.numeric' => 'The RMB buying rate must be a number, e.g. 6.70.',
            'usd_rmb_selling.numeric' => 'The RMB selling rate must be a number, e.g. 6.75.',
            'service_fee_usd.min' => 'The USD amount must be greater than zero, e.g. 100.',
        ]);

        // Rates live in the exchange rate table, not settings, so every screen converts with the same figure.
        $ugxBuy = $this->rateInput($request, 'usd_ugx_buying');
        $ugxSell = $this->rateInput($request, 'usd_ugx_selling');
        $rmbBuy = $this->rateInput($request, 'usd_rmb_buying');
        $rmbSell = $this->rateInput($request, 'usd_rmb_selling');
        unset(
            $validated['usd_ugx_buying'],
            $validated['usd_ugx_selling'],
            $validated['usd_rmb_buying'],
            $validated['usd_rmb_selling']
        );

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

        if ($ugxSell !== null) {
            $before = ExchangeRate::usdUgxPair();
            // The customer-facing figure keeps driving every conversion; the buying rate only measures margin.
            ExchangeRate::setPair('USD', 'UGX', $ugxSell, $ugxBuy ?? $ugxSell, $ugxSell);
            $changes[] = 'USD to UGX selling rate changed from '.($before['sell'] ?? 'unset').' to '.$ugxSell
                .($ugxBuy !== null ? ', buying rate to '.$ugxBuy : '');
        }

        if ($rmbSell !== null) {
            $before = ExchangeRate::pair('USD', 'RMB');
            ExchangeRate::setPair('USD', 'RMB', $rmbSell, $rmbBuy ?? $rmbSell, $rmbSell);
            $changes[] = 'USD to RMB selling rate changed from '.($before['sell'] ?? 'unset').' to '.$rmbSell
                .($rmbBuy !== null ? ', buying rate to '.$rmbBuy : '');
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

    /**
     * A blank rate box means "leave that rate alone", not "set it to zero".
     */
    private function rateInput(Request $request, string $key): ?float
    {
        $value = trim((string) $request->input($key, ''));

        return $value === '' ? null : (float) $value;
    }
}
