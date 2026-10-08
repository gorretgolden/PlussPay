<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('exchange_rates', 'created_by')) {
            Schema::table('exchange_rates', function (Blueprint $table) {
                $table->foreignId('created_by')->nullable()->after('is_active')
                    ->constrained('users')->nullOnDelete();
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasColumn('exchange_rates', 'created_by')) {
            Schema::table('exchange_rates', function (Blueprint $table) {
                $table->dropConstrainedForeignId('created_by');
            });
        }
    }

    /**
     * Older rows have no stored author, so reuse the exchange_rate_set log entry closest in time.
     */
    private function backfill(): void
    {
        $logs = DB::table('activity_logs')
            ->where('action', 'exchange_rate_set')
            ->whereNotNull('user_id')
            ->get(['user_id', 'description', 'new_value', 'created_at']);

        DB::table('exchange_rates')
            ->whereNull('created_by')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($logs) {
                foreach ($rows as $row) {
                    $marker = 'for '.$row->from_currency.'-'.$row->to_currency.' set to';
                    $best = null;
                    $bestGap = null;

                    foreach ($logs as $log) {
                        if (! str_contains((string) $log->description, $marker)
                            || (float) $log->new_value !== (float) $row->rate) {
                            continue;
                        }

                        $gap = abs(strtotime((string) $log->created_at) - strtotime((string) $row->created_at));
                        if ($bestGap === null || $gap < $bestGap) {
                            $best = $log;
                            $bestGap = $gap;
                        }
                    }

                    if ($best) {
                        DB::table('exchange_rates')->where('id', $row->id)->update(['created_by' => $best->user_id]);
                    }
                }
            });
    }
};
