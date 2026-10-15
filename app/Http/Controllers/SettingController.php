<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    private const TAB_FIELDS = [
        'rates' => ['usd_ugx_buying', 'usd_ugx_selling', 'usd_rmb_buying', 'usd_rmb_selling'],
        'transactions' => ['service_fee_ugx', 'service_fee_usd', 'min_transaction_amount', 'max_transaction_amount', 'receipt_prefix'],
        'company' => ['company_name', 'company_address', 'company_phone', 'company_email', 'company_website'],
        'datetime' => ['timezone'],
        'site' => ['site_mode'],
    ];

    public function index(Request $request)
    {
        $settings = Setting::all()->groupBy('group');
        $usdToUgx = ExchangeRate::usdToUgx();
        $usdUgxPair = ExchangeRate::usdUgxPair();
        $usdToRmb = ExchangeRate::getActiveRate('USD', 'RMB');
        $usdRmbPair = ExchangeRate::pair('USD', 'RMB');
        $testingModeBlocked = self::testingModeBlocked($request);

        return view('settings.index', compact(
            'settings',
            'usdToUgx',
            'usdUgxPair',
            'usdToRmb',
            'usdRmbPair',
            'testingModeBlocked'
        ));
    }

    public function update(Request $request)
    {
        $request->validate(['settings_tab' => ['sometimes', 'required', Rule::in(array_keys(self::TAB_FIELDS))]]);
        $tab = $request->input('settings_tab');
        $rules = [
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
            'site_mode' => ['sometimes', 'required', 'in:testing,production'],
            'timezone' => ['nullable', Rule::in(array_keys(Setting::TIMEZONES))],
        ];
        // Older callers may omit a tab; tabbed forms can only save their own fields.
        if ($tab !== null) {
            $rules = array_intersect_key($rules, array_flip(self::TAB_FIELDS[$tab]));
        }
        $validated = $request->validate($rules, [
            'receipt_prefix.regex' => 'The receipt prefix can only contain letters and numbers, e.g. PS.',
            'site_mode.in' => 'Site mode must be Testing or Production.',
            'timezone.in' => 'Please choose one of the listed time zones.',
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
        $ugxBuy = $this->rateInput($validated, 'usd_ugx_buying');
        $ugxSell = $this->rateInput($validated, 'usd_ugx_selling');
        $rmbBuy = $this->rateInput($validated, 'usd_rmb_buying');
        $rmbSell = $this->rateInput($validated, 'usd_rmb_selling');
        unset(
            $validated['usd_ugx_buying'],
            $validated['usd_ugx_selling'],
            $validated['usd_rmb_buying'],
            $validated['usd_rmb_selling']
        );

        // Site mode decides whether the sign-in page prints the real admin emails,
        // so it stays a Super Admin decision even though Admins can edit other settings.
        if (! $request->user()->hasRole('superadmin')) {
            unset($validated['site_mode']);
        }
        // Reject before any writes, even if a forged request bypasses the disabled option.
        if (($validated['site_mode'] ?? null) === 'testing' && self::testingModeBlocked($request)) {
            throw ValidationException::withMessages([
                'site_mode' => 'Testing is unavailable on production. Use a local or non-production host.',
            ]);
        }

        $changes = [];
        $oldValues = [];
        $newValues = [];

        foreach ($validated as $key => $value) {
            $type = in_array($key, ['service_fee_ugx', 'service_fee_usd', 'min_transaction_amount', 'max_transaction_amount']) ? 'float' : 'string';
            $group = match (true) {
                in_array($key, ['site_mode', 'timezone'], true) => 'display',
                str_starts_with($key, 'company_') => 'company',
                default => 'transaction',
            };

            $previous = Setting::get($key);
            $unchanged = $type === 'float' && $previous !== null && $value !== null
                ? (float) $previous === (float) $value
                : (string) $previous === (string) $value;
            if ($unchanged) {
                continue;
            }

            Setting::set($key, $value, $type, $group);
            $changes[] = "{$key} changed from ".($previous ?? 'unset').' to '.($value ?? 'blank');
            $oldValues[$key] = $previous;
            $newValues[$key] = $value;
        }

        if ($ugxSell !== null) {
            $before = ExchangeRate::usdUgxPair();
            $buy = $ugxBuy ?? $before['buy'] ?? $ugxSell;

            // Saving with the figures already on the screen must not rewrite the pair.
            // setPair retires the old row and inserts a new one, so a no-op save was
            // filling the rates table with duplicates and logging a change that never happened.
            if (! $this->samePair($before, $buy, $ugxSell)) {
                // The customer-facing figure keeps driving every conversion; the buying rate only measures margin.
                ExchangeRate::setPair('USD', 'UGX', $ugxSell, $buy, $ugxSell);
                $changes[] = 'USD to UGX selling rate changed from '.($before['sell'] ?? 'unset').' to '.$ugxSell
                    .($ugxBuy !== null ? ', buying rate to '.$ugxBuy : '');
                $oldValues['usd_ugx_selling'] = $before['sell'] ?? null;
                $newValues['usd_ugx_selling'] = $ugxSell;
                if ($ugxBuy !== null) {
                    $oldValues['usd_ugx_buying'] = $before['buy'] ?? null;
                    $newValues['usd_ugx_buying'] = $ugxBuy;
                }
            }
        }

        if ($rmbSell !== null) {
            $before = ExchangeRate::pair('USD', 'RMB');
            $buy = $rmbBuy ?? $before['buy'] ?? $rmbSell;

            if (! $this->samePair($before, $buy, $rmbSell)) {
                ExchangeRate::setPair('USD', 'RMB', $rmbSell, $buy, $rmbSell);
                $changes[] = 'USD to RMB selling rate changed from '.($before['sell'] ?? 'unset').' to '.$rmbSell
                    .($rmbBuy !== null ? ', buying rate to '.$rmbBuy : '');
                $oldValues['usd_rmb_selling'] = $before['sell'] ?? null;
                $newValues['usd_rmb_selling'] = $rmbSell;
                if ($rmbBuy !== null) {
                    $oldValues['usd_rmb_buying'] = $before['buy'] ?? null;
                    $newValues['usd_rmb_buying'] = $rmbBuy;
                }
            }
        }

        if ($changes) {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'settings_updated',
                'description' => implode('; ', $changes),
                'old_value' => json_encode($oldValues),
                'new_value' => json_encode($newValues),
            ]);
        }

        return back()
            ->with('success', $changes ? 'Settings saved.' : 'No changes to save.')
            ->with('settings_saved_tab', $tab ?? 'rates');
    }

    /**
     * Also usable by the sign-in view to suppress legacy Testing settings.
     * This reads deployment configuration; it never changes it.
     */
    public static function testingModeBlocked(Request $request): bool
    {
        if (app()->environment('production')) {
            return true;
        }

        $host = strtolower(rtrim((string) parse_url((string) config('app.url'), PHP_URL_HOST), '.'));
        $local = $host === '' || $host === 'localhost' || ! str_contains($host, '.')
            || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.test');
        if (filter_var($host, FILTER_VALIDATE_IP)
            && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $local = true;
        }

        return ! $local && $host === strtolower(rtrim($request->getHost(), '.'));
    }

    /**
     * Blank selling skips a pair; blank buying preserves its saved buying rate.
     */
    private function rateInput(array $validated, string $key): ?float
    {
        $value = trim((string) ($validated[$key] ?? ''));

        return $value === '' ? null : (float) $value;
    }

    /**
     * Both sides come back as decimals cast to float, so compare with a tolerance
     * instead of equality. A pair saved before the buy and sell split reports the
     * same figure twice, which still counts as unchanged.
     *
     * @param array{buy: float, sell: float}|null $before
     */
    private function samePair(?array $before, float $buy, float $sell): bool
    {
        return $before !== null
            && abs($before['sell'] - $sell) < 0.0000005
            && abs($before['buy'] - $buy) < 0.0000005;
    }
}
