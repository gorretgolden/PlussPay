<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TimezoneSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin'] as $role) {
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

    /**
     * What the next real page load does when the application boots.
     */
    private function applySavedTimezone(): void
    {
        (new AppServiceProvider($this->app))->applyTimezone();
    }

    public function test_the_clock_defaults_to_kampala(): void
    {
        $this->assertSame('Africa/Kampala', Setting::timezone());
        $this->assertSame('Africa/Kampala', config('app.timezone'));
    }

    public function test_an_admin_can_move_the_clock_and_it_applies_on_the_next_page_load(): void
    {
        $this->actingAs($this->user('admin'))
            ->put('/settings', ['timezone' => 'Asia/Shanghai'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Asia/Shanghai', Setting::get('timezone'));

        $this->applySavedTimezone();

        $this->assertSame('Asia/Shanghai', config('app.timezone'));
        $this->assertSame('Asia/Shanghai', date_default_timezone_get());
    }

    public function test_a_zone_outside_the_list_is_refused(): void
    {
        $this->actingAs($this->user('admin'))
            ->put('/settings', ['timezone' => 'Europe/Paris'])
            ->assertSessionHasErrors('timezone');

        $this->assertNull(Setting::get('timezone'));
        $this->assertSame('Africa/Kampala', Setting::timezone());
    }

    public function test_an_unrecognised_stored_value_falls_back_instead_of_crashing(): void
    {
        Setting::set('timezone', 'Mars/Olympus', 'string', 'display');

        $this->applySavedTimezone();

        $this->assertSame('Africa/Kampala', config('app.timezone'));
    }

    public function test_the_card_offers_every_zone_and_marks_the_saved_one(): void
    {
        Setting::set('timezone', 'Africa/Lagos', 'string', 'display');

        $html = $this->actingAs($this->user('admin'))
            ->get('/settings')
            ->assertOk()
            ->assertSee('Date and Time')
            ->getContent();

        $this->assertStringContainsString('value="Africa/Lagos" selected>', $html);
        $this->assertStringNotContainsString('value="Africa/Kampala" selected', $html);

        foreach (array_keys(Setting::TIMEZONES) as $zone) {
            $this->assertStringContainsString('value="' . $zone . '"', $html);
        }
    }

    public function test_the_wall_clock_moves_by_the_difference_between_the_zones(): void
    {
        Setting::set('timezone', 'Africa/Kampala', 'string', 'display');
        $this->applySavedTimezone();
        $kampala = now();

        Setting::set('timezone', 'Asia/Shanghai', 'string', 'display');
        $this->applySavedTimezone();
        $shanghai = now();

        // Both readings are a second apart, so the gap on the face of the clock is
        // purely the offset: Shanghai sits five hours ahead of Kampala, no DST either side.
        $this->assertSame(
            5,
            ((int) $shanghai->format('G') - (int) $kampala->format('G') + 24) % 24
        );
    }
}
