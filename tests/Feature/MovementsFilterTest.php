<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MovementsFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'agent', 'super-agent', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        foreach (['view-accounts', 'view-all-wallets', 'create-account-entry'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::findByName('admin')->givePermissionTo(['view-accounts', 'view-all-wallets', 'create-account-entry']);
        Role::findByName('agent')->givePermissionTo('view-accounts');
        Role::findByName('super-agent')->givePermissionTo(['view-accounts', 'view-all-wallets']);
        Role::findByName('accountant')->givePermissionTo('view-accounts');
    }

    public function test_the_movements_page_filters_out_empty_non_agent_wallets(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'country' => 'UG',
            'timezone' => 'Africa/Kampala',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $agent = User::create([
            'name' => 'Agent User',
            'email' => 'agent@example.com',
            'password' => bcrypt('password'),
            'country' => 'UG',
            'timezone' => 'Africa/Kampala',
            'is_active' => true,
        ]);
        $agent->assignRole('agent');

        $accountant = User::create([
            'name' => 'Accountant User',
            'email' => 'accountant@example.com',
            'password' => bcrypt('password'),
            'country' => 'UG',
            'timezone' => 'Africa/Kampala',
            'is_active' => true,
        ]);
        $accountant->assignRole('accountant');

        // Create wallets for each user
        $agentWallet = Account::create([
            'code' => 'agent-ugx',
            'name' => 'Agent Wallet',
            'country' => 'UG',
            'currency' => 'UGX',
            'owner_id' => $agent->id,
            'opening_balance' => '500000.00',
        ]);

        $adminWallet = Account::create([
            'code' => 'admin-ugx',
            'name' => 'Admin Wallet',
            'country' => 'UG',
            'currency' => 'UGX',
            'owner_id' => $admin->id,
            'opening_balance' => '0.00',
        ]);

        $accountantWallet = Account::create([
            'code' => 'accountant-ugx',
            'name' => 'Accountant Wallet',
            'country' => 'UG',
            'currency' => 'UGX',
            'owner_id' => $accountant->id,
            'opening_balance' => '0.00',
        ]);

        $response = $this->actingAs($admin)->get(route('accounts.movements'));

        $response->assertOk();

        // Agent wallet should appear (agent role)
        $response->assertSee('Agent User (UGX)');

        // Admin and accountant wallets should not appear (empty and not agent/super-agent)
        $response->assertDontSee('Admin User (UGX)');
        $response->assertDontSee('Accountant User (UGX)');
    }

    public function test_the_movements_page_shows_non_agent_wallets_with_balance(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'country' => 'UG',
            'timezone' => 'Africa/Kampala',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        // Create admin wallet with balance
        $adminWallet = Account::create([
            'code' => 'admin-ugx-balance',
            'name' => 'Admin Wallet',
            'country' => 'UG',
            'currency' => 'UGX',
            'owner_id' => $admin->id,
            'opening_balance' => '1000000.00',
        ]);

        $response = $this->actingAs($admin)->get(route('accounts.movements'));

        $response->assertOk();

        // Admin wallet should appear because it has balance
        $response->assertSee('Admin User (UGX)');
    }
}
