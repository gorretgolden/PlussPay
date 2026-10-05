<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'view-dashboard',
            'create-transaction',
            'view-transaction',
            'view-own-transactions',
            'view-all-transactions',
            'approve-transaction',
            'view-receipt',
            'print-receipt',
            'view-reports',
            'export-reports',
            'view-customers',
            'create-customers',
            'edit-customers',
            'delete-customers',
            'view-users',
            'create-users',
            'edit-users',
            'view-exchange-rates',
            'manage-exchange-rates',
            'view-settings',
            'manage-settings',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superadmin = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $superadmin->syncPermissions($permissions);

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($permissions);

        $agent = Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
        $agent->syncPermissions([
            'view-dashboard',
            'create-transaction',
            'view-own-transactions',
            'view-receipt',
            'print-receipt',
            'view-reports',
            'view-customers',
        ]);

        $staff = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff->syncPermissions([
            'view-dashboard',
            'view-own-transactions',
            'view-receipt',
            'print-receipt',
        ]);
    }
}
