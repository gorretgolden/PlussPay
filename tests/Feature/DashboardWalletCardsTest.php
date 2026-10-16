<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\User;
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

        foreach (['superadmin', 'admin', 'accountant', 'agent', 'staff'] as $role) {
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
        $this->assertStringContainsString('In your hand until you hand it in.', $html);
        $this->assertSame(2, Account::agentWallets()->count());
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
