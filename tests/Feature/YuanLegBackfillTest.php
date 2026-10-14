<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class YuanLegBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function booking(string $when): Transaction
    {
        // A row written before the yuan leg was stored: it carries the selling rate and the
        // dollar figure but no buying rate and no leg, which is exactly what the command reads.
        $transaction = new Transaction([
            'sender_name' => 'Old Sender',
            'sender_contact' => '+256 700 000 000',
            'receiver_name' => 'Old Receiver',
            'receiver_contact' => '+86 138 0000 0000',
            'currency' => 'UGX',
            'amount' => '3760000',
            'exchange_rate' => '7.30',
            'total_usd_equivalent' => '1000.00',
            'rmb_amount' => '7300.00',
            'charge_ugx' => '20000.00',
            'fx_profit_ugx' => '10000.00',
            'status' => 'completed',
            'created_by' => User::firstOrCreate(
                ['email' => 'booker@plussave.test'],
                ['name' => 'Booker', 'password' => 'Password123', 'is_active' => true]
            )->id,
        ]);
        $transaction->receipt_number = Transaction::generateReceiptNumber();
        $transaction->created_at = $when;
        $transaction->updated_at = $when;
        $transaction->save();

        return $transaction;
    }

    public function test_prices_a_booking_whenever_a_buying_rate_was_live_then(): void
    {
        $rate = ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'RMB',
            'rate' => 7.30, 'buy_rate' => 7.00, 'sell_rate' => 7.30, 'is_active' => true,
        ]);
        $ugx = ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'UGX',
            'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true,
        ]);

        $rate->created_at = '2026-10-01 09:00:00';
        $rate->save();
        $ugx->created_at = '2026-10-01 09:00:00';
        $ugx->save();

        $transaction = $this->booking('2026-10-05 12:00:00');

        $this->artisan('profit:backfill-yuan-leg --apply')->assertSuccessful();

        $transaction->refresh();

        // (7.00 bought - 7.30 credited) x 1,000 USD = -300 CNY, back to shillings at 3750/7.
        // The command uses the same bcdiv-then-bcmul as ProfitCalculator, so it must land on
        // exactly the figure a live booking would have stored.
        $this->assertSame('7.000000', (string) $transaction->rmb_buying_rate);
        $this->assertSame('-300.000000', (string) $transaction->usd_rmb_profit_cny);
        $this->assertSame('-160714.285687', (string) $transaction->usd_rmb_profit_ugx);
        $this->assertSame('-130714.285687', (string) $transaction->total_profit_ugx);
    }

    public function test_leaves_a_booking_unpriced_when_no_rate_existed_yet(): void
    {
        // The only yuan rate was added on the 10th, so a booking on the 5th cannot be proven.
        $rate = ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'RMB',
            'rate' => 7.30, 'buy_rate' => 7.00, 'sell_rate' => 7.30, 'is_active' => true,
        ]);
        $rate->created_at = '2026-10-10 09:00:00';
        $rate->save();

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true])
            ->forceFill(['created_at' => '2026-10-01 09:00:00'])->save();

        $transaction = $this->booking('2026-10-05 12:00:00');

        $this->artisan('profit:backfill-yuan-leg --apply')->assertSuccessful();

        $transaction->refresh();
        $this->assertNull($transaction->usd_rmb_profit_cny);
        $this->assertNull($transaction->rmb_buying_rate);
    }

    public function test_leaves_a_booking_unpriced_when_the_live_rate_has_no_buying_price(): void
    {
        // A pre-split October row only holds one figure; treating it as the buying price would
        // be guessing at the margin, so the leg stays flagged instead.
        $rate = ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 7.24, 'is_active' => true,
        ]);
        $rate->created_at = '2026-10-01 09:00:00';
        $rate->save();

        $transaction = $this->booking('2026-10-05 12:00:00');

        $this->artisan('profit:backfill-yuan-leg --apply')->assertSuccessful();

        $transaction->refresh();
        $this->assertNull($transaction->usd_rmb_profit_cny);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $rate = ExchangeRate::create([
            'from_currency' => 'USD', 'to_currency' => 'RMB',
            'rate' => 7.30, 'buy_rate' => 7.00, 'sell_rate' => 7.30, 'is_active' => true,
        ]);
        $rate->created_at = '2026-10-01 09:00:00';
        $rate->save();

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true])
            ->forceFill(['created_at' => '2026-10-01 09:00:00'])->save();

        $transaction = $this->booking('2026-10-05 12:00:00');

        $this->artisan('profit:backfill-yuan-leg')->assertSuccessful();

        $transaction->refresh();
        $this->assertNull($transaction->usd_rmb_profit_cny);
    }
}
