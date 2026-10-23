<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A hand-in used to be one typed figure, which meant nobody could say which bookings that figure
     * stood for. Here the request names its transfers instead, so the same rows an agent ticked are
     * the rows the super agent reads and approves.
     *
     * The link is kept on the transfer itself rather than in a join table because every screen that
     * shows a booking has to say whether it is waiting on an approval, and a badge should not have to
     * join a queue to find out. The two figures are stored apart on the request because they take
     * different journeys: the money that travels goes to China, while the charge each booking earned
     * stays in the hand that collected it.
     */
    public function up(): void
    {
        Schema::table('cash_handovers', function (Blueprint $table) {
            $table->decimal('charge_ugx', 15, 2)->default(0)->after('amount');
            $table->text('receipts')->nullable()->after('charge_ugx');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_handover_id')->nullable()->after('country');
            $table->string('hand_in_status', 20)->nullable()->after('cash_handover_id');
            $table->timestamp('handed_in_at')->nullable()->after('hand_in_status');

            $table->index('hand_in_status');
            $table->foreign('cash_handover_id')->references('id')->on('cash_handovers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['cash_handover_id']);
            $table->dropColumn(['cash_handover_id', 'hand_in_status', 'handed_in_at']);
        });

        Schema::table('cash_handovers', function (Blueprint $table) {
            $table->dropColumn(['charge_ugx', 'receipts']);
        });
    }
};
