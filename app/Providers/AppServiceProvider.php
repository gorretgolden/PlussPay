<?php

namespace App\Providers;

use App\Models\Setting;
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
        $this->applyTimezone();

        Transaction::observe(TransactionObserver::class);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');

            // Never let the session cookie travel over plain HTTP.
            config(['session.secure' => true]);
        }
    }

    /**
     * System Settings decides the clock every screen, receipt and "today" report runs on.
     * PHP's default timezone has to move with the config value, because Eloquent stores
     * date columns as plain wall-clock strings in whatever PHP currently thinks it is.
     */
    public function applyTimezone(): void
    {
        try {
            $timezone = Setting::timezone();
        } catch (\Throwable) {
            // boot() also runs during migrate on a fresh deploy, where the settings
            // table may not exist yet. The default in config/app.php stands.
            return;
        }

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }
}
