<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\LoginBackground;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The sign-in page is the company's front door, so its background and logo live in
 * Settings under a Super Admin only tab, with the bundled assets as the fallback.
 * The accent color is no longer a login-page setting: it is the system theme, and
 * every screen including this one reads it.
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

    private function saveLogin(array $payload)
    {
        return $this->put('/settings', ['settings_tab' => 'login'] + $payload);
    }

    public function test_uploaded_branding_reaches_the_sign_in_page(): void
    {
        $this->saveLogin([
            'login_background' => UploadedFile::fake()->image('desk.png', 30, 20),
            'login_logo' => UploadedFile::fake()->image('mark.jpg', 10, 10),
        ])->assertSessionHasNoErrors();

        $this->put('/settings', [
            'settings_tab' => 'appearance',
            'theme_color' => '#0f766e',
        ])->assertSessionHasNoErrors();

        $this->assertSame('login/background.png', Setting::get('login_background'));
        $this->assertSame('upload', Setting::get('login_background_style'));
        $this->assertSame('login/logo.jpg', Setting::get('login_logo'));
        $this->assertSame('#0f766e', Setting::get('theme_color'));
        Storage::disk('public')->assertExists('login/background.png');
        Storage::disk('public')->assertExists('login/logo.jpg');

        auth()->logout();
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertStringContainsString('storage/login/background.png', $html);
        $this->assertStringContainsString('storage/login/logo.jpg', $html);
        $this->assertStringContainsString('--brand: #0f766e', $html);
        $this->assertStringContainsString('--brand-800: #0f766e', $html);
        $this->assertStringNotContainsString('/login-background.webp', $html);
    }

    public function test_the_page_falls_back_to_the_bundled_assets_and_default_color(): void
    {
        auth()->logout();
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertStringContainsString('/login-background.webp', $html);
        $this->assertStringContainsString('/logo.webp', $html);
        $this->assertStringContainsString('--brand: #166534', $html);
        $this->assertStringContainsString('--brand-800: #166534', $html);
    }

    public function test_the_theme_rebuilds_the_green_palette_for_the_whole_system(): void
    {
        $this->put('/settings', [
            'settings_tab' => 'appearance',
            'theme_color' => '#7c2d12',
        ])->assertSessionHasNoErrors();

        auth()->logout();
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('tailwind.config = { theme: { extend: { colors: { green: {', $html);
        $this->assertStringContainsString('--brand-800: #7c2d12', $html);
        $this->assertStringContainsString('--brand-50:', $html);
        $this->assertStringContainsString('--brand-950:', $html);
        $this->assertStringNotContainsString('--brand-800: #166534', $html);
    }

    public function test_a_bundled_background_is_selectable_and_reaches_the_page(): void
    {
        $this->assertGreaterThanOrEqual(4, count(LoginBackground::BUILT_IN), 'At least the default plus three designs.');

        $this->saveLogin(['login_background_style' => 'waves'])->assertSessionHasNoErrors();
        $this->assertSame('waves', Setting::get('login_background_style'));

        $this->saveLogin(['login_background_style' => 'elsewhere'])->assertSessionHasErrors('login_background_style');
        $this->assertSame('waves', Setting::get('login_background_style'));

        auth()->logout();
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertStringContainsString('/login-backgrounds/waves.svg', $html);
        $this->assertStringNotContainsString('/login-background.webp', $html);
    }

    public function test_a_new_upload_switches_back_from_a_bundled_design_and_is_not_cached(): void
    {
        $this->saveLogin(['login_background_style' => 'paper'])->assertSessionHasNoErrors();

        $this->saveLogin([
            'login_background' => UploadedFile::fake()->image('new.jpg', 20, 20),
        ])->assertSessionHasNoErrors();

        $this->assertSame('upload', Setting::get('login_background_style'));

        auth()->logout();
        $html = $this->get('/admin/login')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/storage\/login\/background\.jpg\?v=\d+/', $html);
        $this->assertStringNotContainsString('/login-backgrounds/paper.svg', $html);
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
            'settings_tab' => 'appearance',
            'theme_color' => '#ff0000',
        ])->assertSessionHasNoErrors();

        $this->saveLogin([
            'login_background_style' => 'waves',
            'login_background' => UploadedFile::fake()->image('sneak.png', 5, 5),
        ])->assertSessionHasNoErrors();

        $this->assertNull(Setting::get('theme_color'));
        $this->assertNull(Setting::get('login_background_style'));
        $this->assertNull(Setting::get('login_background'));
        Storage::disk('public')->assertMissing('login/background.png');
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $this->saveLogin([
            'login_background' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('login_background');

        $this->assertNull(Setting::get('login_background'));
    }

    public function test_a_color_outside_the_hex_palette_is_rejected(): void
    {
        $this->put('/settings', [
            'settings_tab' => 'appearance',
            'theme_color' => 'teal',
        ])->assertSessionHasErrors('theme_color');

        $this->assertNull(Setting::get('theme_color'));
    }

    public function test_replacing_an_image_deletes_the_old_file(): void
    {
        Storage::disk('public')->put('login/background.png', 'old');
        Setting::set('login_background', 'login/background.png', 'string', 'display');

        $this->saveLogin([
            'login_background' => UploadedFile::fake()->image('bg.jpg', 20, 20),
        ])->assertSessionHasNoErrors();

        $this->assertSame('login/background.jpg', Setting::get('login_background'));
        Storage::disk('public')->assertMissing('login/background.png');
        Storage::disk('public')->assertExists('login/background.jpg');
    }
}
