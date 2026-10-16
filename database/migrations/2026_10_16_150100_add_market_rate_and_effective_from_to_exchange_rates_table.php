<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The street price and the day a card starts applying. The buying and selling prices are what
     * the desk quotes; the market rate is the mid price the deal was measured against, so a
     * customer's receipt can show how far off the street the desk was. A rate row already carries
     * the moment it was saved, which is what every historical lookup uses, so the start date is
     * filled from it rather than left blank.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('exchange_rates', 'market_rate')) {
            Schema::table('exchange_rates', function (Blueprint $table) {
                $table->decimal('market_rate', 20, 6)->nullable()->after('sell_rate');
                $table->date('effective_from')->nullable()->after('is_active');
            });
        }

        DB::statement('UPDATE exchange_rates SET effective_from = DATE(created_at) WHERE effective_from IS NULL');
    }

    public function down(): void
    {
        Schema::table('exchange_rates', function (Blueprint $table) {
            $table->dropColumn(['market_rate', 'effective_from']);
        });
    }
};
