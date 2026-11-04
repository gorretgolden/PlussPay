<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PREVIOUS_SUMMARY = 'Books transfers and logs what they spent out of the cash in hand. Sees only their own transactions, expenses, receipts, reports and wallets.';

    /**
     * She ruled (2026-11-04) that the agent role does no expenses at all, so the spending desk
     * comes off their board, their sidebar and their routes in one move: every door of the module
     * hangs on these two abilities. Recording what a desk paid out is the lead agent's and the
     * office's job now. Entries an agent recorded before this change stay in the book untouched.
     * Roles page grants are left alone: this only removes what the standard agent role was seeded
     * with.
     */
    public function up(): void
    {
        $agent = Role::where('guard_name', 'web')->where('name', 'agent')->first();

        if ($agent) {
            $held = $agent->permissions->pluck('name')->all();
            $gone = array_values(array_intersect($held, ['view-expenses', 'create-expense']));

            if ($gone) {
                $agent->revokePermissionTo($gone);
            }

            // The line under the role name is kept honest, but only while it still carries the
            // wording the seeder wrote. A summary typed in by hand stays as it is.
            if ($agent->description === self::PREVIOUS_SUMMARY) {
                $agent->forceFill(['description' => PermissionCatalog::DESCRIPTIONS['agent']])->save();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $agent = Role::where('guard_name', 'web')->where('name', 'agent')->first();

        if ($agent) {
            $agent->givePermissionTo(['view-expenses', 'create-expense']);

            if ($agent->description === PermissionCatalog::DESCRIPTIONS['agent']) {
                $agent->forceFill(['description' => self::PREVIOUS_SUMMARY])->save();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
