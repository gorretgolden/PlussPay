<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One transfer has three moments, not one: the cash taken in Uganda, the dollars handed over to
 * the desk in China, and the yuan put into the receiver's hand. The booking itself stays Completed
 * through all three because the customer's side of the deal never changes, so the two later
 * moments ride as their own stamps rather than as new words in the status column, which every
 * screen, export and receipt already reads as three states only.
 *
 * Who holds the dollars is stored as the person, not the account, because the desk in China is the
 * thing she thinks in, and their yuan and dollar wallets are found from them. The stamp columns are
 * nullable with no default, so every transfer already in the book reads as still being in Uganda,
 * which is exactly what it is until somebody sends it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('transactions', 'settled_at')) {
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('status');
            $table->foreignId('settled_to')->nullable()->after('settled_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable()->after('settled_to');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('transactions', 'settled_at')) {
            return;
        }

        Schema::table('transactions', function ($table) {
            if (Schema::hasColumn('transactions', 'settled_to')) {
                $table->dropConstrainedForeignId('settled_to');
            }

            $table->dropColumn(['settled_at', 'delivered_at']);
        });
    }
};
