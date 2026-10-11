<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCustomers extends Command
{
    protected $signature = 'customers:backfill {--dry-run : Report what would be added without saving}';

    protected $description = 'File the senders and receivers of past transfers into the customer directory';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $before = Customer::withTrashed()->count();

        if ($dryRun) {
            DB::beginTransaction();
        }

        // Oldest first, so whoever was seen first keeps the type they were filed under.
        Transaction::orderBy('id')->chunkById(200, function ($transactions) {
            foreach ($transactions as $transaction) {
                Customer::syncFromTransaction($transaction);
            }
        });

        $added = Customer::withTrashed()->count() - $before;

        if ($dryRun) {
            DB::rollBack();
        }

        $this->info($dryRun
            ? "{$added} customer(s) would be added from existing transfers. Nothing was saved."
            : "{$added} customer(s) added from existing transfers.");

        return self::SUCCESS;
    }
}
