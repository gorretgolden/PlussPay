<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SiteModeSwitchTest extends TestCase
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
            'name' => 'Gorret ' . ucfirst($role),
            'email' => $role . '.' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function loginPageHasDemoButtons(): bool
    {
        // The login route redirects to the dashboard for a signed-in user, so sign out
        // first. assertOk is what stops a redirect from passing as "buttons missing".
        auth()->logout();

        return str_contains(
            $this->get('/admin/login')->assertOk()->getContent(),
            'Demo accounts'
        );
    }

    public function test_the_buttons_show_until_anyone_changes_the_mode(): void
    {
        $this->assertTrue($this->loginPageHasDemoButtons());
    }

    public function test_each_button_carries_the_email_the_password_is_refilled_for(): void
    {
        auth()->logout();

        $html = $this->get('/admin/login')->assertOk()->getContent();

        // The click handler reads these to refill the sign-in box, so a button without its email
        // would quietly go back to filling nothing.
        foreach (['admin', 'superadmin', 'agent', 'accountant'] as $role) {
            $this->assertStringContainsString(
                'data-demo-email="'.$role.'@plussave.com"',
                $html,
                'Missing the demo button for '.$role
            );
        }

        $this->assertStringContainsString('plussave.demoPasswords', $html);
    }

    public function test_the_sign_in_boxes_do_not_invite_the_browsers_own_saved_account(): void
    {
        auth()->logout();

        $html = $this->get('/admin/login')->assertOk()->getContent();

        // username/current-password tell the browser that these boxes carry a sign-in it has
        // saved, and it then writes its own pair over the demo account the button just chose.
        $this->assertStringNotContainsString('autocomplete="username"', $html);
        $this->assertStringNotContainsString('autocomplete="current-password"', $html);
        $this->assertStringContainsString('autocomplete="new-password"', $html);
        $this->assertStringContainsString('id="email" value="" autocomplete="off"', $html);
    }

    public function test_a_kept_demo_password_is_forgotten_once_it_fails_to_sign_in(): void
    {
        User::create([
            'name' => 'Books Accountant',
            'email' => 'accountant@plussave.com',
            'password' => 'the-real-books-password',
            'is_active' => true,
        ]);

        auth()->logout();

        // Opening the form first is what lets a refused sign-in come back to it in one hop, which
        // is how the browser does it; two hops would already have forgotten the message.
        $this->get('/admin/login')->assertOk();

        $html = $this->followingRedirects()
            ->post('/admin/login', [
                'email' => 'accountant@plussave.com',
                'password' => 'a-figure-from-another-account',
            ])
            ->assertOk()
            ->assertSee('Invalid password.')
            ->getContent();

        // Without this the button replays the same wrong figure every time she clicks Accountant.
        // The next successful sign-in puts the right one back.
        $this->assertStringContainsString('remember("accountant@plussave.com", \'\')', $html);
    }

    public function test_on_the_developers_own_host_the_button_fills_the_password_from_the_env(): void
    {
        config(['demo.accounts' => ['admin@plussave.com' => 'chosen-in-dot-env']]);

        auth()->logout();

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('data-demo-email="admin@plussave.com" data-demo-password="chosen-in-dot-env"', false);
    }

    public function test_a_public_host_never_prints_a_demo_password_in_the_page(): void
    {
        config([
            'app.url' => 'https://plussave.com',
            'demo.accounts' => ['admin@plussave.com' => 'chosen-in-dot-env'],
        ]);

        auth()->logout();

        $html = $this->call('GET', 'https://plussave.com/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('data-demo-email="admin@plussave.com"', $html);
        $this->assertStringNotContainsString('data-demo-password', $html);
        $this->assertStringNotContainsString('chosen-in-dot-env', $html);
    }

    public function test_a_super_admin_can_switch_the_site_to_production(): void
    {
        $this->actingAs($this->user('superadmin'))
            ->put('/settings', ['site_mode' => 'production'])
            ->assertSessionHasNoErrors();

        $this->assertSame('production', Setting::get('site_mode'));
        $this->assertFalse($this->loginPageHasDemoButtons());
    }

    public function test_switching_back_to_testing_brings_them_out_again(): void
    {
        Setting::set('site_mode', 'production', 'string', 'display');

        $this->actingAs($this->user('superadmin'))
            ->put('/settings', ['site_mode' => 'testing'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->loginPageHasDemoButtons());
    }

    public function test_an_admin_cannot_change_the_mode_behind_a_super_admin(): void
    {
        $this->actingAs($this->user('admin'))
            ->put('/settings', ['site_mode' => 'production']);

        $this->assertNull(Setting::get('site_mode'));
        $this->assertTrue($this->loginPageHasDemoButtons());
    }

    public function test_the_mode_card_only_renders_for_a_super_admin(): void
    {
        $this->actingAs($this->user('superadmin'))
            ->get('/settings')
            ->assertSee('Site Mode');

        $this->actingAs($this->user('admin'))
            ->get('/settings')
            ->assertDontSee('Site Mode');
    }

    public function test_testing_is_rejected_in_production_even_on_a_local_host_before_any_writes(): void
    {
        Setting::set('site_mode', 'production', 'string', 'display');
        Setting::set('company_name', 'Original', 'string', 'company');
        $this->actingAs($this->user('superadmin'));
        config(['app.url' => 'http://localhost']);
        // Simulation uses the test's in-memory database, never the deployed site.
        $this->app->instance('env', 'production');
        try {
            $this->withSession(['_token' => 'production-guard-test'])
                ->put('http://localhost/settings', ['_token' => 'production-guard-test', 'site_mode' => 'testing', 'company_name' => 'Unexpected'])
                ->assertSessionHasErrors('site_mode');
            $this->assertSame('production', Setting::get('site_mode'));
            $this->assertSame('Original', Setting::get('company_name'));
            $this->assertDatabaseMissing('activity_logs', ['action' => 'settings_updated']);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_testing_is_rejected_on_the_canonical_non_local_host_even_in_a_non_production_environment(): void
    {
        Setting::set('site_mode', 'production', 'string', 'display');
        config(['app.url' => 'https://payments.example.com']);
        $this->actingAs($this->user('superadmin'))
            ->put('https://payments.example.com/settings', ['settings_tab' => 'site', 'site_mode' => 'testing'])
            ->assertSessionHasErrors('site_mode');
        $this->assertSame('production', Setting::get('site_mode'));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'settings_updated']);
    }

    public function test_a_distinct_non_production_host_can_use_testing(): void
    {
        Setting::set('site_mode', 'production', 'string', 'display');
        config(['app.url' => 'https://payments.example.com']);
        $this->actingAs($this->user('superadmin'))
            ->put('https://staging.example.com/settings', ['settings_tab' => 'site', 'site_mode' => 'testing'])
            ->assertSessionHasNoErrors();
        $this->assertSame('testing', Setting::get('site_mode'));
    }

    public function test_local_canonical_hosts_do_not_block_testing(): void
    {
        $this->actingAs($this->user('superadmin'));
        foreach (['localhost', '127.0.0.1', '192.168.1.20', 'pluspay.test', 'pluspay.local', 'pluspay.localhost'] as $host) {
            Setting::set('site_mode', 'production', 'string', 'display');
            config(['app.url' => 'http://'.$host]);
            $this->put('http://'.$host.'/settings', ['settings_tab' => 'site', 'site_mode' => 'testing'])
                ->assertSessionHasNoErrors();
            $this->assertSame('testing', Setting::get('site_mode'), $host);
        }
    }

    public function test_production_option_is_available_to_repair_a_legacy_testing_setting(): void
    {
        Setting::set('site_mode', 'testing', 'string', 'display');
        config(['app.url' => 'https://payments.example.com']);
        $this->actingAs($this->user('superadmin'));
        $html = $this->get('https://payments.example.com/settings')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertCount(1, $xpath->query('//select[@name="site_mode"]/option[@value="testing" and @disabled]'));
        $this->assertCount(1, $xpath->query('//select[@name="site_mode"]/option[@value="production" and not(@disabled)]'));
        $this->put('https://payments.example.com/settings', ['settings_tab' => 'site', 'site_mode' => 'production'])
            ->assertSessionHasNoErrors();
        $this->assertSame('production', Setting::get('site_mode'));
    }

    public function test_blank_mode_cannot_clear_production_and_invalid_modes_are_rejected(): void
    {
        Setting::set('site_mode', 'production', 'string', 'display');
        $this->actingAs($this->user('superadmin'));
        foreach (['', 'invalid'] as $mode) {
            $this->put('/settings', ['settings_tab' => 'site', 'site_mode' => $mode])
                ->assertSessionHasErrors('site_mode');
            $this->assertSame('production', Setting::get('site_mode'));
        }
    }

    public function test_an_admin_cannot_enable_testing_through_a_scoped_request(): void
    {
        Setting::set('site_mode', 'production', 'string', 'display');
        $this->actingAs($this->user('admin'))
            ->put('/settings', ['settings_tab' => 'site', 'site_mode' => 'testing'])
            ->assertSessionHasNoErrors();
        $this->assertSame('production', Setting::get('site_mode'));
    }
}
