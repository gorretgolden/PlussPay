<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The sign-in page is the company's front door, so its background, logo and accent
 * color live in Settings under a Super Admin only tab, with the bundled assets as
 * the fallback until the office picks its own.
 */
class LoginPageBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $user = User::create([
            'name' => 'Brand owner', 'email' => 'brand@example.test',
            'password' => 'Password123', 'is_active' => true,
        ]);
        $user->assignRole('superadmin');
        $this->actingAs($user);
        config(['app.url' => 'http://localhost']);
        Storage::fake('public');
        Setting::set('site_mode', 'production', 'string', 'display');
    }

    public function test_uploaded_branding_reaches_the_sign_in_page(): void
    {
        $this->put('/settings', [
            'settings_tab' => 'login',
            'login_background' => UploadedFile::fake()->image('desk.png', 30, 20),
            'login_logo' => UploadedFile::fake()->image('mark.jpg', 10, 10),
            'login_theme_color' => '#0f766e',
        ])->assertSessionHasNoErrors();

        $this->assertSame('login/background.png', Setting::get('login_background'));
        $this->assertSame('login/logo.jpg', Setting::get('login_logo'));
        Storage::disk('public')->assertExists('login/background.png');
        Storage::disk('public')->assertExists('login/logo.jpg');

        auth()->logout();
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertStringContainsString('storage/login/background.png', $html);
        $this->assertStringContainsString('storage/login/logo.jpg', $html);
        $this->assertStringContainsString('--brand: #0f766e', $html);
        $this->assertStringNotContainsString('/login-background.webp', $html);
    }

    public function test_the_page_falls_back_to_the_bundled_assets_and_default_color(): void
    {
        auth()->logout();
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertStringContainsString('/login-background.webp', $html);
        $this->assertStringContainsString('/logo.webp', $html);
        $this->assertStringContainsString('--brand: #166534', $html);
    }

    public function test_an_admin_cannot_change_the_login_branding(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::create([
            'name' => 'Office admin', 'email' => 'office@example.test',
            'password' => 'Password123', 'is_active' => true,
        ]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->put('/settings', [
            'settings_tab' => 'login',
            'login_theme_color' => '#ff0000',
            'login_background' => UploadedFile::fake()->image('sneak.png', 5, 5),
        ])->assertSessionHasNoErrors();

        $this->assertNull(Setting::get('login_theme_color'));
        $this->assertNull(Setting::get('login_background'));
        Storage::disk('public')->assertMissing('login/background.png');
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $this->put('/settings', [
            'settings_tab' => 'login',
            'login_background' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('login_background');

        $this->assertNull(Setting::get('login_background'));
    }

    public function test_a_color_outside_the_hex_palette_is_rejected(): void
    {
        $this->put('/settings', [
            'settings_tab' => 'login',
            'login_theme_color' => 'teal',
        ])->assertSessionHasErrors('login_theme_color');

        $this->assertNull(Setting::get('login_theme_color'));
    }

    public function test_replacing_an_image_deletes_the_old_file(): void
    {
        Storage::disk('public')->put('login/background.png', 'old');
        Setting::set('login_background', 'login/background.png', 'string', 'display');

        $this->put('/settings', [
            'settings_tab' => 'login',
            'login_background' => UploadedFile::fake()->image('bg.jpg', 20, 20),
        ])->assertSessionHasNoErrors();

        $this->assertSame('login/background.jpg', Setting::get('login_background'));
        Storage::disk('public')->assertMissing('login/background.png');
        Storage::disk('public')->assertExists('login/background.jpg');
    }
}
