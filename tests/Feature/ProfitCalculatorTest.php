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
 * Pricing agreed with the office on 2026-10-14 and re-cut on 2026-10-16. The operator enters the
 * cash handed over and the charge taken out of it, the dollars are divided out at full precision,
 * the yuan payout is priced from those unrounded dollars, and each figure is rounded once, to two
 * decimals, when it is stored. The profit is the cash in less what the delivered yuan cost us.
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
        // 480,000 at the 4,300 selling price is 111.6279 dollars, stored as 111.63.
        $this->assertSame('111.63', $quote['total_usd_equivalent']);
        // The payout is priced from the unrounded dollars: 111.6279 at the 6.56 yuan selling price.
        $this->assertSame('732.28', $quote['rmb_amount']);
        // Those yuan cost 732.28 / 6.61 = 110.784 dollars, at 4,000 shillings each, which is
        // 3,385.36 less than the 446,520 the stored credit would have cost us.
        $this->assertEqualsWithDelta(3385.36, (float) $quote['usd_rmb_profit_ugx'], 0.01);
        // The shilling leg is the 480,000 collected less 111.63 dollars at what they cost us.
        $this->assertSame('33480.00', $quote['fx_profit_ugx']);
        // 33,480 + 3,385.36 + 20,000 charge.
        $this->assertEqualsWithDelta(56865.36, (float) $quote['total_profit_ugx'], 0.01);
    }

    public function test_it_reproduces_the_sheet_for_a_dollar_payer(): void
    {
        $this->useSheetRates();

        // Dollars are forwarded whole, so the charge is cash collected on top, not a deduction.
        $quote = $this->quote('USD', '112', '2000');

        $this->assertSame('112.00', $quote['amount']);
        $this->assertSame('112.00', $quote['total_usd_equivalent']);
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

    public function test_the_yuan_payout_is_priced_from_the_unrounded_dollars(): void
    {
        $this->useSheetRates();

        $quote = $this->quote('UGX', '500000', '20000');

        // 480,000 / 4,300 is 111.6279069767 dollars. Pricing the payout off that full figure pays
        // 732.28 yuan; pricing it off the stored 111.63 credit would pay 732.29, and the old habit
        // of crediting 111.6 paid only 732.10. The receiver now gets the fuller figure.
        $this->assertSame('732.28', $quote['rmb_amount']);
        $this->assertEqualsWithDelta(732.2790697, (float) bcmul(bcdiv('480000', '4300', 10), '6.56', 7), 0.0001);
        $this->assertSame('732.29', number_format((float) bcmul('111.63', '6.56', 8), 2, '.', ''));
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

    public function test_the_charge_typed_is_the_charge_booked(): void
    {
        $this->useSheetRates();

        // Nothing suggests a figure from a rate any more. What the operator enters is what is
        // booked, and the rest of the money handed over is what travels.
        $quote = $this->quote('UGX', '500000', '20000');
        $this->assertSame('20000.00', $quote['charge_ugx']);
        $this->assertSame('480000.00', $quote['amount']);
    }

    public function test_a_booking_saves_every_profit_figure_on_the_row(): void
    {
        $this->useSheetRates();
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/transactions/create');
        $this->actingAs($admin)->post('/transactions', [
            'form_id' => session('transaction_form_id'),
            'agent_id' => 'counter',
            'sender_name' => 'Asuma Aggrey',
            'receiver_name' => 'Wei Ming',
            'currency' => 'UGX',
            'amount' => '500000',
            'charge_ugx' => '20000',
            'payment_method' => 'Cash',
        ])->assertSessionHasNoErrors();

        $transaction = Transaction::latest('id')->firstOrFail();

        $this->assertSame('480000.00', (string) $transaction->amount);
        $this->assertSame('111.63', (string) $transaction->total_usd_equivalent);
        $this->assertSame('732.28', (string) $transaction->rmb_amount);
        $this->assertSame('20000.00', (string) $transaction->charge_ugx);
        $this->assertSame('33480.00', (string) $transaction->fx_profit_ugx);
        $this->assertSame('4300.000000', (string) $transaction->rate_sell_ugx);
        $this->assertSame('4000.000000', (string) $transaction->rate_buy_ugx);
        $this->assertSame('6.610000', (string) $transaction->rmb_buying_rate);
        $this->assertEqualsWithDelta(56865.36, (float) $transaction->total_profit_ugx, 0.01);
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
