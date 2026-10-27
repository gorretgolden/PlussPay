<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionCatalogCrudTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);
        return $user;
    }

    private function role(string $name): Role
    {
        return Role::findByName($name, 'web');
    }

    public function test_a_custom_ability_is_added_with_a_slug_and_description(): void
    {
        $root = $this->user('superadmin');
        $this->actingAs($root)->post('/admin/permissions', [
            'name' => 'Approve Payout',
            'description' => 'Clears the withdrawal queue in China.',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('permissions', [
            'name' => 'approve-payout',
            'guard_name' => 'web',
            'description' => 'Clears the withdrawal queue in China.',
        ]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'permission_created', 'user_id' => $root->id]);
    }

    public function test_duplicate_and_unusable_names_are_rejected(): void
    {
        $root = $this->user('superadmin');
        $before = Permission::count();
        $this->actingAs($root)->post('/admin/permissions', ['name' => 'view-transactions'])
            ->assertSessionHasErrors('name');
        $this->post('/admin/permissions', ['name' => '---'])->assertSessionHasErrors('name');
        $this->assertSame($before, Permission::count());
    }

    public function test_an_admin_can_add_a_custom_ability_and_attach_it_to_a_role(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/admin/permissions', [
            'name' => 'review-refunds',
            'description' => 'Checks a refund before it is booked.',
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/roles', ['name' => 'Refunds Desk'])
            ->assertSessionHasNoErrors();
        $role = $this->role('refunds-desk');
        $this->actingAs($admin)->put('/admin/roles/'.$role->id, ['permissions' => ['review-refunds']])
            ->assertSessionHasNoErrors();

        $this->assertTrue($role->fresh()->hasPermissionTo('review-refunds'));
    }

    public function test_built_in_abilities_keep_their_slug_and_cannot_be_deleted_but_their_description_is_editable(): void
    {
        $root = $this->user('superadmin');
        $permission = Permission::findByName('view-transactions', 'web');

        $this->actingAs($root)->put('/admin/permissions/'.$permission->id, ['name' => 'renamed'])->assertForbidden();
        $this->delete('/admin/permissions/'.$permission->id)->assertForbidden();

        $this->actingAs($root)->put('/admin/permissions/'.$permission->id, [
            'name' => 'view-transactions', 'description' => 'Reads the book, including profit.',
        ])->assertRedirect('/admin/permissions');
        $this->assertSame('Reads the book, including profit.', $permission->fresh()->description);
        $this->assertDatabaseHas('activity_logs', ['action' => 'permission_updated', 'new_value' => 'view-transactions']);
    }

    public function test_a_custom_ability_in_use_cannot_be_deleted_but_a_free_one_can(): void
    {
        $root = $this->user('superadmin');
        $used = Permission::create(['name' => 'review-refunds', 'guard_name' => 'web']);
        $free = Permission::create(['name' => 'audit-refunds', 'guard_name' => 'web']);
        $this->role('admin')->givePermissionTo($used);

        $this->actingAs($root)->delete('/admin/permissions/'.$used->id)->assertSessionHasErrors('permission');
        $this->assertDatabaseHas('permissions', ['id' => $used->id]);

        $this->delete('/admin/permissions/'.$free->id)->assertRedirect('/admin/permissions');
        $this->assertDatabaseMissing('permissions', ['id' => $free->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'permission_deleted', 'old_value' => 'audit-refunds']);
    }

    public function test_the_catalog_searches_filters_and_paginates(): void
    {
        $root = $this->user('superadmin');
        for ($i = 1; $i <= 24; $i++) {
            Permission::create(['name' => 'sample-ability-'.$i, 'guard_name' => 'web']);
        }

        $first = $this->actingAs($root)->get('/admin/permissions');
        $first->assertOk()->assertViewHas('permissions', fn ($permissions) => $permissions->count() === 20
            && $permissions->lastPage() > 1);

        $this->get('/admin/permissions?page=2')->assertOk()
            ->assertViewHas('permissions', fn ($permissions) => $permissions->count() > 0
                && $permissions->currentPage() === 2);

        $this->get('/admin/permissions?q=sample-ability-2')->assertOk()
            ->assertViewHas('permissions', fn ($permissions) => $permissions->total() === 6);
    }

    public function test_the_edit_card_opens_from_the_row_action(): void
    {
        $root = $this->user('superadmin');
        $permission = Permission::findByName('view-transactions', 'web');

        $this->actingAs($root)->get('/admin/permissions?edit='.$permission->id)
            ->assertOk()
            ->assertSee('Edit View transactions', false)
            ->assertSee('readonly', false);
    }

    public function test_people_without_the_manage_ability_see_a_read_only_catalog(): void
    {
        $reader = User::factory()->create(['is_active' => true]);
        $reader->assignRole(Role::create(['name' => 'auditor', 'guard_name' => 'web']));
        $this->role('auditor')->givePermissionTo('view-roles');

        $this->actingAs($reader)->get('/admin/permissions')
            ->assertOk()
            ->assertDontSee('Add</button>', false)
            ->assertDontSee('name="name"', false);

        $this->post('/admin/permissions', ['name' => 'sneaky-ability'])->assertForbidden();
    }

    public function test_agents_cannot_open_the_catalog(): void
    {
        $this->actingAs($this->user('agent'))->get('/admin/permissions')->assertForbidden();
    }
}
