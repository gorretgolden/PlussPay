<?php

use Illuminate\Database\Migrations\Migration;
use App\Support\PermissionCatalog;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PREVIOUS_SUMMARY = 'Reads all books, customers, profit and exchange rates. No editing or settings access.';

    /**
     * The accountant sits above the spending book, not inside it. Their job is to check what the
     * office paid out and report on it, so the reading stays and the pen goes. An expense is
     * recorded by the desk that actually paid the money, which is the only place the amount and the
     * receipt can be seen. Roles page grants are left alone: this only removes what the standard
     * accountant role was seeded with.
     */
    public function up(): void
    {
        $accountant = Role::where('guard_name', 'web')->where('name', 'accountant')->first();

        if ($accountant) {
            $held = $accountant->permissions->pluck('name')->all();
            $gone = array_values(array_intersect($held, ['create-expense', 'void-expense']));

            if ($gone) {
                $accountant->revokePermissionTo($gone);
            }

            // The line under the role name is kept honest, but only while it still carries the
            // wording the seeder wrote. A summary typed in by hand stays as it is.
            if ($accountant->description === self::PREVIOUS_SUMMARY) {
                $accountant->forceFill(['description' => PermissionCatalog::DESCRIPTIONS['accountant']])->save();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $accountant = Role::where('guard_name', 'web')->where('name', 'accountant')->first();

        if ($accountant) {
            $accountant->givePermissionTo(['create-expense', 'void-expense']);

            if ($accountant->description === PermissionCatalog::DESCRIPTIONS['accountant']) {
                $accountant->forceFill(['description' => self::PREVIOUS_SUMMARY])->save();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
