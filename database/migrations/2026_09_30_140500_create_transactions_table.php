<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number', 50)->unique();
            $table->string('sender_name');
            $table->string('sender_contact');
            $table->string('receiver_name');
            $table->string('receiver_contact');
            $table->enum('currency', ['UGX', 'USD']);
            $table->decimal('amount', 20, 2);
            $table->decimal('exchange_rate', 20, 6);
            $table->decimal('rmb_amount', 20, 2);
            $table->decimal('charge_rate', 10, 4)->default(0);
            $table->decimal('charge_amount', 20, 2)->default(0);
            $table->decimal('total_usd_equivalent', 20, 2)->default(0);
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('pending');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('receipt_number');
            $table->index('status');
            $table->index('created_by');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
