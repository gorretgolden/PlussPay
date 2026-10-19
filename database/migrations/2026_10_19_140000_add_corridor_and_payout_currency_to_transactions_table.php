<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Two columns say where a transfer is going and in what money it arrives at the other end.
        // The desk it was taken in at already stands on the row, so the three together are the whole
        // route: shillings in at Uganda and yuan out in China, yuan in at China and shillings out at
        // Uganda, or dollars that stop at a desk in China and are handed over as dollars.
        //
        // Every booking in the book so far went to a receiver in China and was paid in yuan, so both
        // columns open on those values and this step rewrites no history. A booking that pays no
        // yuan leaves rmb_amount at zero, which is what already keeps it out of the yuan piles the
        // board counts.
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('destination', 2)->default('CN')->after('country');
            $table->string('payout_currency', 3)->default('RMB')->after('destination');
        });

        // A receiver in Uganda is handed shillings, and those shillings are not the money that was
        // taken in, so they need a column of their own. Every booking to China leaves it at zero.
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('ugx_amount', 15, 2)->default(0)->after('rmb_amount');
        });

        // The desk in China takes its own money in, so a transfer booked there can arrive in yuan.
        // Widening the list of values a column may hold changes no row and drops nothing.
        Schema::table('transactions', function (Blueprint $table) {
            $table->enum('currency', ['UGX', 'USD', 'RMB'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['destination', 'payout_currency', 'ugx_amount']);
        });

        // The currency column is deliberately left able to hold yuan. Narrowing it would fail on any
        // booking taken in at China, and the only way round that is to delete money from the book.
    }
};
