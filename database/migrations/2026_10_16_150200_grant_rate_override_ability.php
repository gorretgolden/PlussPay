<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Giving a customer a price off the card is a counter deal that happens every day, so the
     * ability goes to the people who already book exchanges. What is new is that it is now a named
     * permission that can be taken away, and every use of it carries a written reason.
     */
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'override-exchange-rate', 'guard_name' => 'web']);

        foreach (['superadmin', 'admin', 'agent'] as $name) {
            $role = Role::where('guard_name', 'web')->where('name', $name)->first();

            if ($role && ! $role->hasPermissionTo($permission, false)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'override-exchange-rate')->first();

        if ($permission) {
            DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $permission->id)->delete();
            $permission->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
