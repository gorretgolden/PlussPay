<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The fee is two numbers now: the UGX charged and the USD block it applies
     * to, so the old combined key is renamed instead of left behind.
     */
    public function up(): void
    {
        $legacy = Setting::where('key', 'service_fee_ugx_per_100usd')->first();

        if ($legacy) {
            Setting::firstOrCreate(
                ['key' => 'service_fee_ugx'],
                [
                    'value' => $legacy->value,
                    'type' => 'float',
                    'group' => 'transaction',
                    'description' => 'Service fee charged in UGX for every service_fee_usd block sent',
                ]
            );
            $legacy->delete();
        }

        Setting::firstOrCreate(
            ['key' => 'service_fee_usd'],
            [
                'value' => '100',
                'type' => 'float',
                'group' => 'transaction',
                'description' => 'The USD amount the service fee is charged for, e.g. 100',
            ]
        );
    }

    public function down(): void
    {
        $fee = Setting::where('key', 'service_fee_ugx')->first();

        if ($fee) {
            Setting::updateOrCreate(
                ['key' => 'service_fee_ugx_per_100usd'],
                [
                    'value' => $fee->value,
                    'type' => 'float',
                    'group' => 'transaction',
                    'description' => 'Service fee charged in UGX for every 100 USD sent',
                ]
            );
            $fee->delete();
        }

        Setting::where('key', 'service_fee_usd')->delete();
    }
};
