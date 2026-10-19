<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which desk a transfer belongs to: Uganda or China, read off the hand it was booked into.
        // Every row carries one, so the column opens with the desk that books nearly everything
        // (Uganda) and the backfill below corrects the rows whose holder sits on the other one.
        //
        // The value is stamped by the model from the holder's own desk and is deliberately not
        // fillable, so no write path can set it by hand and no later change of a person's desk
        // rewrites the history of a transfer they already booked.
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('country', 2)->default('UG')->after('created_by');
            $table->index('country');
        });

        // Old bookings have no desk of their own on record, so they are read back off the person
        // the money was booked into. That is the only evidence the book holds about where their
        // cash changed hands, and it is why the stamp is kept on the transfer from now on instead
        // of being derived from the holder each time the row is shown.
        Transaction::stampMissingDesks();
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['country']);
            $table->dropColumn('country');
        });
    }
};
