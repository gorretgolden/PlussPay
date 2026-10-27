<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    private const IDLE_LIMIT_SECONDS = 1800;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if (!$user->is_active) {
            return $this->signOut($request, $user, 'logout',
                "User {$user->name} was logged out after their sign-in was blocked from {$request->ip()}",
                'Your account has been blocked from signing in. Contact admin.');
        }

        if ($user->isLocked()) {
            return $this->signOut($request, $user, 'logout',
                "User {$user->name} was logged out after their account was locked from {$request->ip()}",
                'Your account is locked. Contact admin.');
        }

        $windowReason = $user->loginWindowBlockReason();

        if ($windowReason) {
            return $this->signOut($request, $user, 'login_blocked',
                "User {$user->name} was signed out outside their allowed login hours from {$request->ip()}",
                $windowReason . ' You were signed out.');
        }

        // The browser heartbeat only asks whether the account still has access. It must not
        // hold the session open, so it neither refreshes nor measures the idle clock.
        if ($request->routeIs('session.check')) {
            return $next($request);
        }

        $lastActivity = $request->session()->get('last_activity_at');
        $idleSeconds = $lastActivity ? abs(now()->getTimestamp() - Carbon::parse($lastActivity)->getTimestamp()) : 0;

        if ($idleSeconds > self::IDLE_LIMIT_SECONDS) {
            return $this->signOut($request, $user, 'logout',
                "User {$user->name} was logged out after 30 minutes of inactivity from {$request->ip()}",
                'You have been logged out due to inactivity.', true);
        }

        $request->session()->put('last_activity_at', now()->toIso8601String());

        return $next($request);
    }

    /**
     * The same decision has to reach two places: the screen that is already open asks by
     * script and needs an answer it can show, while a normal page load just goes to the
     * sign-in form. Both paths end the session and leave one audit entry behind.
     */
    private function signOut(Request $request, User $user, string $action, string $log, string $message, bool $success = false): Response
    {
        ActivityLog::logAuth($user->id, $action, $log);

        $this->endSession($request);

        if ($request->expectsJson()) {
            return response()->json([
                'signed_out' => true,
                'message' => $message,
                'redirect' => route('login'),
            ], 401);
        }

        return $success
            ? redirect()->route('login')->with('success', $message)
            : redirect()->route('login')->withErrors(['email' => $message]);
    }

    private function endSession(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
