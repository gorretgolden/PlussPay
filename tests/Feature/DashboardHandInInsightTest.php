<?php

namespace Tests\Feature;

use App\Models\CashHandover;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The hand-in card is a small insight on an agent's own board: how many requests they have sent in
 * each state, what those come to, and the last one they sent. It is read from the signed-in account
 * in the controller, never from the address bar, so one desk can never be shown another desk's cash.
 */
class DashboardHandInInsightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['agent', 'super-agent', 'admin'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions(PermissionCatalog::defaults()[$role] ?? []);
        }

        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);
        ExchangeRate::setPair('USD', 'RMB', 6.56, 6.56, 6.56);
    }

    private function person(string $role, string $name, string $country = 'UG'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => $country,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function lead(): User
    {
        return $this->person('super-agent', 'Amina Lead');
    }

    private function request(User $agent, User $lead, string $status, string $currency, string $amount): CashHandover
    {
        return CashHandover::create([
            'reference' => 'INS-'.strtoupper(substr(uniqid(), -7)),
            'agent_id' => $agent->id,
            'super_agent_id' => $lead->id,
            'currency' => $currency,
            'amount' => $amount,
            'status' => $status,
        ]);
    }

    private function board(User $user): string
    {
        $this->flushSession();

        return $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
    }

    public function test_the_insight_counts_this_agents_requests_in_each_state(): void
    {
        $agent = $this->person('agent', 'Amina Nabukenya');
        $lead = $this->lead();

        $this->request($agent, $lead, CashHandover::PENDING, 'UGX', '100000');
        $this->request($agent, $lead, CashHandover::PENDING, 'UGX', '50000');
        $this->request($agent, $lead, CashHandover::APPROVED, 'UGX', '200000');
        $this->request($agent, $lead, CashHandover::DECLINED, 'UGX', '75000');

        $html = $this->board($agent);

        $this->assertStringContainsString('Cash You Hand In', $html);
        $this->assertStringContainsString('Pending', $html);
        $this->assertStringContainsString('2 requests', $html);
        $this->assertStringContainsString('UGX 150,000', $html);
        $this->assertStringContainsString('1 request', $html);
        $this->assertStringContainsString('UGX 200,000', $html);
        $this->assertStringContainsString('Sent back', $html);
        $this->assertStringContainsString('UGX 75,000', $html);

        // Exactly one link to the whole book, whatever the state count is above it.
        $this->assertSame(1, substr_count($html, 'Every hand-in'));
        $this->assertStringContainsString(route('cash-handovers.index'), $html);
    }

    public function test_a_second_agents_requests_never_appear_on_this_board(): void
    {
        $agent = $this->person('agent', 'Amina Nabukenya');
        $other = $this->person('agent', 'Peter Okello');
        $lead = $this->lead();

        $this->request($agent, $lead, CashHandover::PENDING, 'UGX', '150000');
        $this->request($other, $lead, CashHandover::PENDING, 'UGX', '880000');
        $this->request($other, $lead, CashHandover::APPROVED, 'UGX', '660000');

        $html = $this->board($agent);

        $this->assertStringContainsString('UGX 150,000', $html);
        $this->assertStringNotContainsString('880,000', $html, "Another desk's hand-in leaked onto this board.");
        $this->assertStringNotContainsString('660,000', $html);
    }

    public function test_the_insight_is_scoped_to_the_signed_in_desk_and_ignores_the_address_bar(): void
    {
        $agent = $this->person('agent', 'Amina Nabukenya');
        $other = $this->person('agent', 'Peter Okello');
        $lead = $this->lead();

        $this->request($agent, $lead, CashHandover::PENDING, 'UGX', '150000');
        $this->request($other, $lead, CashHandover::PENDING, 'UGX', '999000');

        // A query that names the other desk cannot point this board at the other desk's money.
        $this->flushSession();
        $html = $this->actingAs($agent)->get('/dashboard?agent_id='.$other->id)->assertOk()->getContent();

        $this->assertStringContainsString('UGX 150,000', $html);
        $this->assertStringNotContainsString('999,000', $html);
    }

    public function test_a_desk_that_is_not_an_agent_is_handed_no_insight(): void
    {
        $admin = $this->person('admin', 'Grace Admin');
        $lead = $this->lead();
        $agent = $this->person('agent', 'Amina Nabukenya');
        $this->request($agent, $lead, CashHandover::PENDING, 'UGX', '150000');

        $html = $this->board($admin);

        $this->assertStringNotContainsString('Cash You Hand In', $html, 'The insight is an agent card, not an office one.');
    }

    public function test_the_insight_pairs_with_the_spending_card_under_the_wallets(): void
    {
        $agent = $this->person('agent', 'Amina Nabukenya');
        $lead = $this->lead();
        $this->request($agent, $lead, CashHandover::PENDING, 'UGX', '100000');

        $html = $this->board($agent);

        // The board reads top down: the wallets two across, then the shortcuts, then this card
        // standing beside the day's spending at the same height. Nothing is stacked in a narrow
        // left column under the wallet any more.
        $walletsAt = strpos($html, 'grid grid-cols-1 md:grid-cols-2 gap-4 items-stretch mb-6');
        $actionsAt = strpos($html, '>Quick Actions<');
        $pairAt = strpos($html, 'grid grid-cols-1 lg:grid-cols-2 gap-4 items-stretch');
        $insightAt = strpos($html, 'Cash You Hand In');
        $overviewAt = strpos($html, 'role="tabpanel"');

        foreach ([$walletsAt, $actionsAt, $pairAt, $insightAt, $overviewAt] as $position) {
            $this->assertNotFalse($position, 'The board is missing a section it is meant to open with.');
        }

        $this->assertLessThan($actionsAt, $walletsAt, 'The wallet cards come above the shortcuts.');
        $this->assertLessThan($pairAt, $actionsAt, 'The shortcuts come above the hand-in row.');
        $this->assertLessThan($overviewAt, $insightAt, 'The hand-in row sits above the Overview figures.');
        $this->assertStringContainsString('shadow-sm p-4 sm:p-5 h-full flex flex-col', $html, 'The card fills its half of the row.');
    }

    public function test_a_non_agent_board_gets_the_same_wallet_grid_and_no_insight(): void
    {
        $lead = $this->lead();

        $html = $this->board($lead);

        $this->assertStringContainsString('grid grid-cols-1 md:grid-cols-2 gap-4 items-stretch mb-6', $html, 'Every desk gets the wallets two across.');
        $this->assertStringNotContainsString('Cash You Hand In', $html, 'Only a desk that sends hand-ins is given the card.');
    }
}
