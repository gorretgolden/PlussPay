<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every market reading the system takes, kept apart from the desk's own prices.
     * A snapshot says where the figure came from and when it was pulled, so the card
     * can name its source and the chart can show the market's daily move.
     */
    public function up(): void
    {
        Schema::create('rate_snapshots', function (Blueprint $table) {
            $table->id();
            $table->char('base_currency', 3);
            $table->char('quote_currency', 3);
            $table->decimal('rate', 20, 8);
            $table->date('rate_date');
            $table->string('source', 60);
            $table->timestamp('fetched_at');
            $table->timestamps();

            // MySQL caps an identifier at 64 characters, so the generated names are too long.
            $table->index(['base_currency', 'quote_currency', 'rate_date'], 'rate_snapshots_pair_day_index');
            $table->index(['base_currency', 'quote_currency', 'fetched_at'], 'rate_snapshots_pair_pulled_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_snapshots');
    }
};
