<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Command;

class BackfillUserCreators extends Command
{
    protected $signature = 'users:backfill-creators {--dry-run : Report what would change without saving}';

    protected $description = 'Fill who added and last edited an account from the audit trail, for accounts made before those columns existed';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $filled = 0;

        User::query()
            ->where(fn ($query) => $query->whereNull('created_by')->orWhereNull('updated_by'))
            ->chunkById(200, function ($users) use (&$filled, $dryRun) {
                foreach ($users as $user) {
                    $createdBy = $user->created_by;
                    $updatedBy = $user->updated_by;

                    if ($createdBy === null) {
                        $createdBy = $this->createdActor($user);
                    }

                    if ($updatedBy === null) {
                        $updatedBy = $this->updatedActor($user);
                    }

                    if ($createdBy === $user->created_by && $updatedBy === $user->updated_by) {
                        continue;
                    }

                    $this->line(($dryRun ? 'Would fill: ' : 'Filled: ').$user->email
                        .' added by '.$this->name($createdBy)
                        .', edited by '.$this->name($updatedBy));

                    if (! $dryRun) {
                        $user->forceFill(['created_by' => $createdBy, 'updated_by' => $updatedBy])->saveQuietly();
                    }

                    $filled++;
                }
            });

        $this->info($dryRun ? "{$filled} account(s) would be updated." : "{$filled} account(s) updated.");

        return self::SUCCESS;
    }

    private function createdActor(User $user): ?int
    {
        if (! $user->email) {
            return null;
        }

        $log = ActivityLog::query()
            ->where('action', 'user_created')
            ->where('description', 'like', '%('.$this->escapeLike($user->email).') created%')
            ->where('user_id', '!=', $user->id)
            ->whereBetween('created_at', [$user->created_at->copy()->subMinutes(10), $user->created_at->copy()->addMinutes(10)])
            ->orderBy('id')
            ->first();

        return $log?->user_id;
    }

    private function updatedActor(User $user): ?int
    {
        $log = ActivityLog::query()
            ->where('action', 'user_updated')
            ->where('description', 'like', '%User '.$this->escapeLike($user->name).' updated%')
            ->whereBetween('created_at', [$user->updated_at->copy()->subMinutes(10), $user->updated_at->copy()->addMinutes(10)])
            ->orderByDesc('id')
            ->first();

        return $log?->user_id;
    }

    private function name(?int $id): string
    {
        return $id ? (User::find($id)?->name ?? '#'.$id) : 'not known';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
