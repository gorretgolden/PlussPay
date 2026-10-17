<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Support\ActivityNews;

/**
 * Every book already writes its own audit row, so the notice is read off that row rather than
 * from a call each screen would have to remember. This runs inside the same transaction as the
 * entry it announces, so a booking that rolls back takes its news with it.
 */
class ActivityNewsObserver
{
    public function created(ActivityLog $log): void
    {
        try {
            ActivityNews::announce($log);
        } catch (\Throwable) {
            // The notifications table is not migrated yet on a fresh deploy, and a booked
            // transfer is never allowed to fail because nobody could be told about it.
        }
    }
}
