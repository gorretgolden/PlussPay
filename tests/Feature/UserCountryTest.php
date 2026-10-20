<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserCountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function actor(string $role): User
    {
        $user = User::create([
            'name' => 'Root User',
            'email' => strtolower($role) . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function payload(string $role = 'agent'): array
    {
        return [
            'name' => 'Wei Chen',
            'email' => 'wei' . uniqid() . '@plussave.test',
            'phone' => '',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => $role,
        ];
    }

    public function test_an_admin_can_create_a_super_agent(): void
    {
        $this->actingAs($this->actor('admin'))
            ->get('/users/create')
            ->assertOk()
            ->assertSee('Super Agent');

        $this->post('/users', $this->payload('super-agent'))
            ->assertSessionHasNoErrors();

        $lead = User::where('name', 'Wei Chen')->firstOrFail();

        $this->assertTrue($lead->hasRole('super-agent'));
        $this->assertSame('UG', $lead->country);
        $this->assertSame(['UGX', 'USD'], array_keys(\App\Models\Account::walletsFor($lead)));

        // What the role was made for: the exchange desk and the send across are theirs.
        $this->assertTrue($lead->can('create-exchange'));
        $this->assertTrue($lead->can('send-to-china'));
        $this->assertTrue($lead->can('approve-cash-handover'));
        $this->assertTrue($lead->can('view-all-transactions'));
    }

    public function test_a_superadmin_can_create_a_china_agent_and_the_zone_follows(): void
    {
        $this->actingAs($this->actor('superadmin'))
            ->post('/users', $this->payload() + ['country' => 'CN'])
            ->assertSessionHasNoErrors();

        $agent = User::where('name', 'Wei Chen')->firstOrFail();

        $this->assertSame('CN', $agent->country);
        $this->assertSame('Asia/Shanghai', $agent->timezone);
    }

    public function test_an_admin_can_place_a_new_account_in_china(): void
    {
        $this->actingAs($this->actor('admin'))
            ->post('/users', $this->payload() + ['country' => 'CN'])
            ->assertSessionHasNoErrors();

        $agent = User::where('name', 'Wei Chen')->firstOrFail();

        $this->assertSame('CN', $agent->country);
        $this->assertSame('Asia/Shanghai', $agent->timezone);
    }

    public function test_an_admin_can_move_another_desk_between_countries(): void
    {
        $agent = $this->actor('agent');
        $agent->update(['country' => 'CN', 'timezone' => 'Asia/Shanghai']);

        $this->actingAs($this->actor('admin'))
            ->put("/users/{$agent->id}", [
                'name' => 'Wei Chen',
                'email' => $agent->email,
                'phone' => '',
                'role' => 'agent',
                'country' => 'UG',
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('UG', $agent->fresh()->country);
        $this->assertSame('Africa/Kampala', $agent->fresh()->timezone);
    }

    public function test_nobody_moves_their_own_desk(): void
    {
        $admin = $this->actor('admin');

        $this->actingAs($admin)
            ->put("/users/{$admin->id}", [
                'name' => 'Root User',
                'email' => $admin->email,
                'phone' => '',
                'role' => 'admin',
                'country' => 'CN',
                'timezone' => 'Asia/Shanghai',
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('UG', $admin->fresh()->country);
    }

    public function test_a_superadmin_can_move_an_account_back_to_uganda(): void
    {
        $agent = $this->actor('agent');
        $agent->update(['country' => 'CN', 'timezone' => 'Asia/Shanghai']);

        $this->actingAs($this->actor('superadmin'))
            ->put("/users/{$agent->id}", [
                'name' => 'Wei Chen',
                'email' => $agent->email,
                'phone' => '',
                'role' => 'agent',
                'country' => 'UG',
                'timezone' => 'Africa/Kampala',
                'is_active' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('UG', $agent->fresh()->country);
    }

    public function test_the_create_form_offers_the_country_box_to_an_admin(): void
    {
        $this->actingAs($this->actor('admin'))
            ->get('/users/create')
            ->assertSee('Which desk this account works from');
    }

    public function test_your_own_profile_states_the_desk_without_a_control_to_move_it(): void
    {
        $admin = $this->actor('admin');

        $this->actingAs($admin)
            ->get("/users/{$admin->id}/edit")
            ->assertSee('Locked on your own account')
            ->assertDontSee('Which desk this account works from');
    }

    public function test_the_sidebar_shows_the_viewers_country(): void
    {
        $agent = $this->actor('agent');
        $agent->update(['country' => 'CN']);

        $this->actingAs($agent)
            ->get('/dashboard')
            ->assertSee('China');
    }
}
