<?php

use App\Models\SystemLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\EnsurePasswordIsChanged::class,
        ]);

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'account.active' => \App\Http\Middleware\EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Keep a queryable copy of every unhandled exception alongside laravel.log.
        // Only the report side is extended; rendering is left untouched, so the normal
        // error pages still show exactly as before. recordThrowable swallows its own
        // failures, so a broken logger can never turn a 500 into a blank screen.
        $exceptions->reportable(function (Throwable $e): void {
            SystemLog::recordThrowable($e, request(), SystemLog::levelFor($e));
        });
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule): void {
        // Keep the log tables bounded without her having to remember. Runs quietly at
        // 03:17 local; the retention window itself lives in System Settings.
        $schedule->command('logs:prune')->dailyAt('03:17');
    })->create();
