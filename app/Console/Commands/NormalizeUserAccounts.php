<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class NormalizeUserAccounts extends Command
{
    protected $signature = 'users:normalize {--dry-run : Report what would change without saving}';

    protected $description = 'Rewrite existing user names as Title Case and phone numbers into one comparable format';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $changed = 0;
        $clashes = [];

        User::chunkById(200, function ($users) use (&$changed, &$clashes, $dryRun) {
            foreach ($users as $user) {
                // Assigning the current value re-runs the name and phone mutators.
                $user->name = $user->name;
                $user->phone = $user->phone;

                if ($user->phone) {
                    $twin = User::where('phone', $user->phone)->where('id', '!=', $user->id)->first();

                    if ($twin) {
                        $pair = [$user->email, $twin->email];
                        sort($pair);

                        $clashes[implode(' and ', $pair) . " both use {$user->phone}"] = true;
                    }
                }

                if ($user->isDirty()) {
                    $this->line(($dryRun ? 'Would fix: ' : 'Fixed: ') . $user->email);

                    if (!$dryRun) {
                        $user->save();
                    }

                    $changed++;
                }
            }
        });

        $this->info($dryRun
            ? "{$changed} account(s) would be rewritten."
            : "{$changed} account(s) rewritten.");

        if ($clashes) {
            $this->warn('These accounts share a phone number, so decide which one keeps it:');

            foreach (array_keys($clashes) as $clash) {
                $this->line('  ' . $clash);
            }
        } else {
            $this->info('No duplicated phone numbers found.');
        }

        return self::SUCCESS;
    }
}
