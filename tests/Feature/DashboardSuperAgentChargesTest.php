<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\AccountLedger;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The lead agent's Charges Collected card: their own bookings' charges plus the charges agents
 * handed in with their cash, this month, in shillings. Read from the ledger so it is the same
 * figure the wallet page prints.
 */
class DashboardSuperAgentChargesTest extends TestCase
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

    private function person(string $role, string $name): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function board(User $user): string
    {
        $this->flushSession();

        return $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();
    }

    public function test_the_lead_reads_their_own_charges_plus_the_handed_in_pile(): void
    {
        $lead = $this->person('super-agent', 'Amina Lead');

        $ugx = Account::walletFor($lead, 'UGX');
        AccountLedger::record($ugx, 'agent_fee', '12000', 'Charges on a booking');
        AccountLedger::record($ugx, 'fee_handover_in', '5000', 'Charges handed in');
        AccountLedger::record($ugx, 'agent_fee_reversal', '2000', 'Cancelled booking given back');
        // Last month's earnings stay off a card that names this month.
        AccountLedger::record($ugx, 'agent_fee', '9999', 'Old charges', now()->subMonthNoOverflow()->toDateString());

        $html = $this->board($lead);

        $this->assertStringContainsString('Charges Collected', $html);
        $this->assertStringContainsString('UGX 15,000', $html, 'Own charges plus handed-in charges, reversal off, last month out.');
        $this->assertStringContainsString("your bookings' charges and what the agents handed in", $html);
    }

    public function test_a_lead_with_nothing_collected_still_reads_a_zero_card(): void
    {
        $html = $this->board($this->person('super-agent', 'Amina Lead'));

        $this->assertStringContainsString('Charges Collected', $html);
        $this->assertStringContainsString('UGX 0', $html);
    }

    public function test_nobody_else_gets_the_charges_card(): void
    {
        $agent = $this->person('agent', 'Amina Nabukenya');
        $admin = $this->person('admin', 'Grace Admin');

        foreach ([$agent, $admin] as $viewer) {
            $this->assertStringNotContainsString('Charges Collected', $this->board($viewer));
        }
    }
}
