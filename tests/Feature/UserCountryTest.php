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

    public function test_a_superadmin_can_create_a_china_agent_and_the_zone_follows(): void
    {
        $this->actingAs($this->actor('superadmin'))
            ->post('/users', $this->payload() + ['country' => 'CN'])
            ->assertSessionHasNoErrors();

        $agent = User::where('name', 'Wei Chen')->firstOrFail();

        $this->assertSame('CN', $agent->country);
        $this->assertSame('Asia/Shanghai', $agent->timezone);
    }

    public function test_a_plain_admin_cannot_place_a_new_account_in_china(): void
    {
        $this->actingAs($this->actor('admin'))
            ->post('/users', $this->payload() + ['country' => 'CN'])
            ->assertSessionHasNoErrors();

        $agent = User::where('name', 'Wei Chen')->firstOrFail();

        $this->assertSame('UG', $agent->country);
        $this->assertSame('Africa/Kampala', $agent->timezone);
    }

    public function test_an_admin_editing_an_account_leaves_the_country_untouched(): void
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

        $this->assertSame('CN', $agent->fresh()->country);
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

    public function test_the_sidebar_shows_the_viewers_country(): void
    {
        $agent = $this->actor('agent');
        $agent->update(['country' => 'CN']);

        $this->actingAs($agent)
            ->get('/dashboard')
            ->assertSee('China');
    }
}
