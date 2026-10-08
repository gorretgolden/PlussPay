<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('exchange_rates', 'updated_by')) {
            Schema::table('exchange_rates', function (Blueprint $table) {
                $table->foreignId('updated_by')->nullable()->after('created_by')
                    ->constrained('users')->nullOnDelete();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasColumn('exchange_rates', 'updated_by')) {
            Schema::table('exchange_rates', function (Blueprint $table) {
                $table->dropConstrainedForeignId('updated_by');
            });
        }
    }

    /**
     * Rates edited before the editor was stored are credited to whoever logged a change on that
     * pair within a minute of the row's own updated_at, which is the tightest evidence available.
     * A wider window would blame the next person who simply added a rate, so rows that cannot be
     * matched stay blank and read as "Not recorded".
     */
    private function backfill(): void
    {
        $logs = DB::table('activity_logs')
            ->whereIn('action', ['exchange_rate_set', 'exchange_rate_activated'])
            ->whereNotNull('user_id')
            ->get(['user_id', 'description', 'created_at']);

        if ($logs->isEmpty()) {
            return;
        }

        DB::table('exchange_rates')
            ->whereNull('updated_by')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($logs) {
                foreach ($rows as $row) {
                    $marker = 'for '.$row->from_currency.'-'.$row->to_currency;
                    $editedAt = strtotime((string) $row->updated_at);
                    $best = null;
                    $bestGap = null;

                    foreach ($logs as $log) {
                        if (! str_contains((string) $log->description, $marker)) {
                            continue;
                        }

                        $gap = abs(strtotime((string) $log->created_at) - $editedAt);

                        if ($gap <= 60 && ($bestGap === null || $gap < $bestGap)) {
                            $best = $log;
                            $bestGap = $gap;
                        }
                    }

                    if ($best) {
                        DB::table('exchange_rates')->where('id', $row->id)->update(['updated_by' => $best->user_id]);
                    }
                }
            });
    }
};
