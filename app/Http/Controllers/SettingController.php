<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->groupBy('group');

        return view('settings.index', compact('settings'));
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
        ]);

        foreach ($validated as $key => $value) {
            $type = in_array($key, ['default_charge_rate', 'min_transaction_amount', 'max_transaction_amount']) ? 'float' : 'string';
            $group = str_starts_with($key, 'company_') ? 'company' : 'transaction';
            Setting::set($key, $value, $type, $group);
        }

        return back()->with('success', 'Settings updated successfully.');
    }
}
