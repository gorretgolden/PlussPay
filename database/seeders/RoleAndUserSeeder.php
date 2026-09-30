<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleAndUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $agentRole = Role::firstOrCreate(['name' => 'agent']);
        $staffRole = Role::firstOrCreate(['name' => 'staff']);

        $admin = User::firstOrCreate(
            ['email' => 'admin@plussave.com'],
            [
                'name' => 'Admin',
                'phone' => '',
                'password' => bcrypt('admin123'),
                'is_active' => true,
            ]
        );
        $admin->assignRole($adminRole);

        $agent = User::firstOrCreate(
            ['email' => 'agent@plussave.com'],
            [
                'name' => 'Agent',
                'phone' => '',
                'password' => bcrypt('agent123'),
                'is_active' => true,
            ]
        );
        $agent->assignRole($agentRole);

        $staff = User::firstOrCreate(
            ['email' => 'staff@plussave.com'],
            [
                'name' => 'Staff',
                'phone' => '',
                'password' => bcrypt('staff123'),
                'is_active' => true,
            ]
        );
        $staff->assignRole($staffRole);
    }
}
