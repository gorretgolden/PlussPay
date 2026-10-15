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
