<?php

namespace App\Providers;

use App\Models\LoginLog;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Models\Transaction;
use App\Observers\TransactionObserver;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
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

        $this->registerLogListeners();
        $this->registerSidebarLogBadges();

        if ($this->app->environment('production')) {
            URL::forceScheme('https');

            // Never let the session cookie travel over plain HTTP.
            config(['session.secure' => true]);
        }
    }

    /**
     * Sign-ins and sign-outs that go through the real auth guard emit these events, so
     * the login log stays accurate without every caller remembering to write a row.
     * Failures and lockouts are written from AuthController directly, because this app
     * checks the hash by hand and Laravel's Failed/Lockout events never fire.
     */
    private function registerLogListeners(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof \App\Models\User) {
                LoginLog::record(request(), 'success', $event->user);
            }
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user instanceof \App\Models\User) {
                LoginLog::record(request(), 'logout', $event->user);
            }
        });

        // A job that exhausts its retries is an error nobody sees on a page, so it has
        // to reach the same table the exception handler writes into.
        Event::listen(JobFailed::class, function (JobFailed $event) {
            SystemLog::recordThrowable($event->exception, null, 'critical');
        });
    }

    /**
     * The sidebar carries a live count so she can see trouble without opening each tab:
     * failed sign-ins in the last quarter of an hour for anyone who manages users, and
     * unresolved system errors for the Super Admin. Both are read lazily when the layout
     * renders, and each is guarded because boot also runs before these tables exist.
     */
    private function registerSidebarLogBadges(): void
    {
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            if (!$user || !$user->hasAnyRole(['admin', 'superadmin'])) {
                return;
            }

            try {
                $view->with('sidebarFailedLogins', LoginLog::where('status', 'failed')
                    ->where('created_at', '>=', now()->subMinutes(15))
                    ->count());

                if ($user->hasRole('superadmin')) {
                    $view->with('sidebarUnresolvedErrors', SystemLog::where('resolved', false)->count());
                }
            } catch (\Throwable) {
                // login_logs / system_logs not migrated yet on a fresh deploy; skip badges.
            }
        });
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
