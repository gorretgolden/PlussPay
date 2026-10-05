<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Older installs built settings before create_settings_table carried this column.
        if (Schema::hasColumn('settings', 'description')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->text('description')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'description')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
