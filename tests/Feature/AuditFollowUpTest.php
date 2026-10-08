<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuditFollowUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' User',
            'email' => $role . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function transaction(User $creator): Transaction
    {
        return Transaction::create([
            'receipt_number' => 'AUD-001',
            'sender_name' => 'Sender One',
            'sender_contact' => '0700000001',
            'receiver_name' => 'Receiver One',
            'receiver_contact' => '8613800000001',
            'currency' => 'USD',
            'amount' => 100,
            'exchange_rate' => 6.75,
            'rmb_amount' => 675,
            'charge_rate' => 0.02,
            'charge_amount' => 2,
            'charge_ugx' => 7520,
            'status' => 'completed',
            'created_by' => $creator->id,
        ]);
    }

    public function test_login_logs_show_the_ip_in_its_own_column(): void
    {
        $admin = $this->user('admin');

        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'login',
            'description' => 'User Admin User logged in from 41.210.157.79',
        ]);

        $this->actingAs($admin)->get('/admin/login-logs')
            ->assertOk()
            ->assertSee('IP Address')
            ->assertSee('41.210.157.79');
    }

    public function test_login_logs_prefer_the_recorded_ip_column(): void
    {
        $admin = $this->user('admin');

        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'login',
            'description' => 'User Admin User logged in',
            'ip_address' => '102.85.222.179',
        ]);

        $this->actingAs($admin)->get('/admin/login-logs')
            ->assertOk()
            ->assertSee('102.85.222.179');
    }

    public function test_the_logs_can_be_searched_by_ip_address(): void
    {
        $admin = $this->user('admin');

        ActivityLog::create([
            'user_id' => $admin->id,
            'action' => 'login',
            'description' => 'User Admin User logged in',
            'ip_address' => '102.85.222.179',
        ]);

        $this->actingAs($admin)->get('/admin/login-logs?search=102.85.222')
            ->assertOk()
            ->assertDontSee('No login logs found.');

        $this->actingAs($admin)->get('/admin/login-logs?search=8.8.8.8')
            ->assertOk()
            ->assertSee('No login logs found.');
    }

    public function test_the_export_summary_describes_each_kind_of_change(): void
    {
        $items = new ActivityLog([
            'action' => 'role_permissions_changed',
            'old_value' => json_encode(['view-transactions', 'edit-customer']),
            'new_value' => json_encode(['view-transactions', 'create-transaction']),
        ]);
        $this->assertSame('+ create-transaction | - edit-customer', $items->changeText());

        $fields = new ActivityLog([
            'action' => 'user_updated',
            'old_value' => json_encode(['name' => 'Bob', 'email' => 'bob@old.test']),
            'new_value' => json_encode(['name' => 'Bob', 'email' => 'bob@new.test']),
        ]);
        $this->assertSame('Email: bob@old.test -> bob@new.test', $fields->changeText());

        $scalar = new ActivityLog([
            'action' => 'exchange_rate_set',
            'old_value' => '6.75',
            'new_value' => '6.78',
        ]);
        $this->assertSame('was: 6.75 | now: 6.78', $scalar->changeText());

        $nothing = new ActivityLog(['action' => 'login', 'description' => 'logged in']);
        $this->assertSame('', $nothing->changeText());
    }

    public function test_editing_a_transaction_records_who_made_the_edit(): void
    {
        $creator = $this->user('agent');
        $editor = $this->user('admin');
        $transaction = $this->transaction($creator);

        $this->assertNull($transaction->updated_by);

        $this->actingAs($editor);
        $transaction->notes = 'Amount confirmed with the China office.';
        $transaction->save();

        $this->assertSame($editor->id, $transaction->fresh()->updated_by);
    }

    public function test_the_transaction_page_names_the_creator_and_the_last_editor(): void
    {
        $creator = $this->user('agent');
        $editor = $this->user('admin');
        $transaction = $this->transaction($creator);

        $this->actingAs($editor);
        $transaction->notes = 'Amount confirmed with the China office.';
        $transaction->save();

        $this->actingAs($editor)->get(route('transactions.show', $transaction))
            ->assertOk()
            ->assertSee('Created by')
            ->assertSee($creator->name)
            ->assertSee('Last updated on')
            ->assertSee($editor->name);
    }
}
