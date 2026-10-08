<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function actor(): User
    {
        $user = User::create([
            'name' => 'Net Manager',
            'email' => 'manager' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('superadmin');

        return $user;
    }

    private function clerk(string $email): User
    {
        $user = User::create([
            'name' => 'Clerk Two',
            'email' => $email,
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole('agent');

        return $user;
    }

    private function enrol(User $user): string
    {
        $secret = TotpService::generateSecret();
        $user->update(['two_factor_secret' => $secret, 'two_factor_enabled' => true]);

        return $secret;
    }

    public function test_a_fresh_code_is_accepted_and_a_stale_one_is_not(): void
    {
        $secret = TotpService::generateSecret();

        $this->assertTrue(TotpService::isValidSecret($secret));
        $this->assertTrue(TotpService::verify($secret, TotpService::code($secret)));
        $this->assertTrue(TotpService::verify($secret, TotpService::code($secret, time() - 30)));
        $this->assertFalse(TotpService::verify($secret, TotpService::code($secret, time() - 120)));
        $this->assertFalse(TotpService::verify($secret, '000000'));
    }

    public function test_the_setup_page_shows_the_key_and_the_link_to_scan(): void
    {
        $user = $this->clerk('scan@plussave.test');

        $this->actingAs($this->actor());

        $response = $this->get(route('users.two-factor', $user));

        $response->assertSuccessful();
        $response->assertSee('otpauth');

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->two_factor_secret);
        $response->assertSee(TotpService::formatSecret($fresh->two_factor_secret), false);
    }

    public function test_turning_it_on_needs_a_code_from_the_authenticator(): void
    {
        $user = $this->clerk('on@plussave.test');

        $this->actingAs($this->actor());
        $this->get(route('users.two-factor', $user));

        $this->post(route('users.two-factor.enable', $user), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->two_factor_enabled);

        $secret = $user->fresh()->two_factor_secret;

        $this->post(route('users.two-factor.enable', $user), ['code' => TotpService::code($secret)])
            ->assertRedirect(route('users.index'));

        $this->assertTrue($user->fresh()->two_factor_enabled);
    }

    public function test_the_enrolment_key_never_reaches_the_audit_trail(): void
    {
        $user = $this->clerk('quiet@plussave.test');
        $secret = TotpService::generateSecret();
        $user->update(['two_factor_secret' => $secret]);

        $this->actingAs($this->actor());

        $this->post(route('users.two-factor.enable', $user), ['code' => TotpService::code($secret)]);
        $this->delete(route('users.two-factor.disable', $user));

        foreach (\App\Models\ActivityLog::all() as $log) {
            $this->assertStringNotContainsString($secret, json_encode($log->getAttributes()));
        }

        $this->assertNull($user->fresh()->two_factor_secret);
    }

    public function test_the_password_alone_no_longer_opens_the_account(): void
    {
        $user = $this->clerk('step@plussave.test');
        $this->enrol($user);

        $this->post(route('login.post'), [
            'email' => 'step@plussave.test',
            'password' => 'Password123',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
    }

    public function test_the_code_finishes_the_sign_in(): void
    {
        $secret = TotpService::generateSecret();
        $user = User::create([
            'name' => 'Code Clerk',
            'email' => 'finish@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true,
        ]);
        $user->assignRole('agent');

        $this->post(route('login.post'), [
            'email' => 'finish@plussave.test',
            'password' => 'Password123',
        ]);

        $this->post(route('two-factor.verify'), ['code' => TotpService::code($secret)])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'login',
        ]);
    }

    public function test_five_wrong_codes_send_them_back_to_the_start(): void
    {
        $secret = TotpService::generateSecret();
        $user = User::create([
            'name' => 'Wrong Clerk',
            'email' => 'wrong@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'two_factor_secret' => $secret,
            'two_factor_enabled' => true,
        ]);
        $user->assignRole('agent');

        $this->post(route('login.post'), [
            'email' => 'wrong@plussave.test',
            'password' => 'Password123',
        ]);

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->post(route('two-factor.verify'), ['code' => '111111'])
                ->assertSessionHasErrors('code');
        }

        $this->post(route('two-factor.verify'), ['code' => '111111'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertStringContainsString('Too many wrong codes', $this->flashedError('email'));
    }

    public function test_turning_it_off_removes_the_extra_step(): void
    {
        $user = $this->clerk('off@plussave.test');
        $this->enrol($user);

        $this->actingAs($this->actor());
        $this->delete(route('users.two-factor.disable', $user))->assertRedirect(route('users.index'));

        $this->assertFalse($user->fresh()->two_factor_enabled);

        $this->app['auth']->forgetGuards();

        $this->post(route('login.post'), [
            'email' => 'off@plussave.test',
            'password' => 'Password123',
        ])->assertRedirect('/dashboard');
    }

    public function test_bulk_can_turn_two_factor_off_for_several_accounts(): void
    {
        $actor = $this->actor();
        $first = $this->clerk('batch1@plussave.test');
        $second = $this->clerk('batch2@plussave.test');
        $this->enrol($first);
        $this->enrol($second);

        $this->actingAs($actor);

        $this->post(route('users.bulk'), [
            'action' => 'two_factor_off',
            'user_ids' => [$first->id, $second->id],
        ])->assertSessionHas('success');

        $this->assertFalse($first->fresh()->two_factor_enabled);
        $this->assertNull($second->fresh()->two_factor_secret);
    }

    public function test_an_agent_cannot_open_the_two_factor_screen(): void
    {
        $spy = $this->clerk('spy@plussave.test');
        $peer = $this->clerk('peer@plussave.test');

        $this->actingAs($spy);

        $this->get(route('users.two-factor', $peer))->assertForbidden();
    }
}
