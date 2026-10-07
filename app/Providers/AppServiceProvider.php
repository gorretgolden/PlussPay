<?php

namespace App\Providers;

use App\Models\Transaction;
use App\Observers\TransactionObserver;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Transaction::observe(TransactionObserver::class);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');

            // Never let the session cookie travel over plain HTTP.
            config(['session.secure' => true]);
        }
    }
}
