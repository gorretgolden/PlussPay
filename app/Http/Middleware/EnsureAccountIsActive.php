<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
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
            ActivityLog::logAuth($user->id, 'logout', "User {$user->name} was logged out after their account was deactivated from {$request->ip()}");

            $this->endSession($request);

            return redirect()->route('login')->withErrors(['email' => 'Your account has been deactivated. Contact admin.']);
        }

        if ($user->isLocked()) {
            ActivityLog::logAuth($user->id, 'logout', "User {$user->name} was logged out after their account was locked from {$request->ip()}");

            $this->endSession($request);

            return redirect()->route('login')->withErrors(['email' => 'Your account is locked. Contact admin.']);
        }

        $lastActivity = $request->session()->get('last_activity_at');
        $idleSeconds = $lastActivity ? abs(now()->getTimestamp() - Carbon::parse($lastActivity)->getTimestamp()) : 0;

        if ($idleSeconds > self::IDLE_LIMIT_SECONDS) {
            ActivityLog::logAuth($user->id, 'logout', "User {$user->name} was logged out after 30 minutes of inactivity from {$request->ip()}");

            $this->endSession($request);

            return redirect()->route('login')->with('success', 'You have been logged out due to inactivity.');
        }

        $request->session()->put('last_activity_at', now()->toIso8601String());

        return $next($request);
    }

    private function endSession(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
