<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Blocking and locking are now one switch, so the separate lock ability has no code
 * behind it. Deleting the permission also drops it from every role that held it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::where('name', 'lock-user')->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::firstOrCreate(['name' => 'lock-user', 'guard_name' => 'web']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
