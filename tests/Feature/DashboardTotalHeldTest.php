<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashHandover;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The agent's wallet card names total money held: the drawer balance plus what approved hand-ins
 * carried up to the lead agent, because approval moves cash out of the balance but the agent is
 * still answerable for it until the pile is banked and sent on.
 */
class DashboardTotalHeldTest extends TestCase
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

    private function board(User $user): string
    {
        $this->flushSession();

        return $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
    }

    public function test_an_approved_hand_in_is_read_back_into_total_money_held(): void
    {
        $agent = $this->person('agent', 'Amina Nabukenya');
        $lead = $this->person('super-agent', 'Amina Lead');

        AccountLedger::record(Account::walletFor($agent, 'UGX'), 'adjustment_in', '300000', 'Cash float');
        AccountLedger::record(Account::walletFor($agent, 'USD'), 'adjustment_in', '100', 'Dollar float');

        // 200,000 plus 5,000 of charges approved up to the lead: the drawer keeps 95,000 but the
        // agent's total still reads the whole 300,000 plus the charges that crossed with it.
        $request = CashHandover::request($agent, $lead, 'UGX', '200000', null, '5000');
        $this->assertTrue($request->approve());

        $html = $this->board($agent);

        $this->assertStringContainsString('UGX 95,000', $html, 'The balance shows only what is still in the drawer.');
        $this->assertStringContainsString('Total money held UGX 300,000', $html, 'The total reads the approved pile back in.');
        $this->assertStringContainsString('includes UGX 205,000 handed in', $html, 'Principal and the charges that crossed with it.');
        // One line, on the shilling card only: the dollar pile has nothing handed in.
        $this->assertSame(1, substr_count($html, 'Total money held'));
    }

    public function test_a_pending_request_adds_no_line_because_it_never_left_the_balance(): void
    {
        $agent = $this->person('agent', 'Amina Nabukenya');
        $lead = $this->person('super-agent', 'Amina Lead');

        AccountLedger::record(Account::walletFor($agent, 'UGX'), 'adjustment_in', '300000', 'Cash float');
        CashHandover::request($agent, $lead, 'UGX', '200000', null, '5000');

        $html = $this->board($agent);

        $this->assertStringContainsString('UGX 300,000', $html);
        $this->assertStringNotContainsString('Total money held', $html, 'A pile still in the drawer must not be printed twice.');
    }

    public function test_another_agents_approved_hand_in_never_leaks_onto_this_card(): void
    {
        $mine = $this->person('agent', 'Amina Nabukenya');
        $other = $this->person('agent', 'Peter Okello');
        $lead = $this->person('super-agent', 'Amina Lead');

        AccountLedger::record(Account::walletFor($mine, 'UGX'), 'adjustment_in', '300000', 'Cash float');
        AccountLedger::record(Account::walletFor($other, 'UGX'), 'adjustment_in', '990000', 'Cash float');

        $theirs = CashHandover::request($other, $lead, 'UGX', '500000', null, '9000');
        $this->assertTrue($theirs->approve());

        $html = $this->board($mine);

        $this->assertStringContainsString('UGX 300,000', $html);
        $this->assertStringNotContainsString('Total money held', $html, "Another desk's pile was read back onto this board.");
    }
}
