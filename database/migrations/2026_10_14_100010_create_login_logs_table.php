<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('login_logs')) {
            return;
        }

        Schema::create('login_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Kept even when there is no account, because a run of unknown addresses is how
            // a password-stuffing attempt reads.
            $table->string('email_attempted')->nullable();
            $table->string('status', 16);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device', 32)->nullable();
            $table->string('browser', 48)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('login_logs', function (Blueprint $table) {
            $table->index(['ip_address', 'created_at']);
            $table->index(['email_attempted', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_logs');
    }
};
