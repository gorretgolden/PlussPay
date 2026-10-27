<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Settings are read on almost every request, so the table is copied into the cache
 * once. The Cache tab exists to warm that copy and to throw it away.
 */
class SystemCacheTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $this->owner = User::create([
            'name' => 'Cache owner', 'email' => 'cache@example.test',
            'password' => 'Password123', 'is_active' => true,
        ]);
        $this->owner->assignRole('superadmin');
        $this->actingAs($this->owner);
        Setting::set('site_mode', 'production', 'string', 'display');
    }

    public function test_the_cache_tab_prints_what_is_cached_and_how_to_change_it(): void
    {
        $html = $this->get('/settings')->assertOk()->getContent();

        $this->assertStringContainsString('id="tab-cache"', $html);
        $this->assertStringContainsString('Cache store', $html);
        $this->assertStringContainsString('Settings', $html);
        $this->assertStringContainsString('Page templates', $html);
        $this->assertStringContainsString('Rebuild settings', $html);
        $this->assertStringContainsString('Clear settings', $html);
        $this->assertStringContainsString('Compile pages', $html);
        $this->assertStringContainsString('Clear pages', $html);
        $this->assertStringContainsString('Clear everything', $html);
        $this->assertStringContainsString('values kept in one copy', $html);
    }

    public function test_rebuilding_keeps_a_copy_and_clearing_it_falls_back_to_the_table(): void
    {
        Setting::set('company_name', 'Cached Co', 'string', 'company');

        $this->post('/settings/cache', ['target' => 'settings', 'action' => 'warm'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('settings_saved_tab', 'cache')
            ->assertSessionHas('success', 'Settings cache rebuilt.');

        $this->assertTrue(Cache::has(Setting::CACHE_KEY));
        $this->assertSame('Cached Co', Setting::get('company_name'));

        // A write straight to the table bypasses the model events, so the copy stays
        // old until it is cleared. That proves reads really come from the cache.
        DB::table('settings')->where('key', 'company_name')->update(['value' => 'Live Co']);
        $this->assertSame('Cached Co', Setting::get('company_name'));

        $this->post('/settings/cache', ['target' => 'settings', 'action' => 'clear'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Settings cache cleared.');

        $this->assertFalse(Cache::has(Setting::CACHE_KEY));
        $this->assertSame('Live Co', Setting::get('company_name'));
    }

    public function test_saving_a_setting_refreshes_the_copy_immediately(): void
    {
        Setting::refreshCache();
        $this->assertSame('Original', Setting::get('company_name', 'Original'));

        $this->put('/settings', ['settings_tab' => 'company', 'company_name' => 'Plus Save'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Plus Save', Setting::get('company_name'));
        $cached = Cache::get(Setting::CACHE_KEY);
        $this->assertSame('Plus Save', $cached['company_name']['value']);
    }

    public function test_anyone_else_is_refused_the_cache_actions(): void
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::create([
            'name' => 'Office admin', 'email' => 'office-cache@example.test',
            'password' => 'Password123', 'is_active' => true,
        ]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->post('/settings/cache', ['target' => 'settings', 'action' => 'warm'])
            ->assertForbidden();
    }

    public function test_an_unknown_action_is_rejected(): void
    {
        $this->post('/settings/cache', ['target' => 'settings', 'action' => 'explode'])
            ->assertSessionHasErrors('action');
    }
}
