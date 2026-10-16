<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the desk charged and how the money moved. A counter deal is not just two amounts:
     * the commission taken out of the side handed over, the price the card was showing at that
     * moment, and the reason an operator gave a different price are all part of the record, and
     * a receipt printed years later has to repeat them exactly as they stood on the day.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('currency_exchanges', 'fee')) {
            Schema::table('currency_exchanges', function (Blueprint $table) {
                // The commission is always in the currency the customer handed over.
                $table->decimal('fee', 20, 2)->nullable()->after('out_account_id');
                $table->string('payment_method', 30)->nullable()->after('fee');
                $table->decimal('market_rate', 20, 6)->nullable()->after('rate');
                $table->string('rate_reason', 255)->nullable()->after('market_rate');
            });
        }
    }

    public function down(): void
    {
        Schema::table('currency_exchanges', function (Blueprint $table) {
            $table->dropColumn(['fee', 'payment_method', 'market_rate', 'rate_reason']);
        });
    }
};
