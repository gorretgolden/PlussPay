<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FailedLoginAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function user(string $role = 'admin', string $name = 'Gorret Admin'): User
    {
        $user = User::create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)) . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function attempt(string $email, string $password): void
    {
        $this->post('/admin/login', ['email' => $email, 'password' => $password]);
    }

    public function test_a_wrong_password_leaves_a_row_on_the_login_logs_page(): void
    {
        $user = $this->user();

        $this->attempt($user->email, 'WrongPassword1');

        $log = ActivityLog::where('action', 'login_failed')->first();

        $this->assertNotNull($log, 'A wrong password must be written to the audit trail.');
        $this->assertSame($user->id, $log->user_id);
        $this->assertStringContainsString('wrong password', $log->description);

        $this->actingAs($this->user('superadmin', 'Log Viewer'))
            ->get('/admin/login-logs')
            ->assertOk()
            ->assertSee('Login failed')
            ->assertSee('wrong password');
    }

    public function test_an_email_that_belongs_to_nobody_is_still_recorded(): void
    {
        $this->attempt('stranger@plussave.test', 'Whatever123');

        $log = ActivityLog::where('action', 'login_failed')->first();

        $this->assertNotNull($log);
        $this->assertNull($log->user_id, 'There is no account to point at, but the attempt still matters.');
        $this->assertStringContainsString('stranger@plussave.test', $log->description);
        $this->assertStringContainsString('no account with that email', $log->description);
    }

    public function test_the_lockout_still_happens_and_both_events_are_kept(): void
    {
        $user = $this->user();

        for ($i = 0; $i < 5; $i++) {
            $this->attempt($user->email, 'WrongPassword1');
        }

        $this->assertSame(5, ActivityLog::where('action', 'login_failed')->where('user_id', $user->id)->count());
        $this->assertNotNull(ActivityLog::where('action', 'login_blocked')->where('user_id', $user->id)->first());
        $this->assertTrue($user->fresh()->isLocked());
    }

    public function test_attempts_while_locked_are_recorded_too(): void
    {
        $user = $this->user();
        $user->forceFill(['locked_until' => now()->addMinutes(15)])->save();

        $this->attempt($user->email, 'Password123');

        $log = ActivityLog::where('action', 'login_failed')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('account locked', $log->description);
    }

    public function test_a_wrong_two_factor_code_is_recorded(): void
    {
        $user = $this->user();
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => TotpService::generateSecret(),
        ])->save();

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'Password123'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->post('/two-factor', ['code' => '000000']);

        $log = ActivityLog::where('action', 'login_failed')->latest('id')->first();

        $this->assertNotNull($log);
        $this->assertStringContainsString('two-factor code wrong', $log->description);
    }

    public function test_failed_attempts_carry_the_export(): void
    {
        $user = $this->user();

        $this->attempt($user->email, 'WrongPassword1');

        $viewer = $this->user('superadmin', 'Log Viewer');

        $response = $this->actingAs($viewer)->get('/export/login-logs');
        $response->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'pluspay-') . '.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        $this->assertSame('Login failed', $sheet->getCell('D6')->getValue());
        $this->assertStringContainsString('wrong password', (string) $sheet->getCell('F6')->getValue());
    }

    public function test_the_failed_attempt_filter_narrows_the_page(): void
    {
        $user = $this->user();

        $this->attempt($user->email, 'WrongPassword1');
        $this->actingAs($user)->get('/dashboard')->assertOk();
        $this->post('/logout');

        $viewer = $this->user('superadmin', 'Log Viewer');

        $response = $this->actingAs($viewer)->get('/admin/login-logs?action=login_failed');

        $response->assertOk();
        $this->assertSame(1, $response->viewData('logs')->total());
    }
}
