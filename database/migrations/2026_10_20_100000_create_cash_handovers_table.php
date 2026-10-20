<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash leaves an agent's hand only when the super agent says it arrived. The request is a row
     * of its own rather than a ledger line, because until it is approved the money is still in the
     * agent's wallet and nothing in the books has moved.
     */
    public function up(): void
    {
        Schema::create('cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('agent_id')->constrained('users');
            $table->foreignId('super_agent_id')->constrained('users');
            $table->string('currency', 3);
            $table->decimal('amount', 15, 2);
            $table->string('note', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('decision_note', 255)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['super_agent_id', 'status']);
            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_handovers');
    }
};
