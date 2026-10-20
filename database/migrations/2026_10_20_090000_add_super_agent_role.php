<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PREVIOUS_AGENT_SUMMARY = 'Books transfers and exchanges and logs what they spent out of the cash in hand. Sees only their own transactions, expenses, receipts, reports and wallets.';

    /**
     * The abilities the super agent takes over from the ordinary agent: the exchange desk and the
     * send to China. The agent keeps booking, their own page of the book and confirming a payout in
     * China: the first two are the work every agent does and the last belongs to the desk that hands
     * the receiver their money.
     */
    private const MOVED_TO_SUPER_AGENT = [
        'view-exchanges', 'create-exchange', 'override-exchange-rate', 'export-exchanges', 'send-to-china',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionCatalog::defaults()['super-agent'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Permission::firstOrCreate(['name' => 'approve-cash-handover', 'guard_name' => 'web']);

        $superAgent = Role::firstOrCreate(
            ['name' => 'super-agent', 'guard_name' => 'web'],
            ['description' => PermissionCatalog::DESCRIPTIONS['super-agent']],
        );

        $superAgent->givePermissionTo(PermissionCatalog::defaults()['super-agent']);
        $superAgent->forceFill([
            'description' => PermissionCatalog::DESCRIPTIONS['super-agent'],
            'permissions_initialized_at' => $superAgent->permissions_initialized_at ?? now(),
        ])->save();

        $agent = Role::where('guard_name', 'web')->where('name', 'agent')->first();

        if ($agent) {
            $gone = array_values(array_intersect($agent->permissions->pluck('name')->all(), self::MOVED_TO_SUPER_AGENT));

            if ($gone) {
                $agent->revokePermissionTo($gone);
            }

            // Only replace the line the seeder wrote. A summary typed in by hand is left alone.
            if ($agent->description === self::PREVIOUS_AGENT_SUMMARY) {
                $agent->forceFill(['description' => PermissionCatalog::DESCRIPTIONS['agent']])->save();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $agent = Role::where('guard_name', 'web')->where('name', 'agent')->first();

        if ($agent) {
            $agent->givePermissionTo(self::MOVED_TO_SUPER_AGENT);

            if ($agent->description === PermissionCatalog::DESCRIPTIONS['agent']) {
                $agent->forceFill(['description' => self::PREVIOUS_AGENT_SUMMARY])->save();
            }
        }

        $superAgent = Role::where('guard_name', 'web')->where('name', 'super-agent')->first();

        if ($superAgent) {
            $superAgent->syncPermissions([]);
            $superAgent->delete();
        }

        Permission::where('name', 'approve-cash-handover')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
