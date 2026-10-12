<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actor(string $role = 'superadmin'): User
    {
        $user = User::create([
            'name' => 'Net Manager',
            'email' => 'manager' . uniqid() . '@plussave.test',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function clerk(string $email, array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => 'Clerk One',
            'email' => $email,
            'password' => 'Password123',
            'is_active' => true,
        ], $attributes));

        $user->assignRole('agent');

        return $user;
    }

    public function test_names_are_stored_properly_capitalised_and_phones_are_canonical(): void
    {
        $this->actingAs($this->actor());

        $this->post(route('users.store'), [
            'name' => 'gorret   golden',
            'email' => 'Gorret@Plussave.test',
            'phone' => '0751 657 654',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => 'staff',
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'gorret@plussave.test')->firstOrFail();

        $this->assertSame('Gorret Golden', $user->name);
        $this->assertSame('+256751657654', $user->phone);
    }

    public function test_an_email_is_stored_lowercase_whatever_case_it_arrives_in(): void
    {
        $this->actingAs($this->actor());

        // The address as typed at the desk, with capitals and a stray space.
        $this->post(route('users.store'), [
            'name' => 'Gorret Golden',
            'email' => '  Gorret@PFS.com  ',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => 'staff',
        ])->assertRedirect(route('users.index'));

        $user = User::firstWhere('email', 'gorret@pfs.com');

        $this->assertNotNull($user, 'The account should be saved lowercase.');

        // Editing the account later cannot put the capitals back.
        $this->put(route('users.update', $user), [
            'name' => 'Gorret Golden',
            'email' => 'Gorret@PFS.com',
            'role' => 'staff',
            'is_active' => true,
        ])->assertRedirect(route('users.index'));

        $this->assertSame('gorret@pfs.com', $user->fresh()->email);

        // Nor can code that writes the model directly, such as a seeder or an import.
        $direct = User::create([
            'name' => 'Direct Writer',
            'email' => 'DIRECT@PFS.com',
            'password' => 'Password123',
            'is_active' => true,
        ]);

        $this->assertSame('direct@pfs.com', $direct->email);
    }

    public function test_capitals_typed_at_sign_in_still_reach_the_account(): void
    {
        $this->clerk('gorret@pfs.com');

        $this->post(route('login.post'), [
            'email' => 'Gorret@PFS.com',
            'password' => 'Password123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
    }

    public function test_the_normalize_command_lowercases_accounts_saved_before_the_rule(): void
    {
        $user = $this->clerk('gorret@pfs.com');

        // A row written before the mutator existed, so the lookup cannot lowercase it.
        DB::table('users')->where('id', $user->id)->update(['email' => 'Gorret@PFS.com']);

        Artisan::call('users:normalize', ['--dry-run' => true]);
        $this->assertStringContainsString('1 account(s) would be rewritten', Artisan::output());
        $this->assertSame('Gorret@PFS.com', $user->fresh()->email);

        Artisan::call('users:normalize');
        $this->assertStringContainsString('1 account(s) rewritten', Artisan::output());
        $this->assertSame('gorret@pfs.com', $user->fresh()->email);
    }

    public function test_a_single_word_name_is_refused_with_the_expected_format(): void
    {
        $this->actingAs($this->actor());

        $response = $this->post(route('users.store'), [
            'name' => 'Gorret',
            'email' => 'single@plussave.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => 'staff',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertStringContainsString('like Gorret Golden', $this->flashedError('name'));
        $this->assertSame(0, User::where('email', 'single@plussave.test')->count());
    }

    public function test_an_email_already_on_another_account_is_refused(): void
    {
        $this->actingAs($this->actor());
        $this->clerk('dup@plussave.test');

        $response = $this->post(route('users.store'), [
            'name' => 'Second Person',
            'email' => 'dup@plussave.test',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => 'staff',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('already used by', $this->flashedError('email'));
    }

    public function test_a_phone_written_in_a_different_shape_still_counts_as_a_duplicate(): void
    {
        $this->actingAs($this->actor());
        $this->clerk('taken@plussave.test', ['phone' => '+256751657654']);

        $response = $this->post(route('users.store'), [
            'name' => 'Other Person',
            'email' => 'other@plussave.test',
            'phone' => '0751-657-654',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
            'role' => 'staff',
        ]);

        $response->assertSessionHasErrors('phone');
        $this->assertStringContainsString('taken@plussave.test', $this->flashedError('phone'));
        $this->assertSame(0, User::where('email', 'other@plussave.test')->count());
    }

    public function test_an_inactive_account_is_refused_at_the_door_and_written_to_the_trail(): void
    {
        $user = $this->clerk('sleeping@plussave.test', ['is_active' => false]);

        $this->post(route('login.post'), [
            'email' => 'sleeping@plussave.test',
            'password' => 'Password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('inactive', $this->flashedError('email'));
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'login_blocked',
        ]);
    }

    public function test_login_hours_refuse_a_sign_in_outside_the_window(): void
    {
        Carbon::setTestNow('2026-10-08 20:30:00');

        $this->clerk('dusk@plussave.test', [
            'allowed_days' => '1,2,3,4,5',
            'allowed_from' => '08:00',
            'allowed_to' => '18:00',
        ]);

        $this->post(route('login.post'), [
            'email' => 'dusk@plussave.test',
            'password' => 'Password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertStringContainsString('between 08:00 to 18:00', $this->flashedError('email'));
    }

    public function test_a_saturday_is_refused_even_during_the_hours(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');

        $this->clerk('weekend@plussave.test', [
            'allowed_days' => '1,2,3,4,5',
            'allowed_from' => '08:00',
            'allowed_to' => '18:00',
        ]);

        $this->post(route('login.post'), [
            'email' => 'weekend@plussave.test',
            'password' => 'Password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_same_password_opens_the_account_inside_the_window(): void
    {
        Carbon::setTestNow('2026-10-08 09:00:00');

        $user = $this->clerk('dawn@plussave.test', [
            'allowed_days' => '1,2,3,4,5',
            'allowed_from' => '08:00',
            'allowed_to' => '18:00',
        ]);

        $this->post(route('login.post'), [
            'email' => 'dawn@plussave.test',
            'password' => 'Password123',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_open_session_is_closed_when_the_window_shuts(): void
    {
        $user = $this->clerk('closing@plussave.test', [
            'allowed_from' => '08:00',
            'allowed_to' => '18:00',
        ]);

        Carbon::setTestNow('2026-10-08 17:50:00');
        $this->actingAs($user);
        $this->get(route('dashboard'))->assertSuccessful();

        Carbon::setTestNow('2026-10-08 18:05:00');
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_bulk_deactivation_stops_those_accounts_and_leaves_the_actor_alone(): void
    {
        $actor = $this->actor();
        $first = $this->clerk('bulk1@plussave.test');
        $second = $this->clerk('bulk2@plussave.test');

        $this->actingAs($actor);

        $this->post(route('users.bulk'), [
            'action' => 'deactivate',
            'user_ids' => [$first->id, $second->id, $actor->id],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertFalse($first->fresh()->is_active);
        $this->assertFalse($second->fresh()->is_active);
        $this->assertTrue($actor->fresh()->is_active);
        $this->assertStringContainsString('Skipped 1 your own account', session('success'));
    }

    public function test_bulk_hours_are_applied_and_can_be_cleared_again(): void
    {
        $actor = $this->actor();
        $user = $this->clerk('hours@plussave.test');

        $this->actingAs($actor);

        $this->post(route('users.bulk'), ['action' => 'window_weekdays', 'user_ids' => [$user->id]]);

        $user = $user->fresh();
        $this->assertSame('Monday to Friday 08:00 to 18:00', $user->loginWindowSummary());

        $this->post(route('users.bulk'), ['action' => 'window_clear', 'user_ids' => [$user->id]]);

        $this->assertFalse($user->fresh()->hasLoginWindow());
    }

    public function test_an_admin_cannot_bulk_lock_a_superadmin_account(): void
    {
        $admin = $this->actor('admin');
        $boss = $this->clerk('boss@plussave.test');
        $boss->syncRoles(['superadmin']);

        $this->actingAs($admin);

        $response = $this->post(route('users.bulk'), ['action' => 'lock', 'user_ids' => [$boss->id]]);


        $this->assertFalse($boss->fresh()->is_locked);
        $this->assertStringContainsString('Nothing was changed', $this->flashedError('error'));
        $this->assertStringContainsString('superadmin', $this->flashedError('error'));
    }

    public function test_an_agent_cannot_run_bulk_actions(): void
    {
        $agent = $this->clerk('nosy@plussave.test');
        $other = $this->clerk('peer@plussave.test');

        $this->actingAs($agent);

        $this->post(route('users.bulk'), ['action' => 'deactivate', 'user_ids' => [$other->id]])
            ->assertForbidden();

        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_the_list_can_be_narrowed_to_restricted_accounts(): void
    {
        $actor = $this->actor();
        $this->clerk('free@plussave.test', ['name' => 'Free Clerk']);
        $this->clerk('held@plussave.test', ['name' => 'Held Clerk', 'allowed_days' => '1,2,3,4,5']);

        $this->actingAs($actor);

        $restricted = $this->get(route('users.index', ['access' => 'restricted']))
            ->viewData('users')
            ->getCollection();

        $this->assertSame(['Held Clerk'], $restricted->pluck('name')->all());

        $open = $this->get(route('users.index', ['access' => 'open']))
            ->viewData('users')
            ->getCollection();

        $this->assertContains('Free Clerk', $open->pluck('name')->all());
        $this->assertStringNotContainsString('Held Clerk', $this->get(route('users.index', ['access' => 'open']))->getContent());
    }
}
