<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        foreach (['view-transactions', 'create-transaction', 'edit-customer', 'delete-customer'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    private function superadmin(): User
    {
        $user = User::create([
            'name' => 'Root',
            'email' => 'root' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('superadmin');

        return $user;
    }

    public function test_roles_page_offers_a_permission_search_box(): void
    {
        $response = $this->actingAs($this->superadmin())->get('/admin/roles');

        $response->assertOk()
            ->assertSee('Search permissions', false);
    }

    public function test_creating_a_role_assigns_its_permissions_in_one_step(): void
    {
        $response = $this->actingAs($this->superadmin())->post('/admin/roles', [
            'name' => 'Supervisor',
            'permissions' => ['view-transactions', 'edit-customer'],
        ]);

        $response->assertSessionHasNoErrors();

        $role = Role::where('name', 'supervisor')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['view-transactions', 'edit-customer'],
            $role->permissions->pluck('name')->all()
        );
    }

    public function test_creating_a_role_without_permissions_still_works(): void
    {
        $this->actingAs($this->superadmin())->post('/admin/roles', ['name' => 'Empty']);

        $this->assertCount(0, Role::where('name', 'empty')->firstOrFail()->permissions);
    }

    public function test_duplicate_role_name_is_rejected_even_with_different_casing(): void
    {
        $this->actingAs($this->superadmin())->post('/admin/roles', [
            'name' => 'Team Lead',
            'permissions' => ['view-transactions'],
        ]);

        $response = $this->actingAs($this->superadmin())->post('/admin/roles', [
            'name' => 'team lead',
            'permissions' => ['create-transaction', 'view-transactions'],
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertEquals(1, Role::where('name', 'teamlead')->count());
    }

    public function test_duplicate_role_name_keeps_the_ticked_permissions(): void
    {
        $this->actingAs($this->superadmin())->post('/admin/roles', [
            'name' => 'Supervisor',
            'permissions' => ['view-transactions'],
        ]);

        $response = $this->actingAs($this->superadmin())->post('/admin/roles', [
            'name' => 'Supervisor',
            'permissions' => ['create-transaction', 'view-transactions'],
        ]);

        $response->assertSessionHasErrors('name');
        $response->assertRedirect();
        $this->assertEqualsCanonicalizing(
            ['create-transaction', 'view-transactions'],
            session()->getOldInput('permissions')
        );
    }

    public function test_add_role_form_is_hidden_behind_a_button_until_needed(): void
    {
        $this->actingAs($this->superadmin())->get('/admin/roles')
            ->assertSee('Add Role')
            ->assertSee('id="addRoleModal" class="fixed inset-0 z-50 hidden', false);
    }

    public function test_a_rejected_role_name_reopens_the_modal_and_keeps_the_ticks(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post('/admin/roles', ['name' => 'Supervisor', 'permissions' => ['view-transactions']]);
        $this->actingAs($admin)->post('/admin/roles', ['name' => 'Supervisor', 'permissions' => ['view-transactions']]);

        $this->actingAs($admin)->get('/admin/roles')
            ->assertSee('A role with this name already exists.', false)
            ->assertDontSee('id="addRoleModal" class="fixed inset-0 z-50 hidden', false)
            ->assertSee('value="Supervisor"', false);
    }

    public function test_bulk_add_assigns_every_selected_permission_to_the_role(): void
    {
        $role = Role::where('name', 'staff')->firstOrFail();
        $ids = Permission::whereIn('name', ['view-transactions', 'edit-customer'])->pluck('id')->all();

        $this->actingAs($this->superadmin())->post('/admin/permissions/bulk', [
            'action' => 'assign',
            'role_id' => $role->id,
            'permission_ids' => $ids,
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(
            ['view-transactions', 'edit-customer'],
            $role->fresh()->permissions->pluck('name')->all()
        );
    }

    public function test_bulk_remove_takes_only_the_selected_permissions_away(): void
    {
        $role = Role::where('name', 'staff')->firstOrFail();
        $role->syncPermissions(['view-transactions', 'create-transaction']);

        $this->actingAs($this->superadmin())->post('/admin/permissions/bulk', [
            'action' => 'remove',
            'role_id' => $role->id,
            'permission_ids' => [Permission::where('name', 'view-transactions')->value('id')],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(['create-transaction'], $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_bulk_delete_removes_free_permissions_and_skips_assigned_ones(): void
    {
        $role = Role::where('name', 'staff')->firstOrFail();
        $role->syncPermissions(['view-transactions']);

        $assigned = Permission::where('name', 'view-transactions')->value('id');
        $free = Permission::whereIn('name', ['create-transaction', 'edit-customer'])->pluck('id')->all();

        $this->actingAs($this->superadmin())->post('/admin/permissions/bulk', [
            'action' => 'delete',
            'permission_ids' => array_merge([$assigned], $free),
        ])->assertSessionHasErrors('error');

        $this->assertDatabaseHas('permissions', ['id' => $assigned]);
        $this->assertDatabaseMissing('permissions', ['id' => $free[0]]);
        $this->assertDatabaseMissing('permissions', ['id' => $free[1]]);
    }

    public function test_admin_cannot_bulk_change_superadmin_permissions(): void
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');

        $superadmin = Role::where('name', 'superadmin')->firstOrFail();
        $superadmin->syncPermissions(['view-transactions']);

        $this->actingAs($admin)->post('/admin/permissions/bulk', [
            'action' => 'remove',
            'role_id' => $superadmin->id,
            'permission_ids' => [Permission::where('name', 'view-transactions')->value('id')],
        ])->assertSessionHasErrors('error');

        $this->assertTrue($superadmin->hasPermissionTo('view-transactions'));
    }

    public function test_saving_a_role_with_a_long_permission_list_writes_the_whole_audit_entry(): void
    {
        $names = $this->manyPermissions(25);
        $role = Role::where('name', 'staff')->firstOrFail();

        $this->actingAs($this->superadmin())
            ->put("/admin/roles/{$role->id}", ['permissions' => $names])
            ->assertSessionHasNoErrors();

        $entry = ActivityLog::where('action', 'role_permissions_changed')->latest('id')->firstOrFail();

        $this->assertGreaterThan(255, strlen($entry->new_value));
        $this->assertCount(25, json_decode($entry->new_value, true));
    }

    public function test_creating_a_role_with_a_long_permission_list_writes_the_whole_audit_entry(): void
    {
        $names = $this->manyPermissions(25);

        $this->actingAs($this->superadmin())
            ->post('/admin/roles', ['name' => 'Reconciliations', 'permissions' => $names])
            ->assertSessionHasNoErrors();

        $entry = ActivityLog::where('action', 'role_created')->latest('id')->firstOrFail();

        $this->assertGreaterThan(255, strlen($entry->new_value));
        $this->assertCount(25, json_decode($entry->new_value, true));
    }

    public function test_activity_log_value_columns_are_text_not_varchar(): void
    {
        $columns = collect(DB::getSchemaBuilder()->getColumns('activity_logs'))->keyBy('name');

        foreach (['old_value', 'new_value'] as $column) {
            $this->assertSame(
                'text',
                $columns[$column]['type'] ?? null,
                "activity_logs.{$column} must be text: a JSON permission list overflows a 255 character column on MySQL."
            );
        }
    }

    /** @return array<int, string> */
    private function manyPermissions(int $count): array
    {
        $names = [];

        for ($i = 1; $i <= $count; $i++) {
            $name = sprintf('manage-reconciliation-record-%02d', $i);
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $names[] = $name;
        }

        return $names;
    }
}
