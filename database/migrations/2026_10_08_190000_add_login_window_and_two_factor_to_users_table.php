<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('allowed_days', 20)->nullable()->after('last_login_ip');
            $table->string('allowed_from', 5)->nullable()->after('allowed_days');
            $table->string('allowed_to', 5)->nullable()->after('allowed_from');
            $table->text('two_factor_secret')->nullable()->after('allowed_to');
            $table->boolean('two_factor_enabled')->default(false)->after('two_factor_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'allowed_days',
                'allowed_from',
                'allowed_to',
                'two_factor_secret',
                'two_factor_enabled',
            ]);
        });
    }
};
