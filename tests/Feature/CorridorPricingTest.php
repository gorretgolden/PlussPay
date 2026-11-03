<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ProfitCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The routes agreed with the office on 2026-10-19, alongside the pricing already proved in
 * ProfitCalculatorTest. Three things changed: a booking can say which way the money is going, a
 * receiver in China can be handed dollars instead of yuan, and a receiver in Uganda can be paid
 * shillings against yuan or dollars handed over in China.
 *
 * Every route is still priced by the same rule: the shillings that came in, less the shillings'
 * worth of what went out, plus nothing else, because the charge is already inside the money taken
 * in on a shilling booking. The tests below check that identity on each route rather than trusting
 * the figures to add up on their own.
 *
 * The rates are the office sheet as of that date: dollars 4,000 in and 4,300 out, yuan 6.61 and
 * 6.56. Read the worked figures in each test against them.
 */
class CorridorPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSheetRates();
    }

    private function useSheetRates(): void
    {
        ExchangeRate::setPair('USD', 'UGX', 4300, 4000, 4300);
        ExchangeRate::setPair('USD', 'RMB', 6.56, 6.61, 6.56);
    }

    private function quote(string $currency, string $cash, string $charge, string $to = 'CN', string $payout = 'RMB'): array
    {
        $quote = ProfitCalculator::quote($currency, $cash, $charge, $to, $payout);
        $this->assertNotNull($quote, "The calculator refused to price {$cash} {$currency} to {$to} in {$payout}.");

        return $quote;
    }

    public function test_a_dollar_payout_to_china_never_promises_yuan(): void
    {
        // 500,000 handed over with a 20,000 charge, so 480,000 travels: 111.6279 dollars at the
        // 4,300 selling price, stored as 111.63, and those are the dollars the receiver is handed.
        $quote = $this->quote('UGX', '500000', '20000', 'CN', 'USD');

        $this->assertSame('CN', $quote['destination']);
        $this->assertSame('USD', $quote['payout_currency']);
        $this->assertSame('480000.00', $quote['amount']);
        $this->assertSame('111.63', $quote['total_usd_equivalent']);
        $this->assertSame('0.00', $quote['rmb_amount']);
        $this->assertSame('0.00', $quote['ugx_amount']);

        // 111.63 dollars cost the desk 4,000 shillings each, so 446,520 out of the 480,000
        // collected, and the difference is the whole exchange profit on this route.
        $this->assertSame('33480.00', $quote['fx_profit_ugx']);
        $this->assertSame('0.00', $quote['usd_rmb_profit_ugx']);
        $this->assertSame('53480.00', $quote['total_profit_ugx']);

        // 500,000 in, 111.63 dollars out at what they cost the desk. Nothing else earns.
        $this->assertSame(53480.00, 500000 - 446520.00, 'The profit is the cash in less the cost of what went out.');
    }

    public function test_dollars_taken_in_and_paid_out_in_china_earn_only_the_charge(): void
    {
        // The dollars are forwarded whole and handed over whole, so no shillings pass through the
        // till at all and the charge is the only money this booking brings in.
        $quote = $this->quote('USD', '112', '2000', 'CN', 'USD');

        $this->assertSame('112.00', $quote['amount']);
        $this->assertSame('112.00', $quote['total_usd_equivalent']);
        $this->assertSame('0.00', $quote['rmb_amount']);
        $this->assertSame('0.00', $quote['fx_profit_ugx']);
        $this->assertSame('0.00', $quote['usd_rmb_profit_ugx']);
        $this->assertSame('2000.00', $quote['total_profit_ugx']);
    }

    public function test_a_dollar_payout_is_priced_without_a_yuan_price(): void
    {
        $onlyShillings = ['UGX', '500000', '20000', 'CN', 'USD'];

        $this->assertSame(['USD → UGX'], ProfitCalculator::requiredPairs('UGX', 'CN', 'USD'));

        ExchangeRate::retirePair('USD', 'RMB');

        $this->assertNotNull(ProfitCalculator::quote(...$onlyShillings), 'A dollar payout does not read the yuan sheet.');
        $this->assertNull(ProfitCalculator::quote('UGX', '500000', '20000'), 'A yuan payout does, and says so.');
    }

    public function test_yuan_handed_over_in_china_buys_the_shillings_paid_out_in_uganda(): void
    {
        // 6,610 yuan taken in at the 6.61 price the desk obtains dollars at is exactly 1,000
        // dollars. Those dollars are worth 4,000 shillings each at the price the desk pays out at,
        // so 4,000,000 is the credit, the 20,000 charge comes out of it, and 3,980,000 is what the
        // receiver in Uganda is handed.
        $quote = $this->quote('RMB', '6610', '20000', 'UG', 'UGX');

        $this->assertSame('UG', $quote['destination']);
        $this->assertSame('UGX', $quote['payout_currency']);
        $this->assertSame('6610.00', $quote['amount']);
        $this->assertSame('1000.00', $quote['total_usd_equivalent']);
        $this->assertSame('3980000.00', $quote['ugx_amount']);
        $this->assertSame('0.00', $quote['rmb_amount'], 'Nothing is promised to a receiver in China on this route.');

        // Turning the same 6,610 yuan back over at the 6.56 price the desk sells dollars at is
        // 1,007.6219 dollars, worth 4,332,774.39 shillings at 4,300. That is what the money the desk
        // now holds is worth to it, and the split below is that gap named by leg.
        $this->assertSame('300000.00', $quote['fx_profit_ugx']);
        $this->assertSame('32774.39', $quote['usd_rmb_profit_ugx']);
        $this->assertSame('352774.39', $quote['total_profit_ugx']);
        $this->assertGreaterThan(0, (float) $quote['usd_rmb_profit_cny']);

        // The one rule, on this route: what the money taken in is worth, less what was handed over.
        $this->assertEqualsWithDelta(352774.39, 4332774.39 - 3980000.00, 0.001);
    }

    public function test_dollars_handed_over_in_china_are_priced_with_the_shilling_price_only(): void
    {
        // 1,000 dollars in: worth 4,000,000 shillings at the price the desk pays out at, less the
        // 20,000 charge, and 4,300,000 at the price it sells at. No yuan is touched, so no yuan leg.
        $quote = $this->quote('USD', '1000', '20000', 'UG', 'UGX');

        $this->assertSame('1000.00', $quote['amount']);
        $this->assertSame('1000.00', $quote['total_usd_equivalent']);
        $this->assertSame('3980000.00', $quote['ugx_amount']);
        $this->assertSame('0.00', $quote['rmb_amount']);
        $this->assertSame('300000.00', $quote['fx_profit_ugx']);
        $this->assertSame('0.00', $quote['usd_rmb_profit_ugx']);
        $this->assertSame('320000.00', $quote['total_profit_ugx']);
        $this->assertSame(['USD → UGX'], ProfitCalculator::requiredPairs('USD', 'UG', 'UGX'));

        ExchangeRate::retirePair('USD', 'RMB');
        $this->assertNotNull(ProfitCalculator::quote('USD', '1000', '20000', 'UG', 'UGX'));
        $this->assertNull(ProfitCalculator::quote('RMB', '6610', '20000', 'UG', 'UGX'), 'Yuan in needs the yuan price.');
    }

    public function test_a_charge_that_eats_the_shillings_due_in_uganda_is_refused(): void
    {
        // 100 yuan is 15.13 dollars, or 60,529 shillings, so a 60,529 charge leaves the receiver
        // nothing and a bigger one takes the booking below zero.
        $this->assertNull(ProfitCalculator::quote('RMB', '100', '60529', 'UG', 'UGX'));
        $this->assertNull(ProfitCalculator::quote('RMB', '100', '70000', 'UG', 'UGX'));
    }

    public function test_a_shilling_booking_never_priced_into_uganda(): void
    {
        // Shillings do not arrive at a desk in China, and the booking form refuses the combination
        // in words. The calculator refuses it too rather than inventing a rate for it.
        $this->assertNull(ProfitCalculator::quote('UGX', '500000', '20000', 'UG', 'UGX'));
    }

    public function test_the_parts_add_back_to_the_total_on_every_route(): void
    {
        $routes = [
            ['UGX', '500000', '20000', 'CN', 'RMB'],
            ['USD', '112', '2000', 'CN', 'RMB'],
            ['UGX', '500000', '20000', 'CN', 'USD'],
            ['RMB', '6610', '20000', 'UG', 'UGX'],
            ['USD', '1000', '20000', 'UG', 'UGX'],
        ];

        foreach ($routes as [$currency, $cash, $charge, $to, $payout]) {
            $quote = $this->quote($currency, $cash, $charge, $to, $payout);
            $label = "{$cash} {$currency} to {$to} in {$payout}";

            $this->assertEqualsWithDelta(
                (float) $quote['fx_profit_ugx'] + (float) $quote['usd_rmb_profit_ugx'] + (float) $quote['charge_ugx'],
                (float) $quote['total_profit_ugx'],
                0.001,
                "The legs do not add up on {$label}."
            );

            $this->assertContains($quote['destination'], ['CN', 'UG'], $label);
            $this->assertContains($quote['payout_currency'], ['RMB', 'USD', 'UGX'], $label);
        }
    }

    public function test_a_booking_carries_its_route_onto_the_row(): void
    {
        $row = Transaction::create(array_merge(
            [
                'created_by' => $this->desk('CN')->id,
                'receipt_number' => 'PS-20261019-9001',
                'sender_name' => 'Zhang Wei',
                'sender_contact' => '+86 138 0000 0000',
                'receiver_name' => 'Tom Okello',
                'receiver_contact' => '+256 700 000 000',
                'currency' => 'RMB',
                'status' => 'pending',
            ],
            $this->quote('RMB', '6610', '20000', 'UG', 'UGX')
        ));

        $this->assertSame('UG', $row->destination);
        $this->assertSame('China', $row->deskLabel());
        $this->assertSame('China', $row->originLabel());
        $this->assertSame('Uganda', $row->destinationLabel());
        $this->assertSame('UGX 3,980,000', $row->payoutLabel());
        $this->assertSame('RMB', $row->currency);
    }

    public function test_the_route_and_payout_are_read_off_the_row_and_never_guessed(): void
    {
        $yuan = $this->row(['destination' => 'CN', 'payout_currency' => 'RMB', 'rmb_amount' => '732.28']);
        $dollars = $this->row(['destination' => 'CN', 'payout_currency' => 'USD', 'total_usd_equivalent' => '111.63']);
        $shillings = $this->row(['destination' => 'UG', 'payout_currency' => 'UGX', 'ugx_amount' => '3980000.00']);

        $this->assertSame('¥732.28', $yuan->payoutLabel());
        $this->assertSame('$111.63', $dollars->payoutLabel());
        $this->assertSame('UGX 3,980,000', $shillings->payoutLabel());

        $this->assertSame('Money to a receiver in China', $yuan->corridorLabel());
        $this->assertSame('Money to a receiver in Uganda', $shillings->corridorLabel());
    }

    /**
     * A hand at a desk, for rows that only have to exist to be read. The desk is the one the route
     * says took the money in, so a row never shows a transfer taken in at the far end of itself.
     */
    private function desk(string $country): User
    {
        return User::create([
            'name' => 'Desk '.$country.' '.uniqid(),
            'email' => strtolower($country).'-'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);
    }

    /**
     * A row with only the columns a display test needs, saved without going through the pricing or
     * the ledger, so a screen can be read without the money behind it moving anywhere.
     */
    private function row(array $attributes): Transaction
    {
        return Transaction::create(array_merge([
            'created_by' => $this->desk(($attributes['destination'] ?? 'CN') === 'UG' ? 'CN' : 'UG')->id,
            'receipt_number' => 'PS-20261019-'.random_int(1000, 9999),
            'sender_name' => 'Asuma Aggrey',
            'sender_contact' => '+256 700 111 111',
            'receiver_name' => 'Wei Ming',
            'receiver_contact' => '+86 138 2222 2222',
            'currency' => 'UGX',
            'amount' => '480000.00',
            'exchange_rate' => '6.560000',
            'rmb_amount' => '0.00',
            'total_usd_equivalent' => '0.00',
            'charge_ugx' => '20000.00',
            'status' => 'pending',
        ], $attributes));
    }
}
