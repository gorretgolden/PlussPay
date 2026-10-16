<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Sending the dollars across and confirming the yuan handed over are two separate hands on the
     * money, so they are two abilities: the desk in Uganda sends, the desk in China confirms. They
     * go to the people who already book and settle transfers. A role that should hold one of them
     * but was created here in the Roles page gets it ticked there, the same way every other
     * ability is granted after the catalog has been seeded.
     */
    private const ABILITIES = ['send-to-china', 'confirm-china-payout'];

    public function up(): void
    {
        foreach (self::ABILITIES as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            foreach (['superadmin', 'admin', 'agent'] as $roleName) {
                $role = Role::where('guard_name', 'web')->where('name', $roleName)->first();

                if ($role && ! $role->hasPermissionTo($permission, false)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::ABILITIES as $name) {
            $permission = Permission::where('name', $name)->first();

            if ($permission) {
                DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
                DB::table('model_has_permissions')->where('permission_id', $permission->id)->delete();
                $permission->delete();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
