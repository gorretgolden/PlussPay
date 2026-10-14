<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only the figures that are genuinely missing from the row get new columns. The rest of
     * what a profit breakdown needs is already stored under older names, and duplicating a
     * number into a second column would give every transfer two answers that can disagree:
     *
     *   ugx buying rate    -> rate_buy_ugx          ugx selling rate -> rate_sell_ugx
     *   usd amount         -> total_usd_equivalent  service fee UGX  -> charge_ugx
     *   profit UGX to USD  -> fx_profit_ugx         rmb selling rate -> exchange_rate
     *
     * The yuan buying price is the one figure that was never recorded anywhere, which is why
     * the loss the China leg carries has been invisible until now.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('rmb_buying_rate', 20, 6)->nullable()->after('exchange_rate');
            $table->decimal('usd_rmb_profit_cny', 20, 6)->nullable()->after('fx_profit_usd');
            $table->decimal('usd_rmb_profit_ugx', 20, 6)->nullable()->after('usd_rmb_profit_cny');
            $table->decimal('total_profit_ugx', 20, 6)->nullable()->after('usd_rmb_profit_ugx');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['rmb_buying_rate', 'usd_rmb_profit_cny', 'usd_rmb_profit_ugx', 'total_profit_ugx']);
        });
    }
};
