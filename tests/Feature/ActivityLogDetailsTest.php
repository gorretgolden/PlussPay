<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActivityLogDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        foreach (['view-transactions', 'create-transaction', 'edit-customer'] as $permission) {
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

    public function test_the_activity_log_list_offers_details_for_every_row(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->post('/admin/roles', ['name' => 'Supervisor', 'permissions' => ['view-transactions']]);

        $this->actingAs($admin)->get('/admin/audit-logs?tab=activity')
            ->assertOk()
            ->assertSee('data-log-details', false);
    }

    public function test_ticked_rows_can_be_exported_as_their_own_file(): void
    {
        $admin = $this->superadmin();

        $keep = ActivityLog::create(['action' => 'login', 'description' => 'Kept entry one']);
        $keepTwo = ActivityLog::create(['action' => 'login', 'description' => 'Kept entry two']);
        ActivityLog::create(['action' => 'login', 'description' => 'Left behind']);

        $response = $this->actingAs($admin)
            ->post('/export/activity-logs/selected', ['ids' => "{$keep->id},{$keepTwo->id},999999"]);

        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'pluspay-') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);

        $descriptions = array_column($rows, 5);

        $this->assertContains('Kept entry one', $descriptions);
        $this->assertContains('Kept entry two', $descriptions);
        $this->assertNotContains('Left behind', $descriptions);
        $this->assertSame('Total records', $rows[7][0]);
        $this->assertSame('2', $rows[7][1]);
    }

    public function test_details_show_which_permissions_were_added_and_which_removed(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->post('/admin/roles', [
            'name' => 'Supervisor',
            'permissions' => ['view-transactions', 'edit-customer'],
        ]);

        $role = Role::where('name', 'supervisor')->firstOrFail();

        $this->actingAs($admin)->put("/admin/roles/{$role->id}", [
            'permissions' => ['create-transaction', 'view-transactions'],
        ]);

        $log = ActivityLog::where('action', 'role_permissions_changed')->latest('id')->firstOrFail();

        $this->actingAs($admin)->get("/admin/activity-logs/{$log->id}/details")
            ->assertOk()
            ->assertSee('Added (1)')
            ->assertSee('create-transaction')
            ->assertSee('Removed (1)')
            ->assertSee('edit-customer')
            ->assertSee('Unchanged (1): view-transactions', false);
    }

    public function test_details_show_the_whole_list_for_a_newly_created_role(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin)->post('/admin/roles', [
            'name' => 'Supervisor',
            'permissions' => ['view-transactions', 'edit-customer'],
        ]);

        $log = ActivityLog::where('action', 'role_created')->latest('id')->firstOrFail();

        $this->actingAs($admin)->get("/admin/activity-logs/{$log->id}/details")
            ->assertOk()
            ->assertSee('Added (2)')
            ->assertSee('view-transactions')
            ->assertSee('edit-customer');
    }

    public function test_details_show_before_and_after_for_a_single_value_change(): void
    {
        $admin = $this->superadmin();

        $log = ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'role_changed',
            'description' => 'Role changed from staff to agent for user Bob',
            'old_value' => 'staff',
            'new_value' => 'agent',
        ]);

        $this->actingAs($admin)->get("/admin/activity-logs/{$log->id}/details")
            ->assertOk()
            ->assertSee('Was')
            ->assertSee('staff')
            ->assertSee('Now')
            ->assertSee('agent');
    }

    public function test_details_show_a_field_by_field_diff_for_a_user_edit(): void
    {
        $admin = $this->superadmin();

        $log = ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'user_updated',
            'description' => 'User Bob updated',
            'old_value' => json_encode([
                'name' => 'Bob', 'email' => 'bob@old.test', 'phone' => null, 'is_active' => true,
            ]),
            'new_value' => json_encode([
                'name' => 'Bob', 'email' => 'bob@new.test', 'phone' => null, 'is_active' => false,
            ]),
        ]);

        $this->actingAs($admin)->get("/admin/activity-logs/{$log->id}/details")
            ->assertOk()
            ->assertSee('Email')
            ->assertSee('bob@old.test')
            ->assertSee('bob@new.test')
            ->assertSee('Greyed rows were left as they were.')
            ->assertSee('Name');
    }

    public function test_details_say_so_when_an_entry_recorded_no_values(): void
    {
        $admin = $this->superadmin();

        $log = ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'login',
            'description' => 'User Root logged in from 41.210.157.79',
        ]);

        $this->actingAs($admin)->get("/admin/activity-logs/{$log->id}/details")
            ->assertOk()
            ->assertSee('No before and after values were recorded for this entry.', false);
    }

    public function test_new_entries_record_the_ip_and_the_form_behind_them(): void
    {
        $this->actingAs($this->superadmin())->post('/admin/roles', ['name' => 'Supervisor']);

        $log = ActivityLog::latest('id')->firstOrFail();

        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame('POST admin/roles', $log->request_path);
    }

    public function test_entries_written_outside_a_request_claim_no_page(): void
    {
        $log = ActivityLog::create(['action' => 'bulk_import', 'description' => 'Imported 12 rows']);

        $this->assertNull($log->ip_address);
        $this->assertNull($log->request_path);
    }

    public function test_staff_cannot_open_the_details_endpoint(): void
    {
        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $staff->assignRole('staff');

        $log = ActivityLog::create(['action' => 'login', 'description' => 'someone logged in']);

        $this->actingAs($staff)->get("/admin/activity-logs/{$log->id}/details")->assertForbidden();
    }
}
