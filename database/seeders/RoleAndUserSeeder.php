<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class RoleAndUserSeeder extends Seeder
{
    /**
     * Demo accounts. Passwords come from the environment, or are generated and
     * printed once, so no working credential ever lands in git.
     */
    private const DEMO_ACCOUNTS = [
        ['email' => 'superadmin@plussave.com', 'name' => 'Super Admin', 'role' => 'superadmin', 'env' => 'SEED_SUPERADMIN_PASSWORD'],
        ['email' => 'admin@plussave.com', 'name' => 'Admin', 'role' => 'admin', 'env' => 'SEED_ADMIN_PASSWORD'],
        ['email' => 'agent@plussave.com', 'name' => 'Agent', 'role' => 'agent', 'env' => 'SEED_AGENT_PASSWORD'],
        ['email' => 'accountant@plussave.com', 'name' => 'Accountant', 'role' => 'accountant', 'env' => 'SEED_ACCOUNTANT_PASSWORD'],
    ];

    public function run(): void
    {
        foreach (['superadmin', 'admin', 'agent', 'accountant'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        foreach (self::DEMO_ACCOUNTS as $account) {
            if (app()->environment('production') && $account['role'] !== 'superadmin') {
                $this->warn("Skipped {$account['email']} - demo accounts are not created on production.");
                continue;
            }

            $existed = User::where('email', $account['email'])->exists();

            $password = env($account['env']) ?: Str::random(20);

            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'phone' => '',
                    'password' => $password,
                    'is_active' => true,
                ]
            );
            $user->assignRole($account['role']);

            if (!$existed) {
                $this->info("Created {$account['email']} with password: {$password}");
                $this->info('Store that password somewhere safe. It is not saved in the repository.');
            }
        }
    }
}
