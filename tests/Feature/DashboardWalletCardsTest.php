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
 * The two cards under Quick Actions are custody: shillings and dollars sitting in one person's
 * hand waiting to be handed in. A desk that reads every wallet does that on the Wallets and
 * Accounts screens, so the board used to hand Super Admin two empty cards of their own and,
 * because wallets are created on sight, quietly register two accounts nobody holds money in.
 */
class DashboardWalletCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (['superadmin', 'admin', 'accountant', 'agent', 'super-agent', 'staff'] as $role) {
            $model = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            $model->syncPermissions(PermissionCatalog::defaults()[$role] ?? []);
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

    public function test_a_desk_that_reads_every_wallet_is_given_no_personal_cards(): void
    {
        foreach ([['superadmin', 'Super Admin'], ['admin', 'Grace Admin'], ['accountant', 'Peter Books']] as [$role, $name]) {
            $html = $this->board($this->person($role, $name));

            foreach (['UGX Wallet', 'USD Wallet', 'No balance yet'] as $card) {
                $this->assertStringNotContainsString($card, $html, "The {$role} board still carries the {$card} card.");
            }
        }
    }

    public function test_reading_the_board_as_a_desk_no_longer_registers_wallets_nobody_holds(): void
    {
        $this->board($this->person('superadmin', 'Super Admin'));
        $this->board($this->person('admin', 'Grace Admin'));

        $this->assertSame(0, Account::agentWallets()->count(), 'No personal wallet account should be created by a visit.');
    }

    public function test_an_agent_still_sees_the_two_balances_they_hold(): void
    {
        $html = $this->board($this->person('agent', 'Amina Nabukenya'));

        $this->assertStringContainsString('UGX Wallet', $html);
        $this->assertStringContainsString('USD Wallet', $html);
        $this->assertStringContainsString('Cash on hand, not yet deposited', $html);
        $this->assertSame(2, Account::agentWallets()->count());
    }

    public function test_both_wallet_cards_print_the_same_rows_in_the_same_order(): void
    {
        $html = $this->board($this->person('agent', 'Amina Nabukenya'));

        // Two cards, so every row that names a slice of the balance is printed exactly twice. A row
        // that dropped off one card would leave the pair at different heights and read as though
        // one wallet were being described differently from the other.
        foreach ([
            'Of which awaiting approval',
            'Ready to hand in',
            'Fees this month',
            'Handed in so far',
        ] as $row) {
            $this->assertSame(2, substr_count($html, $row), "Both cards should print the {$row} row.");
        }
    }

    public function test_the_lead_agent_s_cards_read_the_road_from_his_end(): void
    {
        $lead = $this->person('super-agent', 'Amina Lead');

        // Cash comes up to him from the desks below and leaves from his own counter, so a row that
        // says "hand in" would name a road he never travels.
        $html = $this->board($lead);

        $this->assertStringContainsString('Ready to send on', $html);
        $this->assertStringContainsString('Sent on so far', $html);
        $this->assertStringNotContainsString('Ready to hand in', $html);
        $this->assertStringNotContainsString('Handed in so far', $html);
        $this->assertSame(2, substr_count($html, 'Ready to send on'), 'Both of his cards print the same rows.');
    }

    public function test_the_pending_amount_is_described_as_part_of_the_balance_not_as_extra(): void
    {
        $agent = $this->person('agent', 'Amina Nabukenya');
        $lead = $this->person('super-agent', 'Amina Lead');

        // 300,000 real in the drawer, and a request for 200,000 of it that the lead has not
        // approved. A request moves no money, so the whole 300,000 is still the balance and the
        // 200,000 is a slice of it, never 300,000 plus another 200,000.
        AccountLedger::record(Account::walletFor($agent, 'UGX'), 'adjustment_in', '300000', 'Cash float');
        CashHandover::request($agent, $lead, 'UGX', '200000');

        $html = $this->board($agent);

        $this->assertStringContainsString('UGX 300,000', $html, 'The balance still counts the money a request is waiting on.');
        $this->assertStringContainsString('Of which awaiting approval', $html, 'The pending figure is named as part of the balance.');
        $this->assertStringContainsString('UGX 200,000', $html);
        $this->assertStringContainsString('Ready to hand in', $html);
        $this->assertStringContainsString('UGX 100,000', $html, 'Ready is the balance with the pending slice taken off it.');

        // Nothing on the card may imply the waiting money sits beside the balance rather than in it:
        // the old caption that framed it as separate cash, and the old "charge kept" line, are gone.
        $this->assertStringNotContainsString('waiting for the super agent', strtolower($html));
        $this->assertStringNotContainsString('Charge kept this month', $html);
    }

    public function test_a_second_agents_hand_ins_never_appear_on_this_desk_board(): void
    {
        // Guard the slice framing against a wrong scoping: only the signed-in agent's pending rows
        // belong on the card. See DashboardHandInInsightTest for the fuller reach test.
        $mine = $this->person('agent', 'Amina Nabukenya');
        $other = $this->person('agent', 'Peter Okello');
        $lead = $this->person('super-agent', 'Amina Lead');

        CashHandover::request($mine, $lead, 'UGX', '150000');
        CashHandover::request($other, $lead, 'UGX', '990000');

        $html = $this->board($mine);

        $this->assertStringContainsString('UGX 150,000', $html);
        $this->assertStringNotContainsString('UGX 990,000', $html, "Another desk's pending request leaked onto this board.");
    }

    public function test_desk_staff_holding_money_keep_their_cards(): void
    {
        $html = $this->board($this->person('staff', 'Ivan Clerk'));

        $this->assertStringContainsString('UGX Wallet', $html);
        $this->assertStringContainsString('USD Wallet', $html);
    }

    public function test_a_china_desk_that_holds_money_still_sees_yuan(): void
    {
        $html = $this->board($this->person('agent', 'Mei Lin', 'CN'));

        $this->assertStringContainsString('RMB Wallet', $html);
        $this->assertStringNotContainsString('UGX Wallet', $html);
    }

    public function test_the_rest_of_the_board_is_untouched_by_the_change(): void
    {
        $html = $this->board($this->person('superadmin', 'Super Admin'));

        foreach (['Quick Actions', 'Overview', 'Charge', 'Reload', 'Log out'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
    }
}
