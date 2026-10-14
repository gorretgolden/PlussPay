<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two desks that were being kept on paper. A currency exchange is a deal with a person
     * rather than a transfer on behalf of one: they hand over shillings, dollars or yuan and we
     * hand back one of the others, priced at the card. An expense is money the business spent on
     * itself, which comes straight out of the shilling account.
     *
     * Both carry their own reference so the two ledger legs they write can be read back as one
     * deal, which is what shows the account that rose and the account that fell.
     */
    public function up(): void
    {
        if (! Schema::hasTable('currency_exchanges')) {
            Schema::create('currency_exchanges', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 20)->unique();
                $table->date('exchange_date');
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('person_name', 120);
                $table->string('in_currency', 3);
                $table->decimal('in_amount', 20, 2);
                $table->foreignId('in_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->string('out_currency', 3);
                $table->decimal('out_amount', 20, 2);
                $table->foreignId('out_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->decimal('rate', 20, 6)->nullable();
                $table->decimal('margin_ugx', 20, 2)->nullable();
                $table->string('status', 12)->default('active');
                $table->string('notes', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['exchange_date', 'status']);
                $table->index(['in_currency', 'out_currency']);
            });
        }

        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->string('reference', 20)->unique();
                $table->date('expense_date');
                $table->string('category', 60);
                $table->string('description');
                $table->string('currency', 3)->default('UGX');
                $table->decimal('amount', 20, 2);
                $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->string('paid_via', 30)->nullable();
                $table->string('status', 12)->default('active');
                $table->string('notes', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['expense_date', 'status']);
                $table->index('category');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('currency_exchanges');
    }
};
