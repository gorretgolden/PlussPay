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
 * Pricing agreed with the office on 2026-10-14. The operator enters the cash handed over and the
 * charge taken out of it, the dollars the customer is credited with are rounded to one decimal
 * place, and the profit is the cash in less what the delivered yuan actually cost us.
 *
 * The rates in setUp are the live ones as of that date: shillings bought at 3,600 and sold at
 * 4,128, yuan settled with China at 6.56 and credited to recipients at 6.615. The yuan pair is
 * reversed, so every dollar sent loses a little yuan.
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

    /** The rates written on the office sheet: dollars 4,000 in and 4,300 out, yuan 6.61 and 6.56. */
    private function useSheetRates(): void
    {
        ExchangeRate::setPair('USD', 'UGX', 4300, 4000, 4300);
        ExchangeRate::setPair('USD', 'RMB', 6.56, 6.61, 6.56);
    }

    private function quote(string $currency, string $cashReceived, string $chargeUgx): array
    {
        $quote = ProfitCalculator::quote($currency, $cashReceived, $chargeUgx);
        $this->assertNotNull($quote, 'The calculator refused to price a fully rated booking.');

        return $quote;
    }

    public function test_it_reproduces_the_sheet_for_a_shilling_payer(): void
    {
        $this->useSheetRates();

        // 500,000 handed over, 20,000 charge, so 480,000 travels.
        $quote = $this->quote('UGX', '500000', '20000');

        $this->assertSame('480000.00', $quote['amount']);
        $this->assertSame('20000.00', $quote['charge_ugx']);
        // 480,000 at the 4,300 selling price is 111.63 dollars, credited as 111.6.
        $this->assertSame('111.6', $quote['total_usd_equivalent']);
        // 111.6 dollars at the 6.56 yuan selling price is what the receiver is sent.
        $this->assertSame('732.10', $quote['rmb_amount']);
        // Those yuan cost 732.10 / 6.61 = 110.756 dollars, at 4,000 shillings each, which is
        // 3,374.28 less than the 446,400 those same dollars would have cost us.
        $this->assertEqualsWithDelta(3374.28, (float) $quote['usd_rmb_profit_ugx'], 0.01);
        // The shilling leg is the 480,000 collected less 111.6 dollars at what they cost us.
        $this->assertSame('33600.00', $quote['fx_profit_ugx']);
        $this->assertEqualsWithDelta(3374.28, (float) $quote['usd_rmb_profit_ugx'], 0.01);
        // 33,600 + 3,374.28 + 20,000 charge.
        $this->assertEqualsWithDelta(56974.28, (float) $quote['total_profit_ugx'], 0.01);
    }

    public function test_it_reproduces_the_sheet_for_a_dollar_payer(): void
    {
        $this->useSheetRates();

        // Dollars are forwarded whole, so the charge is cash collected on top, not a deduction.
        $quote = $this->quote('USD', '112', '2000');

        $this->assertSame('112.00', $quote['amount']);
        $this->assertSame('112.0', $quote['total_usd_equivalent']);
        $this->assertSame('734.72', $quote['rmb_amount']);
        // No shillings went through the till, so there is no shilling leg to price.
        $this->assertSame('0.00', $quote['fx_profit_ugx']);
        // 112 dollars in, 734.72 yuan out at 6.61 is 111.153 dollars of cost, at 4,000 each.
        $this->assertEqualsWithDelta(3388.81, (float) $quote['usd_rmb_profit_ugx'], 0.02);
        $this->assertEqualsWithDelta(5388.81, (float) $quote['total_profit_ugx'], 0.02);
    }

    public function test_the_two_legs_and_the_charge_always_add_up_to_the_total(): void
    {
        $this->useSheetRates();

        foreach ([['500000', '20000'], ['500000', '2000'], ['1000000', '0'], ['482000', '2000']] as [$cash, $charge]) {
            $quote = $this->quote('UGX', $cash, $charge);

            $this->assertEqualsWithDelta(
                (float) $quote['fx_profit_ugx'] + (float) $quote['usd_rmb_profit_ugx'] + (float) $quote['charge_ugx'],
                (float) $quote['total_profit_ugx'],
                0.001,
                "The parts do not add up on {$cash} at a {$charge} charge."
            );
        }
    }

    public function test_the_yuan_payout_is_priced_from_the_rounded_dollars(): void
    {
        $this->useSheetRates();

        $quote = $this->quote('UGX', '500000', '20000');

        // 111.63 unrounded would have paid 732.28 yuan. The customer is credited 111.6, so the
        // receiver gets 732.10 and the difference is profit we gave away, not a missing figure.
        $this->assertSame('732.10', $quote['rmb_amount']);
        $this->assertEqualsWithDelta(732.28, (float) bcmul(bcdiv('480000', '4300', 6), '6.56', 4), 0.01);
    }

    public function test_a_reversed_yuan_pair_is_recorded_as_a_loss_not_a_positive(): void
    {
        $quote = $this->quote('UGX', '5000000', '20000');

        $this->assertLessThan(0, (float) $quote['usd_rmb_profit_cny']);
        $this->assertLessThan(0, (float) $quote['usd_rmb_profit_ugx']);
    }

    public function test_a_fair_yuan_pair_turns_the_same_leg_into_a_gain(): void
    {
        $this->useSheetRates();

        $quote = $this->quote('UGX', '5000000', '20000');

        $this->assertGreaterThan(0, (float) $quote['usd_rmb_profit_cny']);
    }

    public function test_a_charge_that_eats_the_cash_is_refused(): void
    {
        $this->assertNull(ProfitCalculator::quote('UGX', '2000', '2000'));
        $this->assertNull(ProfitCalculator::quote('UGX', '1000', '2000'));
    }

    public function test_it_refuses_to_price_without_a_rate(): void
    {
        ExchangeRate::retirePair('USD', 'UGX');
        $this->assertNull(ProfitCalculator::quote('UGX', '500000', '2000'));

        ExchangeRate::setPair('USD', 'UGX', 4128, 3600, 4128);
        ExchangeRate::retirePair('USD', 'RMB');
        $this->assertNull(ProfitCalculator::quote('UGX', '500000', '2000'));
    }

    public function test_the_settings_rule_only_suggests_a_charge_the_operator_can_override(): void
    {
        $this->useSheetRates();

        // 111.6 dollars at 2,000 shillings per 100 dollars.
        $this->assertSame('2232.00', ProfitCalculator::suggestedCharge('UGX', '480000'));
        $this->assertSame('2240.00', ProfitCalculator::suggestedCharge('USD', '112'));

        // The entered charge is what is booked, whatever the rule would have said.
        $quote = $this->quote('UGX', '500000', '20000');
        $this->assertSame('20000.00', $quote['charge_ugx']);
    }

    public function test_a_booking_saves_every_profit_figure_on_the_row(): void
    {
        $this->useSheetRates();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/transactions/create');
        $this->actingAs($admin)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'sender_name' => 'Asuma Aggrey',
            'receiver_name' => 'Wei Ming',
            'currency' => 'UGX',
            'amount' => '500000',
            'charge_ugx' => '20000',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::latest('id')->firstOrFail();

        $this->assertSame('480000.00', (string) $transaction->amount);
        $this->assertSame('111.60', (string) $transaction->total_usd_equivalent);
        $this->assertSame('732.10', (string) $transaction->rmb_amount);
        $this->assertSame('20000.00', (string) $transaction->charge_ugx);
        $this->assertSame('33600.00', (string) $transaction->fx_profit_ugx);
        $this->assertSame('4300.000000', (string) $transaction->rate_sell_ugx);
        $this->assertSame('4000.000000', (string) $transaction->rate_buy_ugx);
        $this->assertSame('6.610000', (string) $transaction->rmb_buying_rate);
        $this->assertEqualsWithDelta(56974.28, (float) $transaction->total_profit_ugx, 0.01);
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
