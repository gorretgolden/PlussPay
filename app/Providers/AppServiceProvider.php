<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\LoginLog;
use App\Models\Setting;
use App\Models\SystemLog;
use App\Models\Transaction;
use App\Observers\ActivityNewsObserver;
use App\Observers\TransactionObserver;
use App\Support\ActivityNews;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register a single ordered permission callback so reserved abilities cannot
        // be short-circuited by Spatie's generic permission callback.
        config(['permission.register_permission_check_method' => false]);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::before(function ($user, string $ability) {
            if ($user->hasRole('superadmin')) {
                return true;
            }
            if ($user->hasAnyRole(['public', 'customer']) || in_array($ability, \App\Support\PermissionCatalog::RESERVED, true)) {
                return false;
            }
            return $user->checkPermissionTo($ability, 'web') ? true : null;
        });
        \Illuminate\Support\Facades\Gate::define('assign-role', fn ($user, $role) => \App\Support\PermissionCatalog::canAssignRole($user, $role));

        $this->applyTimezone();

        Transaction::observe(TransactionObserver::class);
        ActivityLog::observe(ActivityNewsObserver::class);

        $this->registerLogListeners();
        $this->registerSidebarLogBadges();
        $this->registerSidebarNewsBadges();

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

            if (!$user) {
                return;
            }

            try {
                if ($user->can('view-login-logs')) {
                    $view->with('sidebarFailedLogins', LoginLog::where('status', 'failed')
                        ->where('created_at', '>=', now()->subMinutes(15))
                        ->count());
                }

                if ($user->can('view-system-logs')) {
                    $view->with('sidebarUnresolvedErrors', SystemLog::where('resolved', false)->count());
                }
            } catch (\Throwable) {
                // login_logs / system_logs not migrated yet on a fresh deploy; skip badges.
            }
        });
    }

    /**
     * The count of what each reader has not seen yet, so the sidebar says where to look without
     * anybody having to open the inbox first. It is read out of the notices that person was
     * actually sent, which means a badge can never show news they could not open, and a desk
     * that is not on the receiving end of any kind of news gets no badge and no inbox link.
     */
    private function registerSidebarNewsBadges(): void
    {
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            if (!$user || !Gate::any(ActivityNews::AUDIENCE)) {
                return;
            }

            try {
                $view->with('sidebarNews', ActivityNews::badgeCounts($user));
            } catch (\Throwable) {
                // notifications not migrated yet on a fresh deploy; skip badges.
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
