<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ProfitCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The rates on this sheet are the live ones as of 2026-10-14: shillings bought at 3,600 and
 * sold at 4,128, yuan bought from the China side at 6.56 and priced out to recipients at
 * 6.615. Note that the yuan pair is reversed, so every dollar sent loses a little yuan.
 */
class ProfitCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ExchangeRate::setPair('USD', 'UGX', 4128, 3600, 4128);
        ExchangeRate::setPair('USD', 'RMB', 6.615, 6.56, 6.615);

        Setting::set('service_fee_ugx', '2000', 'float', 'transaction');
        Setting::set('service_fee_usd', '100', 'float', 'transaction');
    }

    private function quote(string $currency, string $usd): array
    {
        $quote = ProfitCalculator::quote($currency, $usd);
        $this->assertNotNull($quote, 'The calculator refused to price a fully rated booking.');

        return $quote;
    }

    public function test_it_reproduces_the_worked_example_for_a_shilling_payer(): void
    {
        // 5,000 UGX at the 4,128 selling price is 1.21 dollars.
        $quote = $this->quote('UGX', bcdiv('5000', '4128', 2));

        // Fee: 1.21 dollars times twenty shillings per dollar.
        $this->assertSame('24.20', $quote['charge_ugx']);
        // Shilling leg: 1.21 dollars times the 528 shilling gap.
        $this->assertSame('638.88', $quote['fx_profit_ugx']);
        // Yuan leg: 1.21 dollars times the 0.055 yuan we pay out above what we settle at.
        $this->assertSame('-0.066550', $quote['usd_rmb_profit_cny']);
        // That yuan loss, worth 3,600 shillings to us per 6.56 yuan.
        $this->assertEqualsWithDelta(-36.52, (float) $quote['usd_rmb_profit_ugx'], 0.01);
        // 24.20 + 638.88 - 36.52, which is the "about 626" the business expects.
        $this->assertEqualsWithDelta(626.56, (float) $quote['total_profit_ugx'], 0.01);
    }

    public function test_the_three_parts_always_add_up_to_the_total(): void
    {
        foreach (['1.21', '242.28', '1211.24', '48449.13'] as $usd) {
            $quote = $this->quote('UGX', $usd);

            $this->assertEqualsWithDelta(
                (float) $quote['charge_ugx'] + (float) $quote['fx_profit_ugx'] + (float) $quote['usd_rmb_profit_ugx'],
                (float) $quote['total_profit_ugx'],
                0.000001,
                "Parts do not add up at {$usd} dollars."
            );
        }
    }

    public function test_a_dollar_payer_earns_no_shilling_margin(): void
    {
        $quote = $this->quote('USD', '100');

        // Nobody handed us shillings to convert, so the only gain is the fee, and the yuan
        // leg still costs us. A hundred dollars at these rates is a loss overall.
        $this->assertSame('2000.00', $quote['charge_ugx']);
        $this->assertSame('0.00', $quote['fx_profit_ugx']);
        $this->assertSame('0.00', $quote['fx_profit_usd']);
        $this->assertSame('-5.500000', $quote['usd_rmb_profit_cny']);
        $this->assertLessThan(0, (float) $quote['total_profit_ugx']);
    }

    public function test_a_reversed_yuan_pair_is_recorded_as_a_loss_not_a_positive(): void
    {
        $quote = $this->quote('UGX', '1211.24');

        $this->assertLessThan(0, (float) $quote['usd_rmb_profit_cny']);
        $this->assertLessThan(0, (float) $quote['usd_rmb_profit_ugx']);
    }

    public function test_a_fair_yuan_pair_turns_the_same_leg_into_a_gain(): void
    {
        ExchangeRate::setPair('USD', 'RMB', 6.5, 6.6, 6.5);

        $quote = $this->quote('UGX', '100');

        $this->assertGreaterThan(0, (float) $quote['usd_rmb_profit_cny']);
    }

    public function test_a_transfer_with_no_yuan_rate_at_all_has_no_total_rather_than_a_zero(): void
    {
        ExchangeRate::retirePair('USD', 'RMB');

        $quote = ProfitCalculator::quote('UGX', '100');

        $this->assertIsArray($quote);
        $this->assertNull($quote['usd_rmb_profit_cny']);
        $this->assertNull($quote['usd_rmb_profit_ugx']);
        // Zero here would read as "broke even" on a transfer nobody has priced.
        $this->assertNull($quote['total_profit_ugx']);
        // The fee is still real money and is still recorded.
        $this->assertSame('2000.00', $quote['charge_ugx']);
    }

    public function test_it_refuses_to_price_without_a_ugx_rate(): void
    {
        ExchangeRate::retirePair('USD', 'UGX');

        $this->assertNull(ProfitCalculator::quote('UGX', '100'));
    }

    public function test_a_booking_saves_every_profit_figure_on_the_row(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/transactions/create');
        $this->actingAs($admin)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Asuma Aggrey',
            'receiver_name' => 'Wei Ming',
            'currency' => 'UGX',
            'amount' => '5000',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(1.21, (float) $transaction->total_usd_equivalent, 0.001);
        $this->assertSame('24.20', (string) $transaction->charge_ugx);
        $this->assertSame('638.88', (string) $transaction->fx_profit_ugx);
        $this->assertSame('6.560000', (string) $transaction->rmb_buying_rate);
        $this->assertSame('-0.066550', (string) $transaction->usd_rmb_profit_cny);
        $this->assertEqualsWithDelta(626.56, (float) $transaction->total_profit_ugx, 0.01);
        // The yuan the recipient is credited at has not moved.
        $this->assertSame('8.00', (string) $transaction->rmb_amount);
    }

    private function makeAdmin(): User
    {
        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $user = User::create([
            'name' => 'Gorret Admin',
            'email' => 'gorret'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }
}
