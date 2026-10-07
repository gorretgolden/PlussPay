<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The four cash books the finance officer keeps by hand: UGX and dollars held in
     * Uganda, dollars and RMB held in China. Every movement writes a ledger line so the
     * closing balance of any day can be read back without a spreadsheet.
     */
    public function up(): void
    {
        if (! Schema::hasTable('accounts')) {
            Schema::create('accounts', function (Blueprint $table) {
                $table->id();
                $table->string('code', 20)->unique();
                $table->string('name', 80);
                $table->string('country', 2);
                $table->string('currency', 3);
                $table->decimal('opening_balance', 20, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('description')->nullable();
                $table->timestamps();

                $table->index(['country', 'currency']);
            });
        }

        if (! Schema::hasTable('account_entries')) {
            Schema::create('account_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
                $table->foreignId('counterparty_account_id')->nullable()->constrained('accounts')->nullOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->date('entry_date');
                $table->string('type', 30);
                $table->enum('direction', ['in', 'out']);
                $table->decimal('amount', 20, 2);
                $table->decimal('rate', 20, 6)->nullable();
                $table->string('reference', 60)->nullable();
                $table->string('description');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['account_id', 'entry_date']);
                $table->index('entry_date');
                $table->index('type');
                $table->index('reference');
            });
        }

        $seed = [
            ['UGX-UG', 'UGX Cash (Uganda)', 'UG', 'UGX', 'Agent and mobile money collections held in Uganda.'],
            ['USD-UG', 'Dollar Account (Uganda)', 'UG', 'USD', 'Dollars held in Uganda before they are sent to China.'],
            ['USD-CN', 'Dollar Account (China)', 'CN', 'USD', 'Dollars received in China, waiting to be exchanged into RMB.'],
            ['RMB-CN', 'RMB Account (China)', 'CN', 'RMB', 'RMB used to pay recipients in China.'],
        ];

        foreach ($seed as $i => [$code, $name, $country, $currency, $description]) {
            DB::table('accounts')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'country' => $country,
                    'currency' => $currency,
                    'opening_balance' => 0,
                    'is_active' => true,
                    'description' => $description,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_entries');
        Schema::dropIfExists('accounts');
    }
};
