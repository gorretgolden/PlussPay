<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The categories page names who added a category, so it should also name who edited it last.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('expense_categories', 'updated_by')) {
            Schema::table('expense_categories', function (Blueprint $table) {
                $table->foreignId('updated_by')->nullable()->after('created_by')
                    ->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
