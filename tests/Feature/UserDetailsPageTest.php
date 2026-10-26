<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserDetailsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        foreach (['view-users', 'edit-user', 'export-users'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        Role::findByName('admin')->givePermissionTo(['view-users', 'edit-user', 'export-users']);
        Role::findByName('staff')->givePermissionTo('view-users');
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::create($attributes + [
            'name' => 'Desk Holder', 'email' => uniqid().'@example.test',
            'password' => 'Password123', 'is_active' => true,
        ]);
        $user->assignRole($role);
        return $user;
    }

    public function test_an_admin_opens_a_users_details_with_the_record_names(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('staff', ['country' => 'CN', 'phone' => '+8613800138000']);
        $target->forceFill(['created_by' => $admin->id, 'updated_by' => $admin->id])->saveQuietly();

        $this->actingAs($admin)
            ->get(route('users.show', $target))
            ->assertOk()
            ->assertSee($target->name)
            ->assertSee($target->email)
            ->assertSee($target->phone)
            ->assertSee('China')
            ->assertSee('#' . $target->id)
            ->assertSee('Staff')
            ->assertSee($admin->name)
            ->assertSee('Edit User');
    }

    public function test_a_viewer_without_edit_rights_sees_details_but_no_edit_button(): void
    {
        $viewer = $this->user('staff');
        $target = $this->user('staff');

        $this->actingAs($viewer)
            ->get(route('users.show', $target))
            ->assertOk()
            ->assertSee($target->email)
            ->assertDontSee('Edit User');
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('users.show', $this->user('staff')))
            ->assertRedirect(route('login'));
    }

    public function test_the_users_export_names_who_added_and_edited_each_account(): void
    {
        $admin = $this->user('admin');
        $editor = $this->user('admin');
        $target = $this->user('staff');
        $target->forceFill(['created_by' => $admin->id, 'updated_by' => $editor->id])->saveQuietly();

        $response = $this->actingAs($admin)->get(route('export.users'));
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'pluspay-') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $header = $rows[4];
        $this->assertSame('Added By', $header[10]);
        $this->assertSame('Edited By', $header[11]);

        $targetRow = collect($rows)->first(fn($row) => ($row[1] ?? null) === $target->email);
        $this->assertNotNull($targetRow);
        $this->assertSame($admin->name, $targetRow[10]);
        $this->assertSame($editor->name, $targetRow[11]);
    }

    public function test_the_summary_cards_count_each_desk_and_link_to_the_filter(): void
    {
        $admin = $this->user('admin');
        $this->user('agent', ['country' => 'UG']);
        $this->user('agent', ['country' => 'UG']);
        $this->user('agent', ['country' => 'CN']);
        $this->user('accountant', ['country' => 'UG']);

        $html = $this->actingAs($admin)->get(route('users.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Total users', $html);
        $this->assertStringContainsString('Uganda agents', $html);
        $this->assertStringContainsString('China agents', $html);
        $this->assertStringContainsString('Accountants', $html);
        $this->assertStringContainsString(e(route('users.index', ['role' => 'agent', 'country' => 'UG'])), $html);
        $this->assertStringContainsString(e(route('users.index', ['role' => 'agent', 'country' => 'CN'])), $html);
        $this->assertStringContainsString(e(route('users.index', ['role' => 'accountant'])), $html);

        $filtered = $this->actingAs($admin)->get(route('users.index', ['role' => 'agent', 'country' => 'UG']));
        $this->assertStringContainsString('2 accounts', $filtered->getContent());
    }

    public function test_the_users_table_links_each_name_to_the_details_page(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('staff');

        $this->actingAs($admin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee(route('users.show', $target));
    }
}
