<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A receipt is signed, so it has to say who signed it. The hand that took the money is whoever the
 * booking was written into: the clerk standing at the counter, or the agent the office booked for.
 * That name is saved on the transfer, so the line keeps the same person however many accounts open
 * the copy afterwards. The customer's side carries the sender's name for the same reason.
 *
 * The money is set out the way the paper receipt book at the counter sets it out: particulars, rate,
 * charge and amount each get a column of their own, and the amount column says both what travelled
 * and what the receiver is handed. The heading changes with the corridor and the payout currency,
 * because a slip for the Uganda desk and a slip for the China desk are not the same promise.
 */
class ReceiptSignedCopyTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->desk = User::create([
            'name' => 'Clerk Reading',
            'email' => 'reader@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $this->desk->assignRole('admin');

        $this->agent = User::create([
            'name' => 'Nakato Grace',
            'email' => 'grace@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $this->agent->assignRole('agent');

        Setting::set('company_name', 'Plus Save Financial Solutions', 'string', 'company');

        Transaction::create([
            'receipt_number' => 'PS-SIGN-1',
            'sender_name' => 'Asuma Aggrey',
            'sender_contact' => '0700000001',
            'receiver_name' => 'Wei Ming',
            'receiver_contact' => '8613800000001',
            'currency' => 'USD',
            'amount' => 250.5,
            'exchange_rate' => 7.30,
            'rate_sell_ugx' => 3760,
            'rmb_amount' => 1850.5,
            'charge_ugx' => 20000,
            'total_usd_equivalent' => 250.5,
            'purpose' => 'School fees',
            'status' => 'completed',
            'created_by' => $this->agent->id,
        ]);
    }

    private function screen(string $number = 'PS-SIGN-1'): string
    {
        return $this->actingAs($this->desk)
            ->get('/receipts/'.$number)->assertOk()->getContent();
    }

    private function slip(string $number = 'PS-SIGN-1'): string
    {
        return $this->actingAs($this->desk)
            ->get('/receipts/'.$number.'/print')->assertOk()->getContent();
    }

    public function test_both_names_stand_on_their_own_line_with_the_label_underneath(): void
    {
        foreach ([$this->screen(), $this->slip()] as $html) {
            $this->assertStringContainsString('Nakato Grace', $html);
            $this->assertStringContainsString('Signature and name, for Plus Save Financial Solutions', $html);
            $this->assertStringContainsString('Signature and name, customer', $html);

            // The blank prompt the desk used to fill in by hand is gone from both copies.
            $this->assertStringNotContainsString('Signature & Name:', $html);
        }
    }

    public function test_a_copy_another_account_opens_still_names_the_hand_that_took_the_money(): void
    {
        $other = User::create([
            'name' => 'Someone Later',
            'email' => 'later@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $other->assignRole('superadmin');

        $screen = $this->actingAs($other)->get('/receipts/PS-SIGN-1')->assertOk()->getContent();
        $slip = $this->actingAs($other)->get('/receipts/PS-SIGN-1/print')->assertOk()->getContent();

        // The bar above the receipt does carry whoever is signed in, so the claim is checked where
        // the money is named rather than anywhere on the page.
        foreach ([$this->takenBy($screen), $this->takenBy($slip)] as $cell) {
            $this->assertStringContainsString('Nakato Grace', $cell);
            $this->assertStringNotContainsString('Someone Later', $cell);
        }
    }

    /**
     * The words that say whose hand took the money, with the name that follows them.
     */
    private function takenBy(string $html): string
    {
        $at = strpos($html, 'Money taken by');
        $this->assertNotFalse($at, 'the copy never says whose hand took the money');

        return substr($html, $at, 160);
    }

    public function test_the_paper_table_keeps_every_figure_in_its_own_column(): void
    {
        foreach ([$this->screen(), $this->slip()] as $html) {
            foreach (['PARTICULARS', 'RATE', 'CHARGE', 'AMOUNT (USD/RMB)'] as $heading) {
                $this->assertStringContainsString($heading, $html, 'the paper column heading is missing: '.$heading);
            }

            // The particulars name the corridor, the rates are the two prices the shillings were
            // turned at, the charge stands alone, and the amount cell says what travelled and what
            // the receiver is handed. The total is its own row under the table.
            $this->assertStringContainsString('Sending money to China', $html);
            $this->assertStringContainsString('UGX 3760', $html);
            $this->assertStringContainsString('RMB 7.3', $html);
            $this->assertStringContainsString('UGX 20,000', $html);
            $this->assertStringContainsString('USD 250.5', $html);
            $this->assertStringContainsString('¥1,850.5 RMB', $html);
            $this->assertStringContainsString('Total cash handed over', $html);
            $this->assertStringContainsString('USD 250.5 plus UGX 20,000', $html);

            // A dollar customer pays the charge beside the money, rather than out of it.
            $this->assertStringContainsString('The charge is paid alongside the cash handed over.', $html);
            $this->assertStringContainsString('For official use only', $html);
        }
    }

    public function test_a_booking_into_uganda_is_titled_the_old_way_round_and_pays_shillings(): void
    {
        Transaction::create([
            'receipt_number' => 'PS-SIGN-UG-1',
            'sender_name' => 'Li Wei',
            'sender_contact' => '8613800000002',
            'receiver_name' => 'Nakato Grace',
            'receiver_contact' => '0700000002',
            'currency' => 'RMB',
            'amount' => 500,
            'exchange_rate' => 7.20,
            'rate_sell_ugx' => 3760,
            'rmb_buying_rate' => 7.20,
            'rmb_amount' => 0,
            'ugx_amount' => 240416,
            'total_usd_equivalent' => 69.44,
            'charge_ugx' => 20000,
            'destination' => Transaction::TO_UGANDA,
            'payout_currency' => 'UGX',
            'status' => 'completed',
            'created_by' => $this->agent->id,
        ]);

        foreach ([$this->screen('PS-SIGN-UG-1'), $this->slip('PS-SIGN-UG-1')] as $html) {
            $slip = $this->slipOnly($html);

            $this->assertStringContainsString('<h1 class="slip-title">Send money to Uganda</h1>', $slip);
            $this->assertStringNotContainsString('Send money to China', $slip);
            $this->assertStringContainsString('AMOUNT (RMB/UGX)', $slip);
            $this->assertStringContainsString('RMB 500', $slip);
            $this->assertStringContainsString('(UGX 240,416)', $slip);
            $this->assertStringContainsString('UGX 3760', $slip);
            $this->assertStringContainsString('RMB 7.2', $slip);

            // The charge comes out of the shillings the receiver is handed on this route, so the
            // slip says so rather than repeating the China-route note.
            $this->assertStringContainsString('The charge is taken out of the shillings the receiver is paid.', $slip);
            $this->assertStringNotContainsString('¥', $slip);
        }
    }

    public function test_a_dollar_payout_to_china_never_prints_a_yuan_price(): void
    {
        Transaction::create([
            'receipt_number' => 'PS-SIGN-USD-1',
            'sender_name' => 'Asuma Aggrey',
            'sender_contact' => '0700000003',
            'receiver_name' => 'Wei Ming',
            'receiver_contact' => '8613800000003',
            'currency' => 'USD',
            'amount' => 250.5,
            'exchange_rate' => 0,
            'rate_sell_ugx' => 3760,
            'rmb_amount' => 0,
            'total_usd_equivalent' => 250.5,
            'charge_ugx' => 20000,
            'destination' => Transaction::TO_CHINA,
            'payout_currency' => 'USD',
            'status' => 'completed',
            'created_by' => $this->agent->id,
        ]);

        foreach ([$this->screen('PS-SIGN-USD-1'), $this->slip('PS-SIGN-USD-1')] as $html) {
            $slip = $this->slipOnly($html);

            $this->assertStringContainsString('AMOUNT (USD)', $slip);
            $this->assertStringContainsString('($250.5 USD)', $slip);
            $this->assertStringContainsString('UGX 3760', $slip);
            $this->assertStringNotContainsString('RMB 7', $slip);
            $this->assertStringNotContainsString('¥', $slip);
        }
    }

    /**
     * The slip inside either copy, so a wallet card in the surrounding page cannot satisfy or
     * break a claim about what the customer's paper says.
     */
    private function slipOnly(string $html): string
    {
        $start = strpos($html, '<article class="slip"');
        $this->assertNotFalse($start, 'the copy has no paper slip');

        $end = strpos($html, '</article>', $start);
        $this->assertNotFalse($end, 'the paper slip is not closed');

        return substr($html, $start, $end - $start);
    }

    public function test_a_booking_is_timed_and_not_merely_dated(): void
    {
        foreach ([$this->screen(), $this->slip()] as $html) {
            $this->assertSame(1, preg_match('/\d{1,2}:\d{2}\s?(AM|PM)/', $html), 'no clock time on the copy');
        }
    }

    public function test_the_slip_names_the_hand_that_took_the_money_and_the_reason(): void
    {
        $html = $this->slip();

        $this->assertStringContainsString('Money taken by', $html);
        $this->assertStringContainsString('Nakato Grace', $html);
        $this->assertStringContainsString('School fees', $html);
    }
}
