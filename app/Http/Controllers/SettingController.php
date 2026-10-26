<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\RateSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Services\MarketRateService;
use Database\Seeders\RoleAndUserSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SettingController extends Controller
{
    private const TAB_FIELDS = [
        'rates' => ['usd_ugx_buying', 'usd_ugx_selling', 'usd_rmb_buying', 'usd_rmb_selling', 'market_buy_auto'],
        'transactions' => [
            'min_transaction_ugx', 'max_transaction_ugx',
            'min_transaction_usd', 'max_transaction_usd',
            'min_transaction_rmb', 'max_transaction_rmb',
            'receipt_prefix', 'default_charge_ugx',
        ],
        'company' => ['company_name', 'company_address', 'company_phone', 'company_email', 'company_website'],
        'datetime' => ['timezone'],
        'site' => ['site_mode', 'demo_login_autofill', 'demo_password'],
    ];

    private const TRANSACTION_BOUND_KEYS = [
        'min_transaction_ugx', 'max_transaction_ugx',
        'min_transaction_usd', 'max_transaction_usd',
        'min_transaction_rmb', 'max_transaction_rmb',
    ];

    public function __construct(private readonly MarketRateService $marketRates)
    {
    }

    /**
     * The sign-in page fills these accounts, so saving a demo password has to move the
     * accounts themselves or the button offers a password that no longer signs anyone in.
     * Same list the seeder builds from, so the two can never drift apart.
     */
    private function demoEmails(): array
    {
        return array_column(RoleAndUserSeeder::DEMO_ACCOUNTS, 'email');
    }

    public function index(Request $request)
    {
        $settings = Setting::all()->groupBy('group');
        $usdUgxRow = ExchangeRate::getActiveRate('USD', 'UGX');
        $usdRmbRow = ExchangeRate::getActiveRate('USD', 'RMB');
        $usdUgxPair = ExchangeRate::usdUgxPair();
        $usdRmbPair = ExchangeRate::pair('USD', 'RMB');
        $testingModeBlocked = self::testingModeBlocked($request);
        $market = $this->marketBoard();

        return view('settings.index', compact(
            'settings',
            'usdUgxRow',
            'usdRmbRow',
            'usdUgxPair',
            'usdRmbPair',
            'testingModeBlocked',
            'market'
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
            'min_transaction_ugx' => 'nullable|numeric|min:0',
            'max_transaction_ugx' => 'nullable|numeric|min:0',
            'min_transaction_usd' => 'nullable|numeric|min:0',
            'max_transaction_usd' => 'nullable|numeric|min:0',
            'min_transaction_rmb' => 'nullable|numeric|min:0',
            'max_transaction_rmb' => 'nullable|numeric|min:0',
            'receipt_prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'default_charge_ugx' => 'nullable|numeric|min:0',
            'usd_ugx_buying' => 'nullable|numeric|min:0.01|max:999999.99',
            'usd_ugx_selling' => 'nullable|numeric|min:0.01|max:999999.99',
            'usd_rmb_buying' => 'nullable|numeric|min:0.000001|max:999999.999999',
            'usd_rmb_selling' => 'nullable|numeric|min:0.000001|max:999999.999999',
            'site_mode' => ['sometimes', 'required', 'in:testing,production'],
            'market_buy_auto' => ['nullable', 'in:on,off'],
            'demo_login_autofill' => ['nullable', 'in:on,off'],
            'demo_password' => 'nullable|string|min:4|max:60',
            'timezone' => ['nullable', Rule::in(array_keys(Setting::TIMEZONES))],
        ];
        // Older callers may omit a tab; tabbed forms can only save their own fields.
        if ($tab !== null) {
            $rules = array_intersect_key($rules, array_flip(self::TAB_FIELDS[$tab]));
        }
        $validated = $request->validate($rules, [
            'receipt_prefix.regex' => 'The receipt prefix can only contain letters and numbers, e.g. PS.',
            'default_charge_ugx.numeric' => 'The default charge must be a number in shillings, e.g. 2000.',
            'default_charge_ugx.min' => 'The default charge cannot be negative.',
            'site_mode.in' => 'Site mode must be Testing or Production.',
            'demo_login_autofill.in' => 'Demo button autofill must be On or Off.',
            'demo_password.min' => 'The demo password needs at least 4 characters.',
            'demo_password.max' => 'The demo password can be at most 60 characters.',
            'timezone.in' => 'Please choose one of the listed time zones.',
            'usd_ugx_buying.numeric' => 'The UGX buying rate must be a number, e.g. 3750.',
            'usd_ugx_selling.numeric' => 'The UGX selling rate must be a number, e.g. 3760.',
            'usd_ugx_buying.min' => 'The UGX buying rate must be at least 0.01.',
            'usd_ugx_selling.min' => 'The UGX selling rate must be at least 0.01.',
            'usd_ugx_selling.max' => 'The UGX rate is too high.',
            'usd_rmb_buying.numeric' => 'The RMB buying rate must be a number, e.g. 6.70.',
            'usd_rmb_selling.numeric' => 'The RMB selling rate must be a number, e.g. 6.75.',
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
            $validated['usd_rmb_selling'],
        );

        // Site mode decides whether the sign-in page prints the real admin emails,
        // so it stays a Super Admin decision even though Admins can edit other settings.
        if (! $request->user()->hasRole('superadmin')) {
            unset($validated['site_mode'], $validated['demo_login_autofill'], $validated['demo_password']);
        }
        // Reject before any writes, even if a forged request bypasses the disabled option.
        if (($validated['site_mode'] ?? null) === 'testing' && self::testingModeBlocked($request)) {
            throw ValidationException::withMessages([
                'site_mode' => 'Testing is unavailable on production. Use a local or non-production host.',
            ]);
        }

        $autoWasOn = Setting::get('market_buy_auto', 'off') === 'on';
        $changes = [];
        $oldValues = [];
        $newValues = [];

        foreach ($validated as $key => $value) {
            $type = in_array($key, array_merge(self::TRANSACTION_BOUND_KEYS, ['default_charge_ugx']), true) ? 'float' : 'string';
            $group = match (true) {
                in_array($key, ['site_mode', 'timezone', 'demo_login_autofill', 'demo_password'], true) => 'display',
                $key === 'market_buy_auto' => 'rates',
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

            if ($key === 'demo_password') {
                // The audit trail is readable by every role with log access, so it records
                // that the demo password moved without carrying the value itself.
                $changes[] = 'Demo password ' . ($previous === null ? 'set' : 'changed');
                $oldValues[$key] = '[hidden]';
                $newValues[$key] = '[hidden]';
                continue;
            }

            $changes[] = "{$key} changed from ".($previous ?? 'unset').' to '.($value ?? 'blank');
            $oldValues[$key] = $previous;
            $newValues[$key] = $value;
        }

        // A blank box leaves the accounts alone; anything typed becomes the sign-in for all five.
        if (($validated['demo_password'] ?? null) !== null && $validated['demo_password'] !== '') {
            User::whereIn('email', $this->demoEmails())->update([
                'password' => Hash::make($validated['demo_password']),
                'must_change_password' => false,
            ]);
        }

        // The old single min/max is retired once any per-currency bound is saved, so the
        // book never keeps two versions of the same rule.
        if (array_intersect(array_keys($newValues), self::TRANSACTION_BOUND_KEYS) !== []) {
            Setting::forget('min_transaction_amount');
            Setting::forget('max_transaction_amount');
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

        // Turning the switch on in this save takes effect at once, not at the next hourly
        // pull. A save while it is already on keeps the typed buying rate; only a pull or
        // the schedule moves it, so a hand correction is never silently overwritten.
        if (($validated['market_buy_auto'] ?? null) === 'on' && ! $autoWasOn) {
            $this->marketRates->applyLatestBuyingRate();
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
            ->with('settings_saved_tab', $tab ?? 'rates')
            ->with('settings_saved_text', $changes ? 'Settings saved.' : 'No changes to save.');
    }

    /**
     * Pull a reading now instead of waiting for the hourly run, record both pairs and
     * let the automation move the buying rate when it is on and safe to do so.
     */
    public function pullMarketRates()
    {
        try {
            $result = $this->marketRates->pull();
        } catch (RuntimeException $exception) {
            return back()->withErrors(['market_rate' => $exception->getMessage()]);
        }

        $first = reset($result['quotes']);
        $message = 'Market rates pulled from '.$first->source.'.';

        if ($result['note']) {
            $message .= ' '.$result['note'];
        }

        return back()
            ->with('success', $message)
            ->with('settings_saved_tab', 'rates')
            ->with('settings_saved_text', 'Rates refreshed.');
    }

    /**
     * Everything the Live market rate card prints: both readings, their daily move,
     * the points for the sparkline, the recorded days and the automation state.
     */
    private function marketBoard(): array
    {
        $pairs = [];
        $histories = [];

        foreach (['UGX', 'RMB'] as $quote) {
            $latest = RateSnapshot::latestFor('USD', $quote);
            $history = RateSnapshot::dailyCloses('USD', $quote, 30);
            $histories[$quote] = $history;
            $previous = $history->count() > 1 ? $history[$history->count() - 2] : null;
            $change = $latest && $previous ? (float) $latest->rate - (float) $previous->rate : null;
            $percent = $change !== null && (float) $previous->rate > 0
                ? ($change / (float) $previous->rate) * 100
                : null;
            $rising = $change !== null && $change > 0;
            $falling = $change !== null && $change < 0;

            $pairs[$quote] = [
                'label' => $quote === 'UGX' ? 'Dollar to shilling' : 'Dollar to yuan',
                'unit' => $quote.' per USD',
                'latest' => $latest,
                'display' => $latest ? $this->marketFigure((float) $latest->rate, $quote) : null,
                'previous' => $previous,
                'change' => $change,
                'percent' => $percent,
                'change_text' => $this->marketChangeText($change, $percent, $previous, $quote),
                'tone' => $rising ? 'text-green-700' : ($falling ? 'text-red-700' : 'text-gray-500'),
                'line' => $rising ? 'text-green-700' : ($falling ? 'text-red-700' : 'text-gray-400'),
                'points' => $history->count() > 1 ? $this->sparkPoints($history) : null,
                'chart_label' => $history->count() > 1 && $latest
                    ? 'USD to '.$quote.' from '.$this->marketFigure((float) $history->first()->rate, $quote)
                        .' on '.$history->first()->rate_date->format('d M').' to '
                        .$this->marketFigure((float) $latest->rate, $quote).' on '.$latest->rate_date->format('d M').'. '
                        .$this->marketChangeText($change, $percent, $previous, $quote)
                    : '',
            ];
        }

        $auto = $this->marketRates->buyingRateState();
        $lastPulled = RateSnapshot::lastPulledAt();

        return [
            'pairs' => $pairs,
            'auto' => [
                'on' => $auto['state'] !== 'off',
                'state' => $auto['state'],
                'text' => $auto['text'],
                'tone' => in_array($auto['state'], ['above', 'stale'], true)
                    ? 'text-red-700 font-medium'
                    : ($auto['state'] === 'applied' ? 'text-green-700 font-medium' : 'text-gray-500'),
            ],
            'history' => $this->marketHistory($histories),
            'last_pulled' => $lastPulled,
            'stale_pull' => $lastPulled !== null && $lastPulled->lt(now()->subHours(26)),
        ];
    }

    private function marketChangeText(?float $change, ?float $percent, ?RateSnapshot $previous, string $quote): string
    {
        if ($previous === null) {
            return 'First reading recorded. The day to day comparison starts with the next reading.';
        }

        if ($change === null || abs($change) < 0.0000005) {
            return 'Unchanged since '.$previous->rate_date->format('d M').'.';
        }

        return ($change > 0 ? 'Increased' : 'Decreased').' by '
            .$this->marketFigure(abs($change), $quote).' ('.number_format(abs((float) $percent), 2).'%) since '
            .$previous->rate_date->format('d M').'.';
    }

    /**
     * Points for a 120 by 36 sparkline. A flat market sits on the middle line instead
     * of dividing by a zero range.
     */
    private function sparkPoints($history): string
    {
        $values = $history->map(fn ($reading) => (float) $reading->rate)->values();
        $count = $values->count();
        $minimum = (float) $values->min();
        $range = (float) $values->max() - $minimum;

        return $values->map(function (float $value, int $index) use ($count, $minimum, $range): string {
            $x = $count === 1 ? 60 : ($index / ($count - 1)) * 120;
            $y = $range == 0.0 ? 18 : 30 - (($value - $minimum) / $range) * 24;

            return round($x, 2).','.round($y, 2);
        })->implode(' ');
    }

    /**
     * The last recorded reading per market day, with both pairs on one line.
     */
    private function marketHistory(array $histories): array
    {
        $days = [];

        foreach ($histories as $quote => $readings) {
            foreach ($readings as $reading) {
                $key = $reading->rate_date->toDateString();
                $days[$key] ??= [
                    'date' => $reading->rate_date,
                    'UGX' => '-',
                    'RMB' => '-',
                    'pulled' => $reading->fetched_at,
                ];
                $days[$key][$quote] = $this->marketFigure((float) $reading->rate, $quote);

                if ($reading->fetched_at->gt($days[$key]['pulled'])) {
                    $days[$key]['pulled'] = $reading->fetched_at;
                }
            }
        }

        krsort($days);

        $list = array_values(array_slice($days, 0, 14));

        // Newest first, so the row after each day is its previous market day.
        foreach ($list as $index => $day) {
            $list[$index]['move'] = $this->marketMove($day, $list[$index + 1] ?? null);
        }

        return $list;
    }

    /**
     * The change from the market day before, or null where either side has no
     * reading. 'flat' means the price did not move.
     *
     * @param  array{UGX: string, RMB: string}  $day
     * @param  array{UGX: string, RMB: string}|null  $previous
     * @return array{UGX: ?string, RMB: ?string}
     */
    private function marketMove(array $day, ?array $previous): array
    {
        $move = ['UGX' => null, 'RMB' => null];

        if ($previous === null) {
            return $move;
        }

        foreach (['UGX', 'RMB'] as $quote) {
            if ($day[$quote] === '-' || $previous[$quote] === '-') {
                continue;
            }

            $change = (float) str_replace(',', '', $day[$quote]) - (float) str_replace(',', '', $previous[$quote]);

            if ($change == 0.0) {
                $move[$quote] = 'flat';

                continue;
            }

            $move[$quote] = ($change > 0 ? '+' : '-')
                .($quote === 'UGX'
                    ? number_format(abs($change), 2)
                    : rtrim(rtrim(number_format(abs($change), 4, '.', ''), '0'), '.'));
        }

        return $move;
    }

    private function marketFigure(float $value, string $quote): string
    {
        return $quote === 'UGX'
            ? number_format($value, 2)
            : rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
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
