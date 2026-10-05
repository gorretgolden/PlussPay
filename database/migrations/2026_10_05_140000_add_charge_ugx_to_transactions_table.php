<?php

use App\Models\ExchangeRate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'charge_ugx')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('charge_ugx', 20, 2)->default(0)->after('charge_amount');
        });

        // Rows created before the fee was quoted in UGX only hold the USD charge.
        $rate = ExchangeRate::usdToUgx();
        if ($rate) {
            DB::table('transactions')->where('charge_ugx', 0)
                ->update(['charge_ugx' => DB::raw('charge_amount * ' . $rate)]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transactions', 'charge_ugx')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('charge_ugx');
        });
    }
};
