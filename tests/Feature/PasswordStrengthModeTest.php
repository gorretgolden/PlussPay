<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A desk in Testing works from demo accounts and sample figures, and the password it is handed is
 * the kind a customer would never be allowed to keep: 12345678, all digits. Production is the one
 * that has to hold the letters requirement, and the field has to say which of the two it is under
 * before anybody types, not after.
 */
class PasswordStrengthModeTest extends TestCase
{
    use RefreshDatabase;

    /** The controller enforces a unique phone as well as a unique email, so each booking needs a fresh line. */
    private static int $line = 1;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['admin', 'staff'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        foreach (['view-users', 'create-user', 'edit-user'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        Role::findByName('admin')->givePermissionTo(['view-users', 'create-user', 'edit-user']);
    }

    private function mode(string $siteMode): void
    {
        Setting::set('site_mode', $siteMode, 'string', 'display');
    }

    private function admin(): User
    {
        $user = User::create([
            'name' => 'Desk Holder', 'email' => 'admin.'.Str::random(8).'@example.test',
            'password' => 'Password123', 'is_active' => true,
        ]);
        $user->assignRole('admin');

        return $user;
    }

    private function book(string $password, string $siteMode): \Illuminate\Testing\TestResponse
    {
        $this->mode($siteMode);

        return $this->actingAs($this->admin())->post(route('users.store'), [
            'name' => 'New Agent', 'email' => 'weak.'.Str::random(8).'@example.test',
            'phone' => '+256700'.str_pad((string) self::$line++, 7, '0', STR_PAD_LEFT),
            'country' => 'UG', 'role' => 'staff', 'timezone' => 'Africa/Kampala',
            'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    public function test_a_digits_only_password_is_kept_while_the_site_is_in_testing(): void
    {
        $this->book('12345678', 'testing')->assertRedirect();

        $created = User::where('name', 'New Agent')->firstOrFail();
        $this->assertTrue(Hash::check('12345678', $created->password));
    }

    public function test_a_digits_only_password_is_refused_once_the_site_is_in_production(): void
    {
        $this->book('12345678', 'production')->assertSessionHasErrors('password');

        $this->assertNull(User::where('name', 'New Agent')->first());
    }

    public function test_letters_still_clear_the_rule_in_both_modes(): void
    {
        // The relaxation is only ever about letters. A password that already met the old rule must
        // keep working whichever way the switch is set, or the demo desk cannot use its own names.
        $this->book('Password123', 'testing')->assertSessionHasNoErrors();
        $this->book('Password123', 'production')->assertSessionHasNoErrors();

        $this->assertSame(2, User::where('name', 'New Agent')->count());
    }

    public function test_an_owner_can_move_to_a_digits_only_password_while_testing(): void
    {
        $this->mode('testing');
        $owner = $this->admin();

        $this->actingAs($owner)->post('/password/change-required', [
            'current_password' => 'Password123',
            'password' => '12345678', 'password_confirmation' => '12345678',
        ])->assertRedirect(route('dashboard'));

        $this->assertTrue(Hash::check('12345678', $owner->fresh()->password));
    }

    public function test_an_owner_is_stopped_from_a_digits_only_password_in_production(): void
    {
        $this->mode('production');
        $owner = $this->admin();

        $this->actingAs($owner)->post('/password/change-required', [
            'current_password' => 'Password123',
            'password' => '12345678', 'password_confirmation' => '12345678',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('Password123', $owner->fresh()->password));
    }

    public function test_the_password_field_shows_the_rule_the_site_is_actually_under(): void
    {
        $admin = $this->admin();

        $this->mode('testing');
        $testing = $this->actingAs($admin)->get(route('users.create'))->getContent();
        $this->assertStringContainsString('pattern="(?=.*[0-9]).{8,72}"', $testing);
        $this->assertStringNotContainsString('(?=.*[A-Za-z])', $testing, 'The box must not ask for letters the controller does not.');

        $this->flushSession();
        $this->mode('production');
        $production = $this->actingAs($admin)->get(route('users.create'))->getContent();
        $this->assertStringContainsString('pattern="(?=.*[A-Za-z])(?=.*[0-9]).{8,72}"', $production);
    }
}
