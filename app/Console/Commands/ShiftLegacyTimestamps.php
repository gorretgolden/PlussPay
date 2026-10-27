<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShiftLegacyTimestamps extends Command
{
    /**
     * Plain date columns are deliberately absent. account_entries.entry_date can be typed
     * in on the journal form, so it is a business date rather than a clock reading.
     */
    private const COLUMNS = [
        'transactions' => ['created_at', 'updated_at', 'deleted_at'],
        'activity_logs' => ['created_at', 'updated_at'],
        'exchange_rates' => ['created_at', 'updated_at'],
        'customers' => ['created_at', 'updated_at'],
        'users' => ['created_at', 'updated_at', 'last_login_at'],
    ];

    protected $signature = 'timestamps:shift
        {--before= : The moment the server was updated, read on the clock System Settings shows now. Only rows stamped earlier than that are moved}
        {--hours=3 : How many hours the old rows sit behind the zone you have chosen}
        {--apply : Write the change. Without this option nothing is touched}
        {--restore= : Put the values back from a backup file written by an earlier run}';

    protected $description = 'Move timestamps written while the system ran on UTC onto the time zone now set in System Settings';

    public function handle(): int
    {
        if ($path = $this->option('restore')) {
            return $this->restore($path);
        }

        $minutes = $this->minutes();
        $cut = $this->cutoff();

        if ($cut === null || $minutes === null) {
            return self::FAILURE;
        }

        if ($this->alreadyMoved($cut)) {
            return self::FAILURE;
        }

        if ($cut >= now()->format('Y-m-d H:i:s')) {
            $this->warn('That cut-off is now or later, so every row in the database would move. Check the time first.');
        }

        $this->info('Rows stamped before ' . $cut . ' were written on the old clock and will move ' . $this->option('hours') . ' hour(s) forward.');
        $this->line('');

        [$moved, $touched, $backup] = $this->collect($cut, $minutes);

        if ($moved === 0) {
            $this->info('Nothing to move.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->info("{$moved} value(s) across {$touched} column(s) to move.");

        if (!$this->option('apply')) {
            $this->warn('Dry run. Nothing was written. Repeat with --apply to make it real.');

            return self::SUCCESS;
        }

        return $this->write($backup, $cut, $minutes, $moved);
    }

    /**
     * @return array{0: int, 1: int, 2: array<string, array<int, array<string, string>>>}
     */
    private function collect(string $cut, int $minutes): array
    {
        $schema = DB::getSchemaBuilder();
        $moved = 0;
        $touched = 0;
        $backup = [];

        foreach (self::COLUMNS as $table => $tableColumns) {
            if (!$schema->hasTable($table)) {
                $this->warn("Table {$table} does not exist, skipped.");
                continue;
            }

            $present = $schema->getColumnListing($table);

            foreach ($tableColumns as $column) {
                if (!in_array($column, $present, true)) {
                    continue;
                }

                $rows = DB::table($table)
                    ->whereNotNull($column)
                    ->where($column, '<', $cut)
                    ->orderBy($column)
                    ->get(['id', $column]);

                if ($rows->isEmpty()) {
                    continue;
                }

                $kept = DB::table($table)->where($column, '>=', $cut)->count();

                $this->line(sprintf(
                    '  %s.%s  %s row(s)  %s .. %s%s',
                    $table,
                    $column,
                    number_format($rows->count()),
                    (string) $rows->first()->{$column},
                    (string) $rows->last()->{$column},
                    $kept ? ",  {$kept} newer row(s) left alone" : ''
                ));

                foreach ($rows as $row) {
                    $backup[$table][(int) $row->id][$column] = (string) $row->{$column};
                    $moved++;
                }

                $touched++;
            }
        }

        return [$moved, $touched, $backup];
    }

    private function write(array $backup, string $cut, int $minutes, int $moved): int
    {
        $path = storage_path('app/timestamp-shift-' . now()->format('Ymd-His') . '.json');

        file_put_contents($path, json_encode([
            'before' => $cut,
            'hours' => (float) $this->option('hours'),
            'written_at' => now()->toDateTimeString(),
            'values' => $backup,
        ], JSON_PRETTY_PRINT));

        DB::transaction(function () use ($backup, $minutes) {
            foreach ($backup as $table => $rows) {
                foreach ($rows as $id => $columns) {
                    $changes = [];

                    foreach ($columns as $column => $original) {
                        $changes[$column] = Carbon::parse($original)->addMinutes($minutes)->format('Y-m-d H:i:s');
                    }

                    DB::table($table)->where('id', $id)->update($changes);
                }
            }
        });

        Setting::set('timestamps_shifted', [
            'before' => $cut,
            'hours' => (string) $this->option('hours'),
            'rows' => $moved,
            'backup' => basename($path),
        ], 'array', 'system');

        $this->info("{$moved} value(s) moved.");
        $this->line('Original values are saved in ' . $path);
        $this->line('To undo: php artisan timestamps:shift --restore=' . basename($path));
        $this->warn('A transfer stamped late in the old day can now fall on the next one, so totals for individual past days may shift.');

        return self::SUCCESS;
    }

    private function restore(string $path): int
    {
        $file = str_starts_with($path, '/') ? $path : storage_path('app/' . $path);

        if (!is_file($file)) {
            $this->error('No backup file at ' . $file);

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data) || empty($data['values'])) {
            $this->error('That file does not look like a backup written by this command.');

            return self::FAILURE;
        }

        $count = 0;

        foreach ($data['values'] as $table => $rows) {
            foreach ($rows as $id => $columns) {
                $count += count($columns);
                $this->line("  {$table} #{$id} back to " . implode(', ', array_keys($columns)));
            }
        }

        $this->info("{$count} value(s) to put back as they were before the shift.");

        if (!$this->option('apply')) {
            $this->warn('Dry run. Nothing was written. Repeat with --apply to make it real.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($data) {
            foreach ($data['values'] as $table => $rows) {
                foreach ($rows as $id => $columns) {
                    DB::table($table)->where('id', $id)->update($columns);
                }
            }
        });

        Setting::forget('timestamps_shifted');

        $this->info("{$count} value(s) restored from {$file}");

        return self::SUCCESS;
    }

    private function minutes(): ?int
    {
        $hours = (float) $this->option('hours');

        if ($hours === 0.0 || abs($hours) > 14) {
            $this->error('--hours must be a number between -14 and 14, and not zero.');

            return null;
        }

        return (int) round($hours * 60);
    }

    /**
     * A date the caller did not mean can quietly move the whole database, so relative
     * phrases like "last tuesday" and bare dates are refused rather than guessed at.
     */
    private function cutoff(): ?string
    {
        $raw = trim((string) $this->option('before'));

        if ($raw === '') {
            $this->error('Nothing is moved until you say where the old clock stops, e.g. --before="2026-10-12 09:20".');
            $this->line('That is the moment you ran git pull, read on the clock shown in System Settings.');

            return null;
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                $date = Carbon::createFromFormat('!' . $format, $raw, (string) config('app.timezone'));
            } catch (\Throwable) {
                continue;
            }

            if ($date !== false) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        $this->error('I could not read that time. Write the full date and hour, e.g. --before="2026-10-12 09:20".');

        return null;
    }

    private function alreadyMoved(string $cut): bool
    {
        $marker = Setting::get('timestamps_shifted');

        if (!is_array($marker) || ($marker['before'] ?? null) !== $cut) {
            return false;
        }

        $this->error('Rows before ' . $cut . " were already moved by {$marker['hours']} hour(s), {$marker['rows']} values, backup {$marker['backup']}.");
        $this->line('Running this again would shift them a second time. Undo first: php artisan timestamps:shift --restore=' . $marker['backup']);

        return true;
    }
}
