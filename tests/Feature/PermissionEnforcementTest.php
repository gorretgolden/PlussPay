<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CurrencyExchange;
use App\Models\Transaction;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        return $user;
    }

    private function role(string $name): Role
    {
        return Role::findByName($name, 'web');
    }

    private function transfer(User $owner, string $receipt): Transaction
    {
        return Transaction::withoutEvents(fn () => Transaction::create([
            'receipt_number' => $receipt, 'sender_name' => 'Sender '.$receipt, 'sender_contact' => '',
            'receiver_name' => 'Receiver', 'receiver_contact' => '', 'currency' => 'USD',
            'amount' => 100, 'exchange_rate' => 7, 'rmb_amount' => 700, 'total_usd_equivalent' => 100,
            'charge_rate' => 0, 'charge_amount' => 1, 'charge_ugx' => 3750, 'status' => 'completed',
            'created_by' => $owner->id, 'fx_profit_ugx' => 12345, 'fx_profit_usd' => 3,
            'usd_rmb_profit_ugx' => 23456, 'usd_rmb_profit_cny' => 2, 'rate_buy_ugx' => 3750,
        ]));
    }

    private function exchange(User $owner, string $reference): CurrencyExchange
    {
        return CurrencyExchange::create([
            'reference' => $reference, 'exchange_date' => today(), 'person_name' => 'Person '.$reference,
            'in_currency' => 'UGX', 'out_currency' => 'USD', 'in_amount' => 375000, 'out_amount' => 100,
            'in_account_id' => Account::byCode(Account::UGX_UGANDA)->id,
            'out_account_id' => Account::byCode(Account::USD_UGANDA)->id,
            'created_by' => $owner->id, 'margin_ugx' => 6789, 'status' => 'active',
        ]);
    }

    public function test_migrations_initialize_a_nonzero_web_catalog_and_group_by_module(): void
    {
        $this->assertGreaterThan(50, Permission::count());
        $this->assertSame(['web'], Permission::distinct()->pluck('guard_name')->all());
        $this->assertEqualsCanonicalizing(PermissionCatalog::names(), Permission::pluck('name')->all());
        $this->assertSame('Exchange Rates', PermissionCatalog::module('manage-exchange-rates'));
        $this->assertSame('Users', PermissionCatalog::module('create-user'));
        $this->actingAs($this->user('superadmin'))->get('/admin/roles')->assertOk()
            ->assertSee(Role::count().' roles')->assertSee('Send Money');
    }

    public function test_unseeded_existing_roles_receive_defaults_without_creating_users(): void
    {
        DB::table('role_has_permissions')->delete();
        Permission::query()->delete();
        Role::query()->update(['permissions_initialized_at' => null]);
        (new PermissionsSeeder)->run();
        $this->assertSame(0, User::count());
        foreach (PermissionCatalog::defaults() as $name => $defaults) {
            $this->assertEqualsCanonicalizing($defaults, $this->role($name)->permissions->pluck('name')->all());
        }
    }

    public function test_rerun_preserves_revocations_custom_grants_empty_roles_and_descriptions(): void
    {
        $admin = $this->role('admin');
        $admin->revokePermissionTo('view-settings');
        $custom = Permission::create(['name' => 'custom-audit', 'guard_name' => 'web']);
        $admin->givePermissionTo($custom);
        $admin->forceFill(['description' => 'Customized desk'])->save();
        $this->role('agent')->syncPermissions([]);
        $before = $admin->fresh()->permissions->pluck('name')->all();
        (new PermissionsSeeder)->run();
        (new PermissionsSeeder)->run();
        $this->assertEqualsCanonicalizing($before, $admin->fresh()->permissions->pluck('name')->all());
        $this->assertSame('Customized desk', $admin->fresh()->description);
        $this->assertCount(0, $this->role('agent')->permissions);
    }

    public function test_first_run_preserves_preexisting_customized_permission_roles(): void
    {
        $admin = $this->role('admin');
        $admin->syncPermissions(['view-transactions']);
        $admin->forceFill(['permissions_initialized_at' => null])->save();
        $this->role('agent')->syncPermissions([]);
        $this->role('agent')->forceFill(['permissions_initialized_at' => null])->save();
        (new PermissionsSeeder)->run();
        $this->assertSame(['view-transactions'], $admin->fresh()->permissions->pluck('name')->all());
        $this->assertCount(0, $this->role('agent')->fresh()->permissions);
    }

    public function test_custom_manager_permission_grants_and_revokes_real_direct_url_access(): void
    {
        $manager = $this->user('manager');
        $root = $this->user('superadmin');
        $role = $this->role('manager');
        $this->actingAs($manager)->get('/exchange-rates')->assertForbidden();
        $this->actingAs($root)->put('/admin/roles/'.$role->id, ['permissions' => ['view-exchange-rates']])->assertSessionHasNoErrors();
        $this->actingAs($manager->fresh())->get('/exchange-rates')->assertOk();
        $this->actingAs($root)->put('/admin/roles/'.$role->id, ['permissions' => []])->assertSessionHasNoErrors();
        $this->actingAs($manager->fresh())->get('/exchange-rates')->assertForbidden();
    }

    public function test_admin_role_name_does_not_bypass_a_revoked_permission_or_export_guard(): void
    {
        $admin = $this->user('admin');
        $this->role('admin')->revokePermissionTo('view-transactions');
        $this->actingAs($admin->fresh())->get('/transactions')->assertForbidden();
        $this->get('/transactions/create')->assertForbidden();
        $this->get('/export/transactions')->assertForbidden();
        $this->post('/transactions', [])->assertForbidden();
        $this->role('admin')->givePermissionTo('view-transactions');
        $this->role('admin')->revokePermissionTo('export-transactions');
        $this->actingAs($admin->fresh())->get('/transactions')->assertOk();
        $this->get('/export/transactions')->assertForbidden();
    }

    public function test_accountant_reads_the_books_and_writes_customers_but_keeps_out_of_the_spending_book(): void
    {
        $accountant = $this->user('accountant');
        foreach (['view-all-transactions', 'view-profit', 'view-customers', 'view-accounts', 'view-expenses', 'view-all-expenses', 'export-expenses', 'view-exchange-rates'] as $ability) {
            $this->assertTrue($accountant->can($ability), $ability);
        }
        // Customers and account entries are book entries the old books gate let the accountant
        // write, so that access carries over. Booking a transfer, setting a price, managing people
        // and opening settings were never in it, and neither is the spending book: an expense is
        // recorded by the desk that paid the money out, while the accountant reads that book to
        // check it and report on it.
        foreach (['create-customer', 'edit-customer', 'create-account-entry'] as $ability) {
            $this->assertTrue($accountant->can($ability), $ability);
        }
        foreach (['create-expense', 'void-expense', 'create-transaction', 'create-exchange', 'view-users', 'create-user', 'manage-exchange-rates', 'view-settings'] as $ability) {
            $this->assertFalse($accountant->can($ability), $ability);
        }
        $this->actingAs($accountant)->get('/exchange-rates')->assertOk();
        $this->get('/expenses')->assertOk();
        foreach (['/settings', '/transactions/create', '/exchanges/create', '/users', '/expenses/create'] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post('/expenses', [])->assertForbidden();
        $rate = \App\Models\ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'UGX', 'rate' => 3750]);
        $this->patch('/exchange-rates/'.$rate->id, [])->assertForbidden();
    }

    public function test_agent_lists_receipts_and_reports_remain_own_only(): void
    {
        $agent = $this->user('agent');
        $other = $this->user('agent');
        $own = $this->transfer($agent, 'OWN-ONLY');
        $foreign = $this->transfer($other, 'FOREIGN-PRIVATE');
        $this->actingAs($agent)->get('/transactions')->assertOk()->assertSee($own->receipt_number)->assertDontSee($foreign->receipt_number);
        $this->get('/transactions/'.$foreign->id)->assertForbidden();
        $this->get('/receipts')->assertOk()->assertSee($own->receipt_number)->assertDontSee($foreign->receipt_number);
        $this->get('/receipts/'.$foreign->receipt_number.'/print')->assertNotFound();
        $this->get('/reports')->assertOk()->assertSee($own->receipt_number)->assertDontSee($foreign->receipt_number)->assertDontSee('"exchange_ugx":', false);
        // The exchange desk moved to the lead agent, so an ordinary agent cannot open it at all.
        $this->get('/exchanges')->assertForbidden();
        $this->get('/exchanges/create')->assertForbidden();
        foreach (['/customers', '/accounts', '/settings', '/exchange-rates', '/admin/roles'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_the_lead_agent_sees_every_agents_transfers_and_runs_the_exchange_desk(): void
    {
        $lead = $this->user('super-agent');
        $agent = $this->user('agent');
        $own = $this->transfer($lead, 'LEAD-BOOK');
        $foreign = $this->transfer($agent, 'AGENT-BOOK');

        $this->actingAs($lead)->get('/transactions')
            ->assertOk()
            ->assertSee($own->receipt_number)
            ->assertSee($foreign->receipt_number);
        $this->get('/transactions/'.$foreign->id)->assertOk();
        $this->get('/exchanges')->assertOk();
        $this->get('/exchanges/create')->assertOk();
        $this->get('/china')->assertOk();

        // Oversight of the agents is not oversight of the office books.
        foreach (['/accounts', '/customers', '/settings', '/admin/roles'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_all_record_scope_and_profit_are_independent_from_list_permissions(): void
    {
        $admin = $this->user('admin');
        $other = $this->user('agent');
        $own = $this->transfer($admin, 'ADMIN-OWN');
        $foreign = $this->transfer($other, 'HIDDEN-BOOKING');
        $this->role('admin')->revokePermissionTo(['view-all-transactions', 'view-profit', 'view-all-wallets', 'view-all-exchanges']);
        $this->actingAs($admin->fresh())->get('/transactions')->assertOk()->assertSee($own->receipt_number)->assertDontSee($foreign->receipt_number)->assertDontSee('Overall Profit');
        $this->get('/transactions/'.$foreign->id)->assertForbidden();
        // The profit report is refused outright once the ability is gone. It used to open with the
        // figures stripped out, which left a page of headings and a table of n/a on the same URL
        // the office reads its numbers from.
        $this->get('/reports?type=profit')->assertForbidden();
        $this->get('/reports/export?type=profit')->assertForbidden();
        $this->get('/export/profit-report')->assertForbidden();
        $this->get('/agents/performance')->assertForbidden();
        $this->get('/wallet')->assertViewIs('wallets.my');
    }

    public function test_agent_exports_have_only_own_records_and_no_profit_columns(): void
    {
        $agent = $this->user('agent');
        $other = $this->user('agent');
        $this->transfer($agent, 'EXPORT-OWN');
        $this->transfer($other, 'EXPORT-SECRET');
        $this->actingAs($agent);
        foreach (['/export/transactions', '/reports/export', '/export/receipts'] as $url) {
            $response = $this->get($url)->assertOk();
            $file = tempnam(sys_get_temp_dir(), 'permission-export-');
            try {
                file_put_contents($file, $response->streamedContent());
                $rows = IOFactory::load($file)->getActiveSheet()->toArray();
                $text = json_encode($rows);
                $this->assertStringContainsString('EXPORT-OWN', $text);
                $this->assertStringNotContainsString('EXPORT-SECRET', $text);
                $this->assertStringNotContainsString('Overall Profit', $text);
                $this->assertStringNotContainsString('Exchange Profit', $text);
            } finally { unlink($file); }
        }
    }

    public function test_superadmin_full_access_is_locked_for_every_mutation_path_even_for_root(): void
    {
        $root = $this->user('superadmin');
        $role = $this->role('superadmin');
        $permission = Permission::findByName('view-transactions', 'web');
        $role->syncPermissions([]);
        $this->assertTrue($root->fresh()->can('view-system-logs'));
        $this->actingAs($root)->put('/admin/roles/'.$role->id, ['permissions' => []])->assertForbidden();
        $this->post('/admin/permissions/'.$permission->id.'/roles/'.$role->id.'/toggle')->assertForbidden();
        foreach (['assign', 'remove'] as $action) {
            $this->post('/admin/permissions/bulk', ['action' => $action, 'role_id' => $role->id, 'permission_ids' => [$permission->id]])->assertForbidden();
        }
        $this->delete('/admin/roles/'.$role->id, ['confirm_name' => 'superadmin'])->assertForbidden();
        $this->post('/admin/roles/'.$role->id.'/duplicate', ['name' => 'rootcopy'])->assertForbidden();
        $this->get('/admin/roles?role='.$role->id)->assertOk()->assertSee('Locked · Full access')->assertDontSee('Save changes</button>', false);
    }

    public function test_admin_cannot_delegate_missing_or_reserved_rights_via_any_path(): void
    {
        $admin = $this->user('admin');
        $this->user('manager');
        $manager = $this->role('manager');
        $this->role('admin')->revokePermissionTo('manage-settings');
        $this->actingAs($admin->fresh());
        foreach (['manage-settings', 'view-system-logs'] as $name) {
            $permission = Permission::findByName($name, 'web');
            $this->put('/admin/roles/'.$manager->id, ['permissions' => [$name]])->assertForbidden();
            $this->post('/admin/roles', ['name' => 'Escalated', 'permissions' => [$name]])->assertForbidden();
            $this->post('/admin/permissions/'.$permission->id.'/roles/'.$manager->id.'/toggle')->assertForbidden();
            $this->post('/admin/permissions/bulk', ['action' => 'assign', 'role_id' => $manager->id, 'permission_ids' => [$permission->id]])->assertForbidden();
        }
        $this->assertCount(0, $manager->fresh()->permissions);
    }

    public function test_reserved_abilities_remain_superadmin_only_even_if_assigned_in_database(): void
    {
        $admin = $this->user('admin');
        $admin->givePermissionTo(PermissionCatalog::RESERVED);
        foreach (PermissionCatalog::RESERVED as $ability) {
            $this->assertFalse($admin->fresh()->can($ability), $ability);
        }
        $this->actingAs($admin->fresh())->get('/admin/system-logs')->assertForbidden();
        $this->get('/export/system-logs')->assertForbidden();
        $this->post('/admin/permissions/seed')->assertForbidden();
    }

    public function test_public_and_customer_cannot_gain_privileged_access_or_receive_role_grants(): void
    {
        foreach (['public', 'customer'] as $name) {
            $user = $this->user($name);
            $user->givePermissionTo('view-users');
            $this->actingAs($user)->get('/users')->assertForbidden();
            $root = $this->user('superadmin');
            $this->actingAs($root)->put('/admin/roles/'.$this->role($name)->id, ['permissions' => ['view-users']])->assertForbidden();
            $this->assertFalse(PermissionCatalog::canAssignRole($root, $this->role($name)));
        }
        $admin = $this->user('admin');
        $this->assertFalse(PermissionCatalog::canAssignRole($admin, $this->role('superadmin')));
        $this->role('agent')->givePermissionTo('view-system-logs');
        $this->assertFalse(PermissionCatalog::canAssignRole($admin, $this->role('agent')));
    }

    public function test_role_deletion_requires_exact_typed_name_and_no_assigned_users(): void
    {
        $root = $this->user('superadmin');
        $member = $this->user('manager');
        $role = $this->role('manager');
        $this->actingAs($root)->delete('/admin/roles/'.$role->id, ['confirm_name' => 'manager'])->assertSessionHasErrors('confirm_name');
        $member->removeRole($role);
        $this->delete('/admin/roles/'.$role->id, ['confirm_name' => 'Manager'])->assertSessionHasErrors('confirm_name');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->delete('/admin/roles/'.$role->id, ['confirm_name' => 'manager'])->assertRedirect('/admin/roles');
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'role_deleted', 'user_id' => $root->id]);
    }

    public function test_duplicate_description_and_permission_changes_are_audited(): void
    {
        $root = $this->user('superadmin');
        $agent = $this->role('agent');
        $this->actingAs($root)->post('/admin/roles/'.$agent->id.'/duplicate', ['name' => 'Manager', 'description' => 'A limited desk'])->assertSessionHasNoErrors();
        $manager = $this->role('manager');
        $this->assertSame('A limited desk', $manager->description);
        $this->assertEqualsCanonicalizing($agent->permissions->pluck('name')->all(), $manager->permissions->pluck('name')->all());
        $this->assertDatabaseHas('activity_logs', ['action' => 'role_duplicated']);
        $this->put('/admin/roles/'.$manager->id, ['description' => 'New description', 'permissions' => ['view-reports']])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('activity_logs', ['action' => 'role_permissions_changed', 'new_value' => '["view-reports"]']);
    }

    public function test_catalog_definitions_cannot_be_renamed_deleted_or_guard_mixed(): void
    {
        $root = $this->user('superadmin');
        $permission = Permission::findByName('view-transactions', 'web');
        $this->actingAs($root)->put('/admin/permissions/'.$permission->id, ['name' => 'renamed'])->assertForbidden();
        $this->delete('/admin/permissions/'.$permission->id)->assertForbidden();
        Permission::create(['name' => 'api-only', 'guard_name' => 'api']);
        $this->post('/admin/roles', ['name' => 'ApiLeak', 'permissions' => ['api-only']])->assertSessionHasErrors('permissions.0');
        $this->assertDatabaseHas('permissions', ['name' => 'view-transactions', 'guard_name' => 'web']);
    }

    public function test_seed_post_is_audited_and_never_restores_revoked_default_grants(): void
    {
        $root = $this->user('superadmin');
        $this->role('admin')->revokePermissionTo('view-users');
        $this->actingAs($root)->post('/admin/permissions/seed')->assertSessionHasNoErrors();
        $this->assertFalse($this->role('admin')->hasPermissionTo('view-users'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'permissions_seeded', 'user_id' => $root->id]);
    }
}
