<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounts', 'owner_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->foreignId('owner_id')->nullable()->after('code')
                    ->constrained('users')->nullOnDelete();
                $table->unique(['owner_id', 'currency']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accounts', 'owner_id')) {
            Schema::table('accounts', function (Blueprint $table) {
                $table->dropUnique(['owner_id', 'currency']);
                $table->dropConstrainedForeignId('owner_id');
            });
        }
    }
};
