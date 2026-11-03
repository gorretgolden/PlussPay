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
 * The two far ends of the corridor read as their own Overview cards: what the China desk has
 * handed over there, and what receivers in Uganda have been handed from bookings made in China.
 * The Taken By Agents card names the country the agents stand in, so the two desks' cash stays
 * apart.
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

    public function test_the_payout_cards_read_the_money_handed_over_in_each_country(): void
    {
        $office = $this->user('admin', 'Desk Admin');

        $this->paidBooking($office, 'PS-DESK-1', 'CN', 'RMB', 500);
        $this->paidBooking($office, 'PS-DESK-2', 'UG', 'UGX', 2391427);

        $html = $this->dashboardHtml($office);

        // The yuan explainer under the Overview also names the two cards, so the assertions pin
        // the card label markup itself, not the bare phrases.
        $this->assertSame(3, substr_count($html, '<div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Withdrawn In China</div>'), 'One card per window.');
        $this->assertSame(3, substr_count($html, '<div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Received In Uganda</div>'), 'One card per window.');
        $this->assertGreaterThanOrEqual(3, substr_count($html, '¥500.00'), 'The yuan paid in China reads on the card.');
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'UGX 2,391,427'), 'The shillings paid in Uganda read on the card.');
        $this->assertStringContainsString('handed over to receivers in China', $html);
        $this->assertStringContainsString('paid out from bookings made in China', $html);
    }

    public function test_a_window_with_no_payouts_reads_its_own_zero_not_a_word(): void
    {
        $office = $this->user('admin', 'Desk Admin');

        $html = $this->dashboardHtml($office);

        // Two cards across three windows. An empty pile is the currency's own zero, because a
        // word does not say which of the two tills is empty.
        foreach (['Withdrawn In China' => '¥0.00', 'Received In Uganda' => 'UGX 0'] as $label => $zero) {
            $pattern = '#<div class="text-xs font-semibold uppercase tracking-wide text-gray-500">'
                .preg_quote($label, '#').'</div>\s*<div class="stat-value[^"]*"[^>]*>'
                .preg_quote($zero, '#').'</div>#';

            $this->assertSame(3, preg_match_all($pattern, $html), $label.' shows its own zero in each window.');
        }

        $this->assertSame(0, substr_count($html, '>nothing</div>'), 'No money card on the board reads as a word.');
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

    public function test_an_agent_gets_the_payout_cards_without_the_taken_card(): void
    {
        $agent = $this->user('agent', 'Agent Betty', 'UG');

        $html = $this->dashboardHtml($agent);

        $this->assertStringContainsString('Withdrawn In China', $html);
        $this->assertStringContainsString('Received In Uganda', $html);
        $this->assertStringNotContainsString('Taken By Agents', $html);
    }
}
