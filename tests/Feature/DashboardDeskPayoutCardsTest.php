<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The two far ends of the corridor read as their own Overview row: what the China desk still owes
 * receivers there and has already handed over, and the same two figures for Uganda. The Taken By
 * Agents card names the country the agents stand in, so the two desks' cash stays apart.
 */
class DashboardDeskPayoutCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (array_keys(PermissionCatalog::defaults()) as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions(PermissionCatalog::defaults()[$role]);
        }

        ExchangeRate::setPair('USD', 'UGX', 3760, 3750, 3760);
        ExchangeRate::setPair('USD', 'RMB', 7.24, 7.20, 7.24);
    }

    private function user(string $role, string $name, ?string $country = null): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $role)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country ?? User::DEFAULT_COUNTRY,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function paidBooking(User $creator, string $receipt, string $destination, string $payoutCurrency, float $payout): Transaction
    {
        $booking = Transaction::create([
            'receipt_number' => $receipt,
            'sender_name' => 'Sender '.$receipt,
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver '.$receipt,
            'receiver_contact' => '8613800000001',
            'currency' => 'USD',
            'amount' => 250,
            'destination' => $destination,
            'payout_currency' => $payoutCurrency,
            'exchange_rate' => 6.75,
            'rmb_amount' => $payoutCurrency === 'RMB' ? $payout : 0,
            'ugx_amount' => $payoutCurrency === 'UGX' ? $payout : 0,
            'total_usd_equivalent' => 250,
            'status' => 'completed',
            'created_by' => $creator->id,
        ]);

        // delivered_at is stamped by the payout desks themselves and sits outside the
        // fillable list, so the test marks it the way the desk would: after the row exists.
        $booking->forceFill(['delivered_at' => now()])->save();

        return $booking;
    }

    private function dashboardHtml(User $viewer): string
    {
        $this->flushSession();

        return $this->actingAs($viewer)->get('/dashboard')->assertOk()->getContent();
    }

    public function test_the_payout_row_reads_the_money_handed_over_in_each_country(): void
    {
        $office = $this->user('admin', 'Desk Admin');

        $this->paidBooking($office, 'PS-DESK-1', 'CN', 'RMB', 500);
        $this->paidBooking($office, 'PS-DESK-2', 'UG', 'UGX', 2391427);

        $html = $this->dashboardHtml($office);

        // The row heading and the four figures, one set per window.
        $this->assertSame(3, substr_count($html, '>China and Uganda payouts<'), 'One row per window.');
        $this->assertSame(3, substr_count($html, '>Paid in China<'), 'One paid figure per window.');
        $this->assertSame(3, substr_count($html, '>Paid in Uganda<'), 'One paid figure per window.');
        $this->assertSame(3, substr_count($html, '>To pay in China<'), 'One owed figure per window.');
        $this->assertSame(3, substr_count($html, '>To pay in Uganda<'), 'One owed figure per window.');

        $this->assertGreaterThanOrEqual(3, substr_count($html, '¥500.00'), 'The yuan paid in China reads on the row.');
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'UGX 2,391,427'), 'The shillings paid in Uganda read on the row.');
        $this->assertStringContainsString('handed to 1 receiver', $html);
    }

    public function test_a_pile_with_nothing_in_it_reads_a_formatted_zero(): void
    {
        $office = $this->user('admin', 'Desk Admin');

        $html = $this->dashboardHtml($office);

        // "nothing", "UGX 0", "UGX 0.0" and "¥0.0" all used to mean the same figure on one screen.
        // Every empty pile now prints the zero its own currency is written at.
        $this->assertStringContainsString('>¥0.00<', $html);
        $this->assertStringContainsString('>UGX 0<', $html);
        $this->assertStringNotContainsString('>nothing<', $html, 'The word is gone from the money figures.');
        $this->assertStringNotContainsString('UGX 0.0', $html);
        $this->assertStringNotContainsString('¥0.0<', $html);
        $this->assertStringContainsString('every receiver there is paid', $html);
    }

    public function test_the_taken_by_agents_card_names_the_agents_countries(): void
    {
        $office = $this->user('admin', 'Desk Admin');
        $uganda = $this->user('agent', 'Agent Betty', 'UG');
        $china = $this->user('agent', 'Agent Li', 'CN');

        $this->paidBooking($uganda, 'PS-TAKEN-9', 'CN', 'RMB', 100);
        $this->paidBooking($china, 'PS-TAKEN-10', 'UG', 'UGX', 100000);

        $html = $this->dashboardHtml($office);

        $this->assertStringContainsString('Taken By Agents In China And Uganda', $html);
    }

    public function test_an_agent_gets_the_payout_row_without_the_taken_card(): void
    {
        $agent = $this->user('agent', 'Agent Betty', 'UG');

        $html = $this->dashboardHtml($agent);

        $this->assertStringContainsString('Paid in China', $html);
        $this->assertStringContainsString('Paid in Uganda', $html);
        $this->assertStringNotContainsString('Taken By Agents', $html);
    }
}
