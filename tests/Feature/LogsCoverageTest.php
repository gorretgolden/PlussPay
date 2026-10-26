<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ExchangeRate;
use App\Models\LoginLog;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The handful of behaviours the Admin Logs brief called out explicitly: a rate change
 * keeps its before and after, sign-ins write the login table, an uncaught exception
 * reaches the error table, and the three roles see exactly the tabs they should.
 */
class LogsCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function user(string $role): User
    {
        $user = User::create([
            'name' => ucfirst($role) . ' Person',
            'email' => strtolower($role) . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    public function test_a_rate_change_is_audited_with_its_old_and_new_values(): void
    {
        ExchangeRate::setPair('USD', 'UGX', 4000, 3600, 4000);

        $this->actingAs($this->user('admin'))->put('/settings', [
            'usd_ugx_selling' => '4100',
        ])->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', 'settings_updated')->first();

        $this->assertNotNull($log, 'A selling rate change must be audited.');

        $old = json_decode($log->old_value, true);
        $new = json_decode($log->new_value, true);

        $this->assertArrayHasKey('usd_ugx_selling', $old);
        $this->assertArrayHasKey('usd_ugx_selling', $new);
        $this->assertEqualsWithDelta(4000, (float) $old['usd_ugx_selling'], 0.000001);
        $this->assertEqualsWithDelta(4100, (float) $new['usd_ugx_selling'], 0.000001);
    }

    public function test_a_password_is_never_written_into_an_audit_row(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->put('/settings', ['company_name' => 'PlusPay'])
            ->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', 'settings_updated')->first();

        $this->assertStringNotContainsString('Password123', (string) $log->old_value);
        $this->assertStringNotContainsString('Password123', (string) $log->new_value);
        $this->assertStringNotContainsString('password', strtolower((string) $log->old_value));
    }

    public function test_a_failed_sign_in_writes_a_login_log_row(): void
    {
        $user = $this->user('agent');

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'WrongPassword1']);

        $row = LoginLog::where('status', 'failed')->where('user_id', $user->id)->first();

        $this->assertNotNull($row);
        $this->assertSame($user->email, $row->email_attempted);
    }

    public function test_a_successful_sign_in_writes_a_login_log_row(): void
    {
        $user = $this->user('admin');

        $this->post(route('login.post'), ['email' => $user->email, 'password' => 'Password123'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->assertSame(1, LoginLog::where('status', 'success')->where('user_id', $user->id)->count());
    }

    public function test_a_sign_out_is_written_and_the_two_outcomes_are_kept_apart(): void
    {
        $user = $this->user('admin');

        $this->post(route('login.post'), ['email' => $user->email, 'password' => 'Password123']);
        $this->actingAs($user)->post('/logout');

        $this->assertSame(1, LoginLog::where('status', 'success')->where('user_id', $user->id)->count());
        $this->assertSame(1, LoginLog::where('status', 'logout')->where('user_id', $user->id)->count());
    }

    public function test_five_failed_attempts_from_one_ip_inside_the_window_is_flagged(): void
    {
        $user = $this->user('agent');

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => $user->email, 'password' => 'WrongPassword1']);
        }

        $suspicious = LoginLog::suspiciousSince();

        $this->assertNotEmpty($suspicious['emails'], 'The account being retried should surface.');
    }

    public function test_an_uncaught_exception_is_saved_to_the_error_table(): void
    {
        report(new \RuntimeException('Disk unreadable'));

        $row = SystemLog::where('message', 'Disk unreadable')->first();

        $this->assertNotNull($row, 'The reportable hook must persist the exception.');
        $this->assertSame('RuntimeException', $row->exception_class);
        $this->assertSame('error', $row->level);
        $this->assertFalse($row->resolved);
    }

    public function test_an_agent_cannot_open_any_logs_page_or_export(): void
    {
        $agent = $this->user('agent');

        $this->actingAs($agent)->get('/admin/audit-logs?tab=activity')->assertForbidden();
        $this->actingAs($agent)->get('/admin/audit-logs?tab=login')->assertForbidden();
        $this->actingAs($agent)->get('/admin/system-logs')->assertForbidden();
        $this->actingAs($agent)->get('/export/activity-logs')->assertForbidden();
        $this->actingAs($agent)->get('/export/login-logs')->assertForbidden();
        $this->actingAs($agent)->get('/export/system-logs')->assertForbidden();
    }

    public function test_an_admin_reads_the_audit_and_login_logs_but_not_the_errors(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get('/admin/audit-logs?tab=activity')->assertOk();
        $this->actingAs($admin)->get('/admin/audit-logs?tab=login')->assertOk();

        $this->actingAs($admin)->get('/admin/system-logs')->assertForbidden();
    }

    public function test_a_super_admin_reads_every_logs_tab(): void
    {
        $boss = $this->user('superadmin');

        $this->actingAs($boss)->get('/admin/audit-logs?tab=activity')->assertOk();
        $this->actingAs($boss)->get('/admin/audit-logs?tab=login')->assertOk();
        $this->actingAs($boss)->get('/admin/system-logs')->assertOk();
    }
}
