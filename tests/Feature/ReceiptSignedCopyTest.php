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
 * the copy afterwards. The customer's side carries the sender's name for the same reason. The money
 * underneath both names is one figure to a line, because a table that puts the charge, the price and
 * the amount in four columns against each other gets read as arithmetic nobody checked.
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

    private function screen(): string
    {
        return $this->actingAs($this->desk)
            ->get('/receipts/PS-SIGN-1')->assertOk()->getContent();
    }

    private function slip(): string
    {
        return $this->actingAs($this->desk)
            ->get('/receipts/PS-SIGN-1/print')->assertOk()->getContent();
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

    public function test_the_money_reads_as_one_figure_to_a_line(): void
    {
        foreach ([$this->screen(), $this->slip()] as $html) {
            $labels = ['SENDING MONEY TO CHINA', 'SENDING CHARGE', 'TOTAL HANDED OVER', 'PAID OUT IN CHINA'];
            $found = 0;

            foreach ($labels as $label) {
                $found += (int) str_contains(strtolower($html), strtolower($label));
            }

            $this->assertSame(4, $found, 'the money is not split into its own lines');

            // The four-column header that left the charge and the price fighting for one cell.
            $this->assertStringNotContainsString('AMOUNT (USD/RMB)', $html);
            $this->assertStringNotContainsString('PARTICULARS', $html);
        }

        $this->assertStringContainsString('USD 250.5', $this->screen());
        $this->assertStringContainsString('UGX 20,000', $this->screen());
        $this->assertStringContainsString('¥1,850.5', $this->screen());
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
