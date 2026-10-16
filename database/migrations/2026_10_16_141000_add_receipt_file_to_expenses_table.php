<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The slip a payment was proved with. The path is kept, not the file's public address, so the
     * download can be checked against the same ability that opens the expense book.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'receipt_file')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->string('receipt_file', 255)->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn('receipt_file');
        });
    }
};
