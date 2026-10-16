<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The spending book used to be one room: whoever could open it saw every entry. An agent now
     * records what they paid out of the cash in their hand, so the door and the whole book are
     * pulled apart. view-expenses opens the screen, view-all-expenses is the part that reads other
     * people's entries, and an agent is deliberately given only the first plus the pen.
     *
     * Granted with givePermissionTo rather than a sync, so a role whose box was ticked by hand in
     * the Roles page keeps whatever else it holds.
     */
    public function up(): void
    {
        $everyBook = Permission::firstOrCreate(['name' => 'view-all-expenses', 'guard_name' => 'web']);
        $openDesk = Permission::firstOrCreate(['name' => 'view-expenses', 'guard_name' => 'web']);
        $writeEntry = Permission::firstOrCreate(['name' => 'create-expense', 'guard_name' => 'web']);

        foreach (['superadmin', 'admin', 'accountant'] as $roleName) {
            $role = Role::where('guard_name', 'web')->where('name', $roleName)->first();

            if ($role && ! $role->hasPermissionTo($everyBook, false)) {
                $role->givePermissionTo($everyBook);
            }
        }

        $agent = Role::where('guard_name', 'web')->where('name', 'agent')->first();

        if ($agent) {
            foreach ([$openDesk, $writeEntry] as $permission) {
                if (! $agent->hasPermissionTo($permission, false)) {
                    $agent->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $agent = Role::where('guard_name', 'web')->where('name', 'agent')->first();

        if ($agent) {
            $agent->revokePermissionTo(['view-expenses', 'create-expense']);
        }

        $everyBook = Permission::where('name', 'view-all-expenses')->first();

        if ($everyBook) {
            DB::table('role_has_permissions')->where('permission_id', $everyBook->id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $everyBook->id)->delete();
            $everyBook->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
