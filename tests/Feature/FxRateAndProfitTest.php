<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FxRateAndProfitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'RMB', 'rate' => 7.24, 'is_active' => true]);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3760, 'buy_rate' => 3750, 'sell_rate' => 3760, 'is_active' => true]);

        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Rate Admin',
            'email' => 'rates'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('admin');

        return $user;
    }

    public function test_settings_save_keeps_the_selling_rate_as_the_conversion_rate(): void
    {
        $this->actingAs($this->admin())
            ->put('/settings', [
                'usd_ugx_buying' => '3748',
                'usd_ugx_selling' => '3772',
                'usd_rmb_buying' => '6.70',
                'usd_rmb_selling' => '6.75',
            ])
            ->assertSessionHasNoErrors();

        $row = ExchangeRate::getActiveRate('USD', 'UGX');

        // Everything else in the app converts with `rate`, so it must stay the customer-facing price.
        $this->assertSame('3772.000000', (string) $row->rate);
        $this->assertSame('3748.000000', (string) $row->buy_rate);
        $this->assertSame('3772.000000', (string) $row->sell_rate);
        $this->assertSame(['buy' => 3748.0, 'sell' => 3772.0], ExchangeRate::usdUgxPair());

        $rmb = ExchangeRate::getActiveRate('USD', 'RMB');
        $this->assertSame('6.750000', (string) $rmb->rate);
        $this->assertSame('6.700000', (string) $rmb->buy_rate);
    }

    public function test_transaction_records_the_profit_made_on_each_dollar(): void
    {
        // UGX 3,760,000 at the selling rate of 3760 is exactly $1,000, and those dollars cost 3750 each.
        $transaction = $this->storeUgxTransfer(3760000);

        $this->assertSame('3750.000000', (string) $transaction->rate_buy_ugx);
        $this->assertSame('3760.000000', (string) $transaction->rate_sell_ugx);
        $this->assertSame('10000.00', (string) $transaction->fx_profit_ugx);
        $this->assertSame('2.65', (string) $transaction->fx_profit_usd);

        // The service fee stays separate income: UGX 2,000 per 100 USD on $1,000 is UGX 20,000.
        $this->assertSame('20000.00', (string) $transaction->charge_ugx);
        $this->assertSame('5.31', (string) $transaction->charge_amount);
    }

    public function test_profit_is_zero_when_only_one_rate_is_known(): void
    {
        ExchangeRate::setPair('USD', 'UGX', 3700);

        $transaction = $this->storeUgxTransfer(3700000);

        $this->assertSame('3700.000000', (string) $transaction->rate_buy_ugx);
        $this->assertSame('3700.000000', (string) $transaction->rate_sell_ugx);
        $this->assertSame('0.00', (string) $transaction->fx_profit_ugx);
        $this->assertSame('0.00', (string) $transaction->fx_profit_usd);
    }

    public function test_profit_report_adds_the_fee_and_the_dollar_margin_in_both_currencies(): void
    {
        $transaction = $this->storeUgxTransfer(3760000);
        $admin = $transaction->creator;

        // UGX 20,000 of service fee plus UGX 10,000 of margin on the 1,000 dollars bought.
        $this->actingAs($admin)
            ->get('/reports?type=profit')
            ->assertOk()
            ->assertSee('Profit on One Dollar Sent')
            ->assertSee('UGX 30,000')
            ->assertSee('UGX 30.00');
    }

    public function test_the_profit_report_also_states_the_profit_in_rmb(): void
    {
        $transaction = $this->storeUgxTransfer(3760000);

        // Those 1,000 dollars were delivered as 7,240 RMB, so each dollar of profit is worth
        // 7.24 RMB: the UGX 20,000 fee is $5.31 = 38.44, the margin $2.65 = 19.19.
        $this->actingAs($transaction->creator)
            ->get('/reports?type=profit')
            ->assertOk()
            ->assertSee('&yen;38.44 RMB', false)
            ->assertSee('&yen;19.19 RMB', false)
            ->assertSee('&yen;57.63 RMB', false);
    }

    public function test_agents_never_see_the_profit_report(): void
    {
        $this->storeUgxTransfer(3760000);

        $agent = User::create([
            'name' => 'Field Agent',
            'email' => 'agent'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $agent->assignRole('agent');

        $this->actingAs($agent)
            ->get('/reports?type=profit')
            ->assertOk()
            ->assertDontSee('Margin on Dollars');

        $this->actingAs($agent)->get('/export/profit-report')->assertForbidden();
    }

    public function test_profit_report_exports_as_excel(): void
    {
        $transaction = $this->storeUgxTransfer(3760000);

        $this->actingAs($transaction->creator)
            ->get('/export/profit-report')
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $transaction->created_by,
            'action' => 'exported',
        ]);
    }

    private function storeUgxTransfer(float $amount): Transaction
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/transactions/create');

        $this->actingAs($admin)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Test Sender',
            'receiver_name' => 'Test Receiver',
            'currency' => 'UGX',
            'amount' => (string) $amount,
        ])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }
}
