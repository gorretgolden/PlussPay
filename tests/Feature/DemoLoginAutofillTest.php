<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RoleAndUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The testing team works on the live sign-in page, so the demo buttons have to carry a
 * password there. These tests hold the two edges of that: one click really signs a demo
 * account in, and the working password never leaks into the audit trail.
 */
class DemoLoginAutofillTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        foreach (RoleAndUserSeeder::DEMO_ACCOUNTS as $account) {
            $user = User::create([
                'name' => $account['name'],
                'email' => $account['email'],
                'phone' => '',
                'password' => 'something-unrecorded',
                'is_active' => true,
                'country' => $account['country'],
            ]);
            $user->assignRole($account['role']);
        }

        $this->owner = User::where('email', 'superadmin@plussave.com')->sole();
        $this->actingAs($this->owner);
        config(['app.url' => 'http://localhost']);
        Setting::set('site_mode', 'testing', 'string', 'display');
    }

    public function test_saving_a_demo_password_resets_the_five_demo_accounts_and_nobody_else(): void
    {
        $bystander = User::create([
            'name' => 'Real staff', 'email' => 'staff@example.test',
            'password' => 'their-own-secret', 'is_active' => true,
        ]);
        $before = $bystander->password;

        $this->from('/settings')->put('/settings', [
            'settings_tab' => 'site', 'site_mode' => 'testing',
            'demo_login_autofill' => 'on', 'demo_password' => 'TestDesk2026',
        ])->assertSessionHasNoErrors();

        $this->assertSame('TestDesk2026', Setting::get('demo_password'));
        $this->assertSame('on', Setting::get('demo_login_autofill'));

        foreach (RoleAndUserSeeder::DEMO_ACCOUNTS as $account) {
            $this->assertTrue(
                Hash::check('TestDesk2026', User::where('email', $account['email'])->value('password')),
                $account['email'].' was not reset'
            );
        }
        $this->assertSame($before, $bystander->fresh()->password, 'a real account must never be touched');
    }

    public function test_every_demo_button_signs_straight_in_with_the_shared_password(): void
    {
        $this->put('/settings', [
            'settings_tab' => 'site', 'site_mode' => 'testing',
            'demo_login_autofill' => 'on', 'demo_password' => 'TestDesk2026',
        ])->assertSessionHasNoErrors();

        foreach (RoleAndUserSeeder::DEMO_ACCOUNTS as $account) {
            auth()->logout();
            $this->post('/admin/login', [
                'email' => $account['email'],
                'password' => 'TestDesk2026',
            ])->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs(User::where('email', $account['email'])->sole());
        }
    }

    public function test_buttons_carry_the_password_only_while_autofill_is_on(): void
    {
        $this->put('/settings', [
            'settings_tab' => 'site', 'site_mode' => 'testing',
            'demo_login_autofill' => 'off', 'demo_password' => 'TestDesk2026',
        ])->assertSessionHasNoErrors();

        auth()->logout();
        $html = $this->get('/admin/login')->assertOk()->getContent();
        // The buttons are on the page, they just hold no password to give away.
        $this->assertSame(
            count(RoleAndUserSeeder::DEMO_ACCOUNTS),
            substr_count($html, 'data-demo-email=')
        );
        $this->assertStringNotContainsString('data-demo-password', $html);

        $this->actingAs($this->owner);
        $this->put('/settings', ['settings_tab' => 'site', 'site_mode' => 'testing', 'demo_login_autofill' => 'on'])
            ->assertSessionHasNoErrors();

        auth()->logout();
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertSame(
            count(RoleAndUserSeeder::DEMO_ACCOUNTS),
            substr_count($html, 'data-demo-password="TestDesk2026"')
        );
    }

    public function test_the_audit_trail_records_the_change_without_the_password(): void
    {
        $this->put('/settings', [
            'settings_tab' => 'site', 'site_mode' => 'testing',
            'demo_login_autofill' => 'on', 'demo_password' => 'TestDesk2026',
        ])->assertSessionHasNoErrors();

        $log = ActivityLog::where('action', 'settings_updated')->latest('id')->sole();
        $this->assertStringContainsString('Demo password', $log->description);
        $this->assertStringNotContainsString('TestDesk2026', $log->description);
        $this->assertStringNotContainsString('TestDesk2026', (string) $log->old_value);
        $this->assertStringNotContainsString('TestDesk2026', (string) $log->new_value);
    }

    public function test_a_blank_demo_password_leaves_the_accounts_on_what_they_already_are(): void
    {
        $kept = User::where('email', 'admin@plussave.com')->value('password');

        $this->put('/settings', [
            'settings_tab' => 'site', 'site_mode' => 'testing',
            'demo_login_autofill' => 'on', 'demo_password' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame($kept, User::where('email', 'admin@plussave.com')->value('password'));
    }

    public function test_an_admin_cannot_set_the_demo_password_even_by_posting_the_site_tab(): void
    {
        $admin = User::where('email', 'admin@plussave.com')->sole();
        $this->actingAs($admin);

        $this->put('/settings', [
            'settings_tab' => 'site', 'demo_login_autofill' => 'on', 'demo_password' => 'Sneaked',
        ])->assertSessionHasNoErrors();

        $this->assertNull(Setting::get('demo_password'));
        $this->assertFalse(Hash::check('Sneaked', User::where('email', 'admin@plussave.com')->value('password')));
    }
}
