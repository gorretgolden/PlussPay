<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the lists people open every day: the rate history ordered newest
     * first, the audit trail by subject and date, and the booking desk by country or
     * status. Each is guarded so a database that already has one is left alone.
     */
    public function up(): void
    {
        $this->add('exchange_rates', ['created_at'], 'exchange_rates_created_at_index');
        $this->add('exchange_rates', ['is_active', 'created_at'], 'exchange_rates_active_created_index');
        $this->add('activity_logs', ['created_at'], 'activity_logs_created_at_index');
        $this->add('activity_logs', ['action'], 'activity_logs_action_index');
        $this->add('activity_logs', ['subject_type', 'subject_id'], 'activity_logs_subject_index');
        $this->add('transactions', ['country', 'created_at'], 'transactions_country_created_index');
        $this->add('transactions', ['status', 'created_at'], 'transactions_status_created_index');
        $this->add('users', ['last_login_at'], 'users_last_login_at_index');
    }

    public function down(): void
    {
        foreach ([
            ['exchange_rates', 'exchange_rates_created_at_index'],
            ['exchange_rates', 'exchange_rates_active_created_index'],
            ['activity_logs', 'activity_logs_created_at_index'],
            ['activity_logs', 'activity_logs_action_index'],
            ['activity_logs', 'activity_logs_subject_index'],
            ['transactions', 'transactions_country_created_index'],
            ['transactions', 'transactions_status_created_index'],
            ['users', 'users_last_login_at_index'],
        ] as [$table, $index]) {
            if (Schema::hasIndex($table, $index)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($index));
            }
        }
    }

    private function add(string $table, array $columns, string $index): void
    {
        if (Schema::hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $index));
    }
};
