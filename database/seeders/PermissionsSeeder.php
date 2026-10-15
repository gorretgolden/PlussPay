<?php

namespace Database\Seeders;

use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSeeder extends Seeder
{
    /** Deployment: php artisan db:seed --class=PermissionsSeeder --force */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            $unseeded = !Permission::where('guard_name', 'web')->exists();
            foreach (PermissionCatalog::names() as $name) {
                Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }

            foreach (PermissionCatalog::defaults() as $name => $defaults) {
                $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
                // Never sync existing grants. Even an intentionally emptied role stays empty.
                // The durable marker also prevents deleting the catalog from resetting roles.
                if ($role->permissions_initialized_at === null) {
                    if ($role->wasRecentlyCreated || $unseeded) {
                        $role->givePermissionTo($defaults);
                    }
                    $role->forceFill([
                        'permissions_initialized_at' => now(),
                        'description' => $role->description ?? PermissionCatalog::DESCRIPTIONS[$name],
                    ])->save();
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
