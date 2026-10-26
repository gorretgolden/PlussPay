<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ThemeToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->user = User::create([
            'name' => 'Night reader', 'email' => 'night@example.test',
            'password' => 'Password123', 'is_active' => true,
        ]);
        $this->user->assignRole('admin');
    }

    public function test_theme_defaults_to_light_and_the_toggle_switches_and_persists(): void
    {
        $this->actingAs($this->user);

        $html = $this->get('/account')->assertOk()->getContent();
        $this->assertStringNotContainsString('<html lang="en" class="dark"', $html);
        $this->assertStringContainsString('Dark mode', $html);

        $this->post('/account/theme', ['theme' => 'dark'])->assertRedirect();
        $this->assertSame('dark', $this->user->fresh()->theme);

        $html = $this->get('/account')->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="en" class="dark"', $html);
        $this->assertStringContainsString('Light mode', $html);

        $this->post('/account/theme', ['theme' => 'light'])->assertRedirect();
        $this->assertSame('light', $this->user->fresh()->theme);
    }

    public function test_an_unlisted_theme_value_is_rejected(): void
    {
        $this->actingAs($this->user);

        $this->post('/account/theme', ['theme' => 'neon'])->assertSessionHasErrors('theme');
        $this->assertSame('light', $this->user->fresh()->theme);
    }

    public function test_a_guest_cannot_change_a_theme(): void
    {
        $this->post('/account/theme', ['theme' => 'dark'])->assertRedirect('/admin/login');
        $this->assertSame('light', $this->user->fresh()->theme);
    }
}
