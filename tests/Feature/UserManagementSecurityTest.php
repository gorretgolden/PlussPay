<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        foreach (['superadmin', 'admin', 'agent', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        foreach (['create-user', 'edit-user', 'view-users', 'export-users', 'delete-user'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        Role::findByName('admin')->givePermissionTo(['create-user', 'edit-user', 'view-users']);
    }

    private function user(string $role = 'agent', array $attributes = []): User
    {
        $user = User::create($attributes + [
            'name' => 'Private Holder', 'email' => uniqid().'@example.test',
            'password' => 'Password123', 'is_active' => true,
        ]);
        $user->assignRole($role);
        return $user;
    }

    private function payload(?User $user = null): array
    {
        return [
            'name' => $user?->name ?? 'New Person',
            'email' => $user?->email ?? uniqid().'@example.test',
            'role' => $user?->getRoleNames()->first() ?? 'agent',
            'is_active' => 1,
            'password' => 'Temporary123!', 'password_confirmation' => 'Temporary123!',
        ];
    }

    public function test_creating_a_user_stamps_who_added_them_and_editing_stamps_the_last_editor(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);

        $data = $this->payload();

        $this->post(route('users.store'), $data)
            ->assertRedirect(route('users.index'));

        $created = User::where('email', $data['email'])->first();
        $this->assertNotNull($created);
        $this->assertSame($admin->id, $created->created_by);
        $this->assertNull($created->updated_by);

        $this->actingAs($this->user('admin'));
        $this->put(route('users.update', $created), ['name' => 'Edited Person'] + $this->payload($created))
            ->assertRedirect();

        $created->refresh();
        $this->assertSame($admin->id, $created->created_by);
        $this->assertNotNull($created->updated_by);
        $this->assertNotSame($admin->id, $created->updated_by);
        $this->assertSame('Edited Person', $created->name);
    }

    public function test_a_sign_in_never_marks_the_account_as_edited(): void
    {
        $user = $this->user('agent');
        $user->recordSuccessfulLogin('127.0.0.1');

        $user->refresh();
        $this->assertNull($user->updated_by);
        $this->assertNotNull($user->last_login_at);
    }

    public function test_duplicate_create_and_update_never_identify_the_holder_or_flash_passwords(): void
    {
        $this->actingAs($this->user('superadmin'));
        $holder = $this->user('agent', ['phone' => '+256751657654']);
        $target = $this->user();
        foreach (['email' => strtoupper($holder->email), 'phone' => '0751 657 654'] as $field => $value) {
            $payload = array_replace($this->payload(), [$field => $value]);
            $this->post('/users', $payload)->assertSessionHasErrors($field);
            $this->assertSame("This {$field} is already registered.", $this->flashedError($field));
            $this->assertStringNotContainsString($holder->name, $this->flashedError($field));
            $this->assertNull(session('_old_input.password'));
            $this->assertNull(session('_old_input.password_confirmation'));
            $this->put('/users/'.$target->id, array_replace($this->payload($target), [$field => $value]))->assertSessionHasErrors($field);
            $this->assertNull(session('_old_input.password'));
            $this->assertSame("This {$field} is already registered.", $this->flashedError($field));
        }
    }

    public function test_invalid_form_input_never_flashes_password_fields(): void
    {
        $this->actingAs($this->user('superadmin'))->post('/users', array_replace($this->payload(), ['name' => 'One']))->assertSessionHasErrors('name');
        $this->assertNull(session('_old_input.password'));
        $this->assertNull(session('_old_input.password_confirmation'));
    }

    public function test_self_deactivation_is_refused_by_update_toggle_and_bulk(): void
    {
        $actor = $this->user('superadmin');
        $this->user('superadmin');
        $this->actingAs($actor)->put('/users/'.$actor->id, array_replace($this->payload($actor), ['is_active' => 0]))->assertSessionHasErrors('is_active');
        $this->patch('/users/'.$actor->id.'/toggle-active')->assertSessionHasErrors('error');
        $this->patch('/users/'.$actor->id.'/toggle-lock')->assertSessionHasErrors('error');
        $this->post('/users/bulk', ['action' => 'deactivate', 'user_ids' => [$actor->id]])->assertSessionHasErrors('error');
        $this->assertTrue($actor->fresh()->is_active);
        $this->assertFalse($actor->fresh()->isLocked());
    }

    public function test_locked_or_inactive_superadmins_do_not_count_as_a_recovery_account(): void
    {
        $actor = $this->user('superadmin', ['is_locked' => true]);
        $target = $this->user('superadmin');
        // Execute the authorized controller request without an active-account gate;
        // this models a second session racing with its own lock/deactivation.
        $this->withoutMiddleware(\App\Http\Middleware\EnsureAccountIsActive::class);
        $this->actingAs($actor);
        $this->put('/users/'.$target->id, array_replace($this->payload($target), ['is_active' => 0]))->assertSessionHasErrors('is_active');
        $this->put('/users/'.$target->id, array_replace($this->payload($target), ['role' => 'agent']))->assertSessionHasErrors('role');
        $this->patch('/users/'.$target->id.'/toggle-active')->assertSessionHasErrors('error');
        $this->patch('/users/'.$target->id.'/toggle-lock')->assertSessionHasErrors('error');
        $this->post('/users/bulk', ['action' => 'lock', 'user_ids' => [$target->id]])->assertSessionHasErrors('error');
        $this->put('/users/'.$target->id, array_replace($this->payload($target), ['restrict_hours' => 1, 'days' => [1], 'allowed_from' => '09:00', 'allowed_to' => '12:00']))->assertSessionHasErrors('days');
        $this->assertTrue($target->fresh()->is_active);
        $this->assertFalse($target->fresh()->isLocked());
        $this->assertTrue($target->fresh()->hasRole('superadmin'));
    }

    public function test_bulk_cannot_remove_all_available_superadmins(): void
    {
        $actor = $this->user('superadmin', ['is_active' => false]);
        $first = $this->user('superadmin'); $second = $this->user('superadmin');
        $this->withoutMiddleware(\App\Http\Middleware\EnsureAccountIsActive::class);
        $this->actingAs($actor)->post('/users/bulk', ['action' => 'deactivate', 'user_ids' => [$first->id, $second->id]])->assertSessionHas('success');
        $this->assertSame(1, User::role('superadmin')->where('is_active', true)->count());
    }

    public function test_bulk_hours_use_validated_days_and_times_not_a_preset(): void
    {
        $target = $this->user();
        $this->actingAs($this->user('superadmin'))->post('/users/bulk', [
            'action' => 'window_set', 'user_ids' => [$target->id], 'days' => [2, 4, 6], 'allowed_from' => '20:00', 'allowed_to' => '06:00',
        ])->assertSessionHas('success');
        $this->assertSame([2, 4, 6], $target->fresh()->allowedDayList());
        $this->assertSame('20:00', $target->fresh()->allowed_from);
        $this->post('/users/bulk', ['action' => 'window_set', 'user_ids' => [$target->id], 'days' => [9]])->assertSessionHasErrors(['days.0', 'allowed_from', 'allowed_to']);
        $this->post('/users/bulk', ['action' => 'window_weekdays', 'user_ids' => [$target->id]])->assertSessionHasErrors('action');
    }

    public function test_availability_requires_authentication_permission_and_authorized_ignore_scope(): void
    {
        $target = $this->user();
        $this->getJson('/users/availability?field=email&email=new@example.test')->assertUnauthorized();
        $this->actingAs($target)->getJson('/users/availability?field=email&email=new@example.test')->assertForbidden();
        $creator = $this->user('staff'); $creator->givePermissionTo('create-user');
        $this->actingAs($creator)->getJson('/users/availability?field=email&email=new@example.test')->assertExactJson(['available' => true]);
        $this->getJson('/users/availability?field=email&email=new@example.test&ignore_id='.$target->id)->assertForbidden();
        $boss = $this->user('superadmin');
        $this->actingAs($this->user('admin'))->getJson('/users/availability?field=email&email=new@example.test&ignore_id='.$boss->id)->assertForbidden();
    }

    public function test_availability_normalizes_values_and_only_returns_a_boolean(): void
    {
        $target = $this->user('agent', ['email' => 'mixed@example.test', 'phone' => '+256751657654']);
        $this->actingAs($this->user('admin'));
        foreach ([['email', ' MIXED@EXAMPLE.TEST '], ['phone', '+2560751657654'], ['phone', '0751 657 654']] as [$field, $value]) {
            $query = ['field' => $field, $field => $value];
            $this->getJson('/users/availability?'.http_build_query($query))->assertExactJson(['available' => false]);
            $this->getJson('/users/availability?'.http_build_query($query + ['ignore_id' => $target->id]))->assertExactJson(['available' => true]);
        }
        $this->getJson('/users/availability?field=email&email=invalid')->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->getJson('/users/availability?field=phone&phone=letters')->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    public function test_availability_is_throttled(): void
    {
        $this->actingAs($this->user('admin'));
        for ($i = 0; $i < 30; $i++) $this->getJson('/users/availability?field=email&email=new@example.test')->assertOk();
        $this->getJson('/users/availability?field=email&email=new@example.test')->assertStatus(429);
    }

    public function test_invitation_uses_the_broker_and_never_logs_passwords_or_tokens(): void
    {
        $this->actingAs($this->user('superadmin'))->post('/users', [
            'name' => 'New Owner', 'email' => 'owner@example.test', 'role' => 'agent', 'send_password_link' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect('/users');
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        Mail::assertSent(Mailable::class, function (Mailable $mail) use ($user) {
            $this->assertTrue($mail->hasTo($user->email));
            $url = $mail->viewData['url'];
            $this->assertNull(parse_url($url, PHP_URL_QUERY));
            parse_str(parse_url($url, PHP_URL_FRAGMENT), $fragment);
            $this->assertTrue(Password::tokenExists($user, $fragment['token']));
            $this->assertNotSame($fragment['token'], DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
            $this->assertStringNotContainsString($fragment['token'], ActivityLog::all()->toJson());
            return true;
        });
    }

    public function test_broker_token_is_single_use_and_clears_forced_change(): void
    {
        $user = $this->user('agent', ['must_change_password' => true]);
        $token = Password::createToken($user);
        $payload = ['email' => strtoupper($user->email), 'token' => $token, 'password' => 'MyPrivate456!', 'password_confirmation' => 'MyPrivate456!'];
        $this->postJson('/password/enroll', $payload)->assertOk()->assertJsonPath('redirect', route('login'));
        $this->assertTrue(Hash::check('MyPrivate456!', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->postJson('/password/enroll', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        $logs = ActivityLog::all()->toJson();
        $this->assertStringNotContainsString('MyPrivate456!', $logs);
        $this->assertStringNotContainsString($token, $logs);
        Mail::assertNothingSent();
    }

    public function test_expired_token_and_mismatched_passwords_cannot_reset_or_flash_credentials(): void
    {
        $user = $this->user(); $token = Password::createToken($user);
        $this->post('/password/enroll', ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123', 'password_confirmation' => 'Wrong123'])->assertSessionHasErrors('password');
        $this->assertNull(session('_old_input.password')); $this->assertNull(session('_old_input.token'));
        DB::table('password_reset_tokens')->where('email', $user->email)->update(['created_at' => now()->subDays(2)]);
        $this->postJson('/password/enroll', ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])->assertUnprocessable();
        $this->assertTrue(Hash::check('Password123', $user->fresh()->password));
    }

    public function test_forced_change_blocks_get_post_and_json_until_current_password_is_proven(): void
    {
        $user = $this->user('superadmin', ['must_change_password' => true]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.required'));
        $this->post('/users', $this->payload())->assertRedirect(route('password.required'));
        $this->getJson('/users/availability?field=email&email=new@example.test')->assertForbidden()->assertJsonPath('redirect', route('password.required'));
        $this->get('/password/change-required')->assertOk();
        $this->post('/password/change-required', ['current_password' => 'Wrong123', 'password' => 'BetterPassword456', 'password_confirmation' => 'BetterPassword456'])->assertSessionHasErrors('current_password');
        $this->assertTrue($user->fresh()->must_change_password);
        $this->post('/password/change-required', ['current_password' => 'Password123', 'password' => 'BetterPassword456', 'password_confirmation' => 'BetterPassword456'])->assertRedirect('/dashboard');
        $this->assertFalse($user->fresh()->must_change_password);
        $this->get('/dashboard')->assertOk();
    }

    public function test_admin_password_change_is_confirmed_and_force_flag_cannot_be_waived(): void
    {
        $target = $this->user('agent', ['must_change_password' => true]);
        $this->actingAs($this->user('superadmin'));
        $this->put('/users/'.$target->id, array_replace($this->payload($target), ['password_confirmation' => 'Wrong123']))->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('Password123', $target->fresh()->password));
        $this->put('/users/'.$target->id, array_replace($this->payload($target), ['must_change_password' => 0]))->assertSessionHasNoErrors();
        $this->assertTrue($target->fresh()->must_change_password);
        $this->assertStringNotContainsString('Temporary123!', ActivityLog::all()->toJson());
    }

    public function test_the_forced_password_change_is_a_choice_and_not_a_default(): void
    {
        $actor = $this->user('superadmin');
        $html = $this->actingAs($actor)->get('/users/create')->getContent();
        preg_match('/<input id="must_change_password"[^>]*>/', $html, $box);

        $this->assertNotEmpty($box[0] ?? '', 'The create form has to keep the box.');
        $this->assertStringNotContainsString('checked', $box[0], 'A new account changes its password only when the admin asks for it.');

        $this->post('/users', array_replace($this->payload(), ['email' => 'plain@example.test']))->assertSessionHasNoErrors();
        $this->assertFalse(User::where('email', 'plain@example.test')->firstOrFail()->must_change_password);

        $target = $this->user('agent', ['must_change_password' => true]);
        preg_match('/<input id="must_change_password"[^>]*>/', $this->get('/users/'.$target->id.'/edit')->getContent(), $kept);
        $this->assertMatchesRegularExpression('/checked/', $kept[0] ?? '', 'An account already told to change its password still shows that.');
    }

    public function test_forms_and_partial_table_render_the_new_controls(): void
    {
        $actor = $this->user('superadmin');
        $this->actingAs($actor)->get('/users/create')->assertOk()->assertSee('autocomplete="new-password"', false)->assertSee('data-unique', false)->assertSee('Restrict sign-in hours');
        $this->get('/users/'.$actor->id.'/edit')->assertOk()->assertSee('Locked on your own account');
        $this->get('/users')->assertOk()->assertSee('Name / Email')->assertSee('Country / Zone')->assertSee('More filters');
        $this->get('/users', ['X-Users-Partial' => '1'])->assertOk()->assertSee('user-table')->assertDontSee('<!DOCTYPE', false);
        $this->get('/password/enroll')->assertOk()->assertSee('Set your password');
    }
}
