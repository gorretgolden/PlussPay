<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('activity_logs', 'user_name')) {
            Schema::table('activity_logs', function (Blueprint $table) {
                // The doer is snapshotted at write time. A user renamed or removed later
                // must not rewrite history, so the name, role and country travel on the row.
                $table->string('user_name')->nullable()->after('user_id');
                $table->string('user_role')->nullable()->after('user_name');
                $table->string('user_country', 2)->nullable()->after('user_role');
                $table->string('subject_type')->nullable()->after('transaction_id');
                $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            });
        }
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            foreach (['user_name', 'user_role', 'user_country', 'subject_type', 'subject_id'] as $column) {
                if (Schema::hasColumn('activity_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
