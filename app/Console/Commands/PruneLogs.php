<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\LoginLog;
use App\Models\Setting;
use App\Models\SystemLog;
use Illuminate\Console\Command;

class PruneLogs extends Command
{
    protected $signature = 'logs:prune
                            {--days= : Override the retention window in days}
                            {--dry-run : Report what would be removed without deleting}';

    protected $description = 'Delete audit, login and system log rows older than the retention window';

    /**
     * A money business keeps its audit trail longer than a normal app deletes noise, so
     * the default is 180 days and the window is a System Setting she can widen. Nothing
     * resolved is ever touched, and the delete is per-table so a dry run shows each count.
     */
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: Setting::get('log_retention_days', 180));

        if ($days < 1) {
            $this->error('Retention must be at least 1 day. Nothing was deleted.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        $counts = [
            'activity_logs' => ActivityLog::where('created_at', '<', $cutoff)->count(),
            'login_logs' => LoginLog::where('created_at', '<', $cutoff)->count(),
            // A resolved error is her record that an incident happened and was closed,
            // so only unresolved rows past the window are eligible for removal.
            'system_logs' => SystemLog::where('resolved', false)->where('created_at', '<', $cutoff)->count(),
        ];

        if ($this->option('dry-run')) {
            $this->info("Older than {$days} days (before {$cutoff->toDateTimeString()}) - nothing deleted:");
            foreach ($counts as $table => $count) {
                $this->line("  {$table}: {$count}");
            }

            return self::SUCCESS;
        }

        $removed = 0;
        foreach ($counts as $table => $count) {
            $model = match ($table) {
                'activity_logs' => ActivityLog::class,
                'login_logs' => LoginLog::class,
                'system_logs' => SystemLog::class,
            };
            $model::where('created_at', '<', $cutoff)
                ->when($table === 'system_logs', fn ($q) => $q->where('resolved', false))
                ->delete();
            $removed += $count;
            $this->line("  {$table}: {$count} deleted");
        }

        $this->info("Removed {$removed} log row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
