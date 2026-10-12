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
}
