<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The book can now be cut by the price a transfer was booked at, and the dropdown that offers
 * those prices asks for the distinct figures. Both read one column at a time, so each of the
 * four booked prices gets its own index and the two-sided match runs as an index merge instead
 * of walking every row in the book.
 */
return new class extends Migration
{
    private const COLUMNS = ['rate_buy_ugx', 'rate_sell_ugx', 'rmb_buying_rate', 'exchange_rate'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('transactions', $column)
                && ! Schema::hasIndex('transactions', 'transactions_'.$column.'_index')) {
                Schema::table('transactions', function ($table) use ($column) {
                    $table->index($column);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasIndex('transactions', 'transactions_'.$column.'_index')) {
                Schema::table('transactions', function ($table) use ($column) {
                    $table->dropIndex('transactions_'.$column.'_index');
                });
            }
        }
    }
};
