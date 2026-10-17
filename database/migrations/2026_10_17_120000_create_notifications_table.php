<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This is Laravel's own notifications table, used through the database channel only.
     * There is no queue worker on the hosting, so a message is written the moment the
     * audit row it describes is, and it sits here until the reader marks it seen.
     *
     * Every word a reader needs is inside data: the audit trail can be pruned or a record
     * deleted without taking the notice's wording down with it.
     */
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::table('notifications', function (Blueprint $table) {
            // Both screens that read this table ask the same question: what has this person
            // not seen yet, newest first.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notifications_unread_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
