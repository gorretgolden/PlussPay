<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Role;

/** Stable application abilities. Labels and grouping are presentation, never authority. */
final class PermissionCatalog
{
    public const MODULES = [
        'Users' => ['view-users', 'create-user', 'edit-user', 'delete-user', 'toggle-user-status', 'reset-user-password', 'lock-user', 'manage-user-two-factor', 'assign-roles', 'export-users'],
        'Roles' => ['view-roles', 'create-role', 'edit-role', 'delete-role', 'assign-permissions', 'seed-permissions'],
        'Transactions' => ['view-dashboard', 'view-transactions', 'view-own-transactions', 'view-all-transactions', 'edit-transaction', 'delete-transaction', 'approve-transaction', 'export-transactions'],
        'Send Money' => ['create-transaction', 'bulk-import-transactions', 'view-exchanges', 'view-all-exchanges', 'create-exchange', 'void-exchange', 'override-exchange-rate', 'export-exchanges'],
        'China Desk' => ['send-to-china', 'confirm-china-payout'],
        'Exchange Rates' => ['view-exchange-rates', 'manage-exchange-rates', 'export-exchange-rates'],
        'Wallets' => ['view-wallets', 'view-all-wallets', 'adjust-wallets'],
        'Receipts' => ['view-receipts', 'print-receipt', 'export-receipts'],
        'Reports' => ['view-reports', 'export-reports', 'view-profit', 'view-agent-performance'],
        'Settings' => ['view-settings', 'manage-settings', 'manage-system-settings'],
        'Customers' => ['view-customers', 'create-customer', 'edit-customer', 'delete-customer', 'export-customers'],
        'Accounts' => ['view-accounts', 'create-account-entry', 'export-accounts'],
        'Expenses' => ['view-expenses', 'create-expense', 'void-expense', 'export-expenses', 'manage-expense-categories'],
        'Logs' => ['view-activity-logs', 'view-login-logs', 'export-logs', 'view-system-logs', 'resolve-system-logs', 'export-system-logs'],
    ];

    public const RESERVED = ['seed-permissions', 'manage-system-settings', 'view-system-logs', 'resolve-system-logs', 'export-system-logs'];

    public const DESCRIPTIONS = [
        'superadmin' => 'Full access to every module. This protected role cannot be changed.',
        'admin' => 'Manages daily operations, people and permissions. System internals stay with Super Admin.',
        'accountant' => 'Reads all books, customers, profit and exchange rates. No editing or settings access.',
        'agent' => 'Books transfers and exchanges. Sees only their own transactions, receipts, reports and wallets.',
        'staff' => 'Desk staff. Reads their own transactions, receipts and reports; books nothing and changes nothing.',
    ];

    public static function names(): array
    {
        return array_merge(...array_values(self::MODULES));
    }

    public static function module(string $name): string
    {
        foreach (self::MODULES as $module => $names) {
            if (in_array($name, $names, true)) {
                return $module;
            }
        }
        return 'Other';
    }

    public static function label(string $name): string
    {
        return ucfirst(str_replace('-', ' ', $name));
    }

    public static function sensitive(string $name): bool
    {
        return in_array($name, self::RESERVED, true)
            || str_starts_with($name, 'view-all-')
            || preg_match('/^(delete|approve|void|manage|assign|adjust|reset|lock|bulk|override)-/', $name)
            || $name === 'view-profit';
    }

    public static function defaults(): array
    {
        $read = [
            'view-dashboard', 'view-transactions', 'view-own-transactions', 'view-all-transactions',
            'export-transactions', 'view-exchanges', 'view-all-exchanges', 'export-exchanges',
            'view-wallets', 'view-all-wallets', 'view-receipts', 'print-receipt', 'export-receipts',
            'view-reports', 'export-reports', 'view-profit', 'view-agent-performance',
            'view-customers', 'export-customers', 'view-accounts', 'export-accounts',
            'view-expenses', 'export-expenses', 'view-exchange-rates', 'export-exchange-rates',
        ];

        return [
            'superadmin' => self::names(),
            'admin' => array_values(array_diff(self::names(), self::RESERVED)),
            // The old books gate opened customers, expenses and account entries to the accountant
            // for writing as well as reading, so those abilities stay in this grant. People,
            // prices and settings were never in it.
            'accountant' => array_merge($read, [
                'create-customer', 'edit-customer', 'delete-customer',
                'create-expense', 'void-expense', 'create-account-entry',
            ]),
            'agent' => [
                'view-dashboard', 'create-transaction', 'view-transactions', 'view-own-transactions',
                'export-transactions', 'view-exchanges', 'create-exchange', 'override-exchange-rate', 'export-exchanges',
                'view-wallets', 'view-receipts', 'print-receipt', 'export-receipts', 'view-reports', 'export-reports',
                'send-to-china', 'confirm-china-payout',
            ],
            // Desk staff booked nothing under the old role gates and saw only their own records,
            // so the grant below is that same reach expressed as abilities.
            'staff' => [
                'view-dashboard', 'view-transactions', 'view-own-transactions', 'export-transactions',
                'view-wallets', 'view-receipts', 'print-receipt', 'export-receipts',
                'view-reports', 'export-reports',
            ],
        ];
    }

    public static function mayDelegate(User $actor, string $ability): bool
    {
        return $actor->hasRole('superadmin')
            || (!in_array($ability, self::RESERVED, true) && $actor->can($ability));
    }

    /** Use this for every user role-assignment path as well as the option list. */
    public static function canAssignRole(User $actor, Role $role): bool
    {
        if ($role->guard_name !== 'web' || in_array($role->name, ['public', 'customer'], true)) {
            return false;
        }
        if ($role->name === 'superadmin') {
            return $actor->hasRole('superadmin');
        }
        return $actor->can('assign-roles') && $role->permissions->every(fn ($p) => self::mayDelegate($actor, $p->name));
    }
}
