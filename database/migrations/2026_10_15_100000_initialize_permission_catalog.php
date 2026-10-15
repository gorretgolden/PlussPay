<?php

use Database\Seeders\PermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->timestamp('permissions_initialized_at')->nullable();
        });

        // Also initializes existing production installs without creating demo users.
        (new PermissionsSeeder)->run();
    }

    public function down(): void
    {
        // Permission data is deliberately retained: rollback must not revoke live access.
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['description', 'permissions_initialized_at']);
        });
    }
};
