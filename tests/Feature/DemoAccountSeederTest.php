<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleAndUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Env;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DemoAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'SEED_SUPERADMIN_PASSWORD',
        'SEED_ADMIN_PASSWORD',
        'SEED_AGENT_PASSWORD',
        'SEED_CHINA_AGENT_PASSWORD',
        'SEED_ACCOUNTANT_PASSWORD',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['superadmin', 'admin', 'agent', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        // The env repository is one object for the whole test process, so a key left standing by
        // an earlier test would quietly decide a password in a later one.
        $this->clearSeedPasswords();
    }

    protected function tearDown(): void
    {
        $this->clearSeedPasswords();

        parent::tearDown();
    }

    private function clearSeedPasswords(): void
    {
        $repository = Env::getRepository();

        foreach (self::KEYS as $key) {
            $repository->clear($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    /**
     * Write the keys the live .env is supposed to carry through the same repository the seeder
     * reads with env(), so the test proves the chosen password reaches the account.
     */
    private function setSeedPasswords(array $values): void
    {
        $repository = Env::getRepository();

        foreach ($values as $key => $value) {
            $repository->set($key, $value);
        }
    }

    public function test_the_demo_accounts_exist_on_production_so_the_login_buttons_have_targets(): void
    {
        $this->app['env'] = 'production';
        $this->assertSame('production', $this->app->environment());

        $this->setSeedPasswords([
            'SEED_SUPERADMIN_PASSWORD' => 'super-secret-one',
            'SEED_ADMIN_PASSWORD' => 'admin-secret-two',
            'SEED_AGENT_PASSWORD' => 'agent-secret-three',
            'SEED_CHINA_AGENT_PASSWORD' => 'china-secret-four',
            'SEED_ACCOUNTANT_PASSWORD' => 'books-secret-five',
        ]);

        // The same command she will run on the live box, --force included, because db:seed
        // otherwise stops and asks before touching a production database.
        $this->artisan('db:seed', ['--class' => RoleAndUserSeeder::class, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame([
            'accountant@plussave.com',
            'admin@plussave.com',
            'agent@plussave.com',
            'china.agent@plussave.com',
            'superadmin@plussave.com',
        ], User::orderBy('email')->pluck('email')->all());

        $expected = [
            'admin@plussave.com' => 'admin',
            'agent@plussave.com' => 'agent',
            'china.agent@plussave.com' => 'agent',
            'accountant@plussave.com' => 'accountant',
            'superadmin@plussave.com' => 'superadmin',
        ];

        foreach ($expected as $email => $role) {
            $this->assertTrue(
                User::where('email', $email)->whereHas('roles', fn ($q) => $q->where('name', $role))->exists(),
                "{$email} should hold the {$role} role"
            );
        }

        $passwords = [
            'superadmin@plussave.com' => 'super-secret-one',
            'admin@plussave.com' => 'admin-secret-two',
            'agent@plussave.com' => 'agent-secret-three',
            'china.agent@plussave.com' => 'china-secret-four',
            'accountant@plussave.com' => 'books-secret-five',
        ];

        foreach ($passwords as $email => $password) {
            $this->assertTrue(Hash::check($password, User::where('email', $email)->value('password')), $email);
        }

        // The China desk gets its own country and clock, the Uganda accounts share Kampala.
        $this->assertSame('CN', User::where('email', 'china.agent@plussave.com')->value('country'));
        $this->assertSame('Asia/Shanghai', User::where('email', 'china.agent@plussave.com')->value('timezone'));
        $this->assertSame('Africa/Kampala', User::where('email', 'admin@plussave.com')->value('timezone'));
    }

    public function test_a_seeded_demo_account_signs_straight_in_with_its_env_password(): void
    {
        $this->setSeedPasswords(['SEED_ADMIN_PASSWORD' => 'admin-secret-two']);

        $this->seed(RoleAndUserSeeder::class);

        $admin = User::where('email', 'admin@plussave.com')->firstOrFail();

        $this->assertTrue($admin->is_active);
        $this->assertFalse((bool) $admin->two_factor_enabled);
        $this->assertTrue(Auth::attempt(['email' => 'admin@plussave.com', 'password' => 'admin-secret-two']));
        $this->assertFalse(Auth::attempt(['email' => 'admin@plussave.com', 'password' => 'wrong-one']));
    }

    public function test_seeding_again_leaves_an_existing_account_password_alone(): void
    {
        $boss = User::create([
            'name' => 'Existing Boss',
            'email' => 'superadmin@plussave.com',
            'password' => 'her-own-long-standing-password',
            'is_active' => true,
        ]);
        $boss->assignRole('superadmin');

        $this->setSeedPasswords([
            'SEED_SUPERADMIN_PASSWORD' => 'a-brand-new-value',
            'SEED_ADMIN_PASSWORD' => 'admin-secret-two',
        ]);

        $this->seed(RoleAndUserSeeder::class);

        // Matched on the email, so the row is never rebuilt and her own password survives.
        $this->assertTrue(Hash::check('her-own-long-standing-password', $boss->fresh()->password));
        $this->assertFalse(Hash::check('a-brand-new-value', $boss->fresh()->password));
        $this->assertTrue(Hash::check('admin-secret-two', User::where('email', 'admin@plussave.com')->value('password')));
        $this->assertSame(5, User::count());
    }

    public function test_an_account_with_no_key_set_is_still_created_and_active(): void
    {
        $this->seed(RoleAndUserSeeder::class);

        $agent = User::where('email', 'agent@plussave.com')->firstOrFail();

        // Nothing was chosen in .env, so the seeder made one up and printed it once. The row is
        // still a normal account, which is what the login page's buttons need.
        $this->assertTrue($agent->is_active);
        $this->assertStringStartsWith('$2y$', (string) $agent->password);
        $this->assertTrue($agent->hasRole('agent'));
    }
}
