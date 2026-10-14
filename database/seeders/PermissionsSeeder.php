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
            // Dashboard
            'view-dashboard',

            // Transactions
            'create-transaction',
            'view-transactions',
            'view-own-transactions',
            'edit-transaction',
            'delete-transaction',
            'approve-transaction',
            'bulk-import-transactions',

            // Receipts
            'view-receipts',
            'print-receipt',

            // Reports
            'view-reports',
            'export-reports',

            // Customers
            'view-customers',
            'create-customer',
            'edit-customer',
            'delete-customer',

            // Users
            'view-users',
            'create-user',
            'edit-user',
            'delete-user',
            'toggle-user-status',
            'reset-user-password',
            'lock-user',

            // Roles & Permissions
            'view-roles',
            'create-role',
            'edit-role',
            'delete-role',
            'assign-permissions',

            // Exchange Rates
            'view-exchange-rates',
            'manage-exchange-rates',

            // Settings
            'view-settings',
            'manage-settings',

            // Agent Performance
            'view-agent-performance',

            // Audit Logs
            'view-activity-logs',
            'view-login-logs',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        // Super Admin: all permissions
        $superadmin = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'web']);
        $superadmin->syncPermissions(Permission::all());

        // Admin: all except deleting superadmin-level things
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions($permissions);

        // Agent: day-to-day operations
        $agent = Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web']);
        $agent->syncPermissions([
            'view-dashboard',
            'create-transaction',
            'view-transactions',
            'view-own-transactions',
            'view-receipts',
            'print-receipt',
            'view-reports',
            'view-customers',
            'create-customer',
        ]);

        // Accountant: the books, the ledger and every profit figure, without admin powers
        $accountant = Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $accountant->syncPermissions([
            'view-dashboard',
            'view-transactions',
            'view-receipts',
            'print-receipt',
            'view-reports',
            'export-reports',
            'view-customers',
            'create-customer',
            'edit-customer',
            'view-agent-performance',
        ]);
    }
}
