<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The account page is the one screen with no permission behind it: a cashier or a super admin
 * must be able to read the details the office holds and set a new password without asking an
 * administrator. These tests open it as each role and change a password through the real form.
 */
class MyAccountTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, string $name, array $attributes = []): User
    {
        if ($role !== '') {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web'])
                ->syncPermissions(PermissionCatalog::defaults()[$role] ?? []);
        }

        $user = User::create(array_merge([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).uniqid().'@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
            'country' => 'UG',
        ], $attributes));

        if ($role !== '') {
            $user->assignRole($role);
        }

        return $user;
    }

    public function test_my_account_is_in_the_sidebar_for_every_desk_and_opens_their_own_details(): void
    {
        $roles = [
            'agent' => 'Amina Nabukenya',
            'super-agent' => 'Amina Lead',
            'accountant' => 'Grace Naigaga',
            'superadmin' => 'Super Admin',
        ];

        foreach ($roles as $role => $name) {
            $user = $this->user($role, $name);

            $html = $this->actingAs($user)->get('/account')->assertOk()->getContent();

            $this->assertStringContainsString('href="'.route('account.show').'"', $html, $role.' has the sidebar link.');
            $this->assertMatchesRegularExpression('/My Account\s*<\/a>/', $html, $role.' sees the link named.');
            $this->assertStringContainsString($user->name, $html, $role.' reads their own name.');
            $this->assertStringContainsString($user->email, $html, $role.' reads their own email.');

            // The password form is the reason the page exists, so its boxes must be on it.
            $this->assertStringContainsString('name="current_password"', $html);
            $this->assertStringContainsString('name="password_confirmation"', $html);
            $this->assertStringContainsString('name="source" value="account"', $html);
        }
    }

    public function test_a_person_with_no_role_can_still_read_their_account(): void
    {
        $user = $this->user('', 'No Role Yet');

        $html = $this->actingAs($user)->get('/account')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/My Account\s*<\/a>/', $html);
        $this->assertStringContainsString('No role assigned', $html);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
    }

    public function test_the_page_reads_back_the_details_the_office_set(): void
    {
        // A China desk reads its sign-in window on Shanghai time, so pin the clock to a Monday
        // that is inside the window on both clocks.
        $this->travelTo(now()->startOfWeek()->setTime(10, 0));

        $user = $this->user('super-agent', 'Amina Lead', ['phone' => '0751 657 654', 'country' => 'CN']);
        $user->setAllowedDays([1, 2, 3, 4, 5]);
        $user->allowed_from = '08:00';
        $user->allowed_to = '18:00';
        $user->save();

        $html = $this->actingAs($user)->get('/account')->assertOk()->getContent();
        $user->refresh();

        $this->assertNotEmpty($user->phone);
        $this->assertStringContainsString($user->phone, $html);
        $this->assertStringContainsString('Super Agent', $html);
        $this->assertStringContainsString('China', $html);
        $this->assertStringContainsString('Monday to Friday 08:00 to 18:00', $html);
        $this->assertStringContainsString('Two-step sign-in', $html);
    }

    public function test_a_password_change_from_my_account_stays_on_the_page(): void
    {
        $user = $this->user('agent', 'Amina Nabukenya');

        $this->actingAs($user)
            ->from(route('account.show'))
            ->post(route('password.change'), [
                'current_password' => 'Password123',
                'password' => 'BetterPassword456',
                'password_confirmation' => 'BetterPassword456',
                'source' => 'account',
            ])
            ->assertRedirect(route('account.show'))
            ->assertSessionHas('success', 'Password changed successfully.');

        $user->refresh();
        $this->assertTrue(Hash::check('BetterPassword456', $user->password));
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $user->id, 'action' => 'password_changed']);

        $this->get(route('account.show'))->assertOk();
    }

    public function test_the_real_time_check_answers_yes_or_no_on_the_typed_current_password(): void
    {
        $user = $this->user('agent', 'Amina Nabukenya');

        $this->actingAs($user)
            ->postJson(route('password.check-current'), ['current_password' => 'Password123'])
            ->assertOk()
            ->assertJson(['valid' => true]);

        $this->actingAs($user)
            ->postJson(route('password.check-current'), ['current_password' => 'Wrong123'])
            ->assertOk()
            ->assertJson(['valid' => false]);

        $this->actingAs($user)
            ->postJson(route('password.check-current'), [])
            ->assertStatus(422);
    }

    public function test_a_guest_cannot_use_the_real_time_check(): void
    {
        $this->postJson(route('password.check-current'), ['current_password' => 'Password123'])
            ->assertUnauthorized();
    }

    public function test_signing_in_with_the_old_password_says_it_was_previously_changed(): void
    {
        $user = $this->user('agent', 'Amina Nabukenya');

        $this->actingAs($user)->post(route('password.change'), [
            'current_password' => 'Password123',
            'password' => 'BetterPassword456',
            'password_confirmation' => 'BetterPassword456',
        ])->assertRedirect(route('dashboard'));

        $this->app['auth']->guard('web')->logout();

        $this->from(route('login'))->post(route('login.post'), [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'previously changed',
            (string) session('errors')->first('email'),
        );
    }

    public function test_a_wrong_current_password_is_named_on_the_page_and_changes_nothing(): void
    {
        $user = $this->user('agent', 'Amina Nabukenya');

        $this->actingAs($user)
            ->from(route('account.show'))
            ->post(route('password.change'), [
                'current_password' => 'Wrong123',
                'password' => 'BetterPassword456',
                'password_confirmation' => 'BetterPassword456',
                'source' => 'account',
            ])
            ->assertRedirect(route('account.show'))
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('Password123', $user->fresh()->password));
    }

    public function test_the_forced_change_still_lands_on_the_dashboard(): void
    {
        $user = $this->user('agent', 'Amina Nabukenya', ['must_change_password' => true]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.required'));

        $this->post(route('password.change'), [
            'current_password' => 'Password123',
            'password' => 'BetterPassword456',
            'password_confirmation' => 'BetterPassword456',
        ])->assertRedirect(route('dashboard'));

        $this->assertFalse($user->fresh()->must_change_password);
    }
}
