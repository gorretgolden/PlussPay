<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The lead agent already sends the dollars for the Uganda bookings, so clearing the queue on
     * the other side is the rest of that job. The permission seeder never resyncs a role that has
     * already been initialised, which keeps hand-made grants intact, so the one new pen is added
     * here and nothing else on the role is touched.
     */
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::firstOrCreate(['name' => 'confirm-china-payout', 'guard_name' => 'web']);
        $role = Role::where('guard_name', 'web')->where('name', 'super-agent')->first();

        if ($role && ! $role->permissions->contains('name', $permission->name)) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('guard_name', 'web')->where('name', 'super-agent')->first();

        if ($role && $role->permissions->contains('name', 'confirm-china-payout')) {
            $role->revokePermissionTo('confirm-china-payout');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
