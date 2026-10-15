<?php

namespace Tests\Feature;

use App\Http\Controllers\RolePermissionController;
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
        $this->assertEquals(1, Role::where('name', 'team-lead')->count());
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

    public function test_roles_switch_through_a_dropdown_and_only_get_a_search_once_there_are_many(): void
    {
        $this->actingAs($this->superadmin())->get('/admin/roles')
            ->assertOk()
            ->assertSee('id="roleSelect"', false)
            ->assertDontSee('Search roles by name', false);

        for ($desk = 1; $desk <= 6; $desk++) {
            $this->actingAs($this->superadmin())->post('/admin/roles', ['name' => 'Desk '.$desk]);
        }

        $this->actingAs($this->superadmin())->get('/admin/roles')
            ->assertOk()
            ->assertSee('Search roles by name', false)
            ->assertSee('id="roleSelect"', false);
    }

    public function test_role_counts_read_as_english_and_not_with_a_parenthesised_s(): void
    {
        $this->actingAs($this->superadmin())->post('/admin/roles', [
            'name' => 'Desk Lead',
            'permissions' => ['view-transactions'],
        ]);
        $this->actingAs($this->superadmin())->post('/admin/roles', ['name' => 'Settlements']);

        $this->actingAs($this->superadmin())->get('/admin/roles')
            ->assertSee('1 permission<', false)
            ->assertSee('0 permissions<', false)
            ->assertDontSee('permission(s)', false)
            ->assertDontSee('role(s)', false)
            ->assertSee('Desk Lead');
    }

    public function test_a_role_can_be_renamed_and_the_people_on_it_keep_their_access(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->post('/admin/roles', ['name' => 'Supervisor', 'permissions' => ['view-transactions']]);
        $role = Role::where('name', 'supervisor')->firstOrFail();
        $person = User::create([
            'name' => 'Desk Lead',
            'email' => 'lead'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $person->assignRole($role);

        $this->actingAs($admin)->patch("/admin/roles/{$role->id}/rename", ['name' => 'Desk Lead'])
            ->assertSessionHasNoErrors();

        $renamed = Role::where('name', 'desk-lead')->firstOrFail();
        $this->assertSame($role->id, $renamed->id);
        $this->assertTrue($person->fresh()->hasRole('desk-lead'));
        $this->assertEquals(['view-transactions'], $renamed->permissions->pluck('name')->all());
        $this->assertDatabaseHas('activity_logs', ['action' => 'role_renamed', 'new_value' => json_encode(['desk-lead'])]);
        $this->assertSame('Desk Lead', RolePermissionController::displayName($renamed->name));
    }

    public function test_a_rename_that_collides_with_another_role_is_rejected(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->post('/admin/roles', ['name' => 'Supervisor']);
        $this->actingAs($admin)->post('/admin/roles', ['name' => 'Auditor']);
        $role = Role::where('name', 'supervisor')->firstOrFail();

        $this->actingAs($admin)->patch("/admin/roles/{$role->id}/rename", ['name' => 'Auditor'])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'supervisor']);
    }

    public function test_built_in_roles_keep_their_names(): void
    {
        $role = Role::where('name', 'admin')->firstOrFail();

        $this->actingAs($this->superadmin())->patch("/admin/roles/{$role->id}/rename", ['name' => 'Operations'])
            ->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'admin']);
    }

    public function test_nobody_can_strip_the_roles_page_off_their_own_role(): void
    {
        $role = Role::where('name', 'admin')->firstOrFail();
        $role->syncPermissions(['view-roles', 'edit-role', 'assign-permissions', 'view-transactions']);
        $admin = User::create([
            'name' => 'Office Admin',
            'email' => 'office'.uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $admin->assignRole($role);

        $this->actingAs($admin)->put("/admin/roles/{$role->id}", [
            'permissions' => ['view-transactions'],
        ])->assertSessionHasErrors('permissions');

        $this->assertEqualsCanonicalizing(
            ['view-roles', 'edit-role', 'assign-permissions', 'view-transactions'],
            $role->fresh()->permissions->pluck('name')->all()
        );

        // The same trap through the bulk tool, and on another role, is still allowed.
        $other = Role::where('name', 'staff')->firstOrFail();
        $other->syncPermissions(['view-roles', 'edit-role', 'assign-permissions']);

        $this->actingAs($admin)->post('/admin/permissions/bulk', [
            'action' => 'remove',
            'role_id' => $other->id,
            'permission_ids' => [Permission::where('name', 'view-roles')->value('id')],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['edit-role', 'assign-permissions'], $other->fresh()->permissions->pluck('name')->all());
    }

    public function test_bulk_add_assigns_every_selected_permission_to_the_role(): void
    {
        $role = Role::where('name', 'staff')->firstOrFail();
        // The staff role now carries a real grant from the catalog, so start this case from
        // empty the way the bulk remove test does, and assert only what this request added.
        $role->syncPermissions([]);
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

    public function test_bulk_delete_cannot_destroy_application_permission_definitions(): void
    {
        $role = Role::where('name', 'staff')->firstOrFail();
        $role->syncPermissions(['view-transactions']);

        $assigned = Permission::where('name', 'view-transactions')->value('id');
        $free = Permission::whereIn('name', ['create-transaction', 'edit-customer'])->pluck('id')->all();

        $this->actingAs($this->superadmin())->post('/admin/permissions/bulk', [
            'action' => 'delete',
            'permission_ids' => array_merge([$assigned], $free),
        ])->assertForbidden();

        $this->assertDatabaseHas('permissions', ['id' => $assigned]);
        $this->assertDatabaseHas('permissions', ['id' => $free[0]]);
        $this->assertDatabaseHas('permissions', ['id' => $free[1]]);
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
        ])->assertForbidden();

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
