<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Old bookings stay blank rather than being backfilled to cash: nobody asked the desk how
        // that money arrived, so the list shows a dash and the booking book says nobody said.
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payment_method', 40)->nullable()->after('purpose');
            $table->string('payment_reference', 60)->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_reference']);
        });
    }
};
