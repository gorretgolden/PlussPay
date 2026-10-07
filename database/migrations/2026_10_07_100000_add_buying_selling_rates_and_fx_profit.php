<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('exchange_rates', 'buy_rate')) {
            return;
        }

        Schema::table('exchange_rates', function (Blueprint $table) {
            // rate stays the customer-facing figure so every existing conversion keeps working.
            $table->decimal('buy_rate', 20, 6)->nullable()->after('rate');
            $table->decimal('sell_rate', 20, 6)->nullable()->after('buy_rate');
        });

        DB::table('exchange_rates')->where('is_active', true)
            ->whereNull('sell_rate')
            ->update(['sell_rate' => DB::raw('rate')]);

        if (Schema::hasColumn('transactions', 'fx_profit_ugx')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('rate_buy_ugx', 20, 6)->nullable()->after('exchange_rate');
            $table->decimal('rate_sell_ugx', 20, 6)->nullable()->after('rate_buy_ugx');
            $table->decimal('fx_profit_ugx', 20, 2)->default(0)->after('charge_ugx');
            $table->decimal('fx_profit_usd', 20, 2)->default(0)->after('fx_profit_ugx');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropColumn(['buy_rate', 'sell_rate']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['rate_buy_ugx', 'rate_sell_ugx', 'fx_profit_ugx', 'fx_profit_usd']);
        });
    }
};
