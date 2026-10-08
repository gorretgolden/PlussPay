<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    private const CHALLENGE_SECONDS = 600;

    private const MAX_CODE_ATTEMPTS = 5;

    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ], [
            'email.required' => 'Email is required.',
            'email.email' => 'Enter a valid email address.',
            'password.required' => 'Password is required.',
        ]);

        $email = strtolower(trim($request->email));
        $user = User::where('email', $email)->first();

        if (!$user) {
            RateLimiter::hit('login:' . $request->ip(), 60);

            return back()->withErrors(['email' => 'Invalid email.'])->onlyInput('email');
        }

        if ($user->isLocked()) {
            $message = 'Your account is locked.';
            if ($user->locked_until && $user->locked_until->isFuture()) {
                $minutes = (int) ceil(now()->diffInSeconds($user->locked_until) / 60);
                $message .= " Try again in {$minutes} minute(s).";
            } else {
                $message .= ' Contact admin.';
            }

            return back()->withErrors(['email' => $message])->onlyInput('email');
        }

        if (!$user->is_active) {
            $this->logDenied($user, $request, 'sign-in refused because the account is inactive');

            return back()->withErrors([
                'email' => 'This account is inactive, so it cannot sign in. Contact admin.',
            ])->onlyInput('email');
        }

        $key = 'login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors([
                'email' => 'Too many login attempts. Try again in ' . $seconds . ' seconds.',
            ])->onlyInput('email');
        }

        if (!Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, 60);
            $user->recordFailedLogin();

            if ($user->isLocked()) {
                $this->logDenied($user, $request, 'account locked after too many failed passwords');
            }

            return back()->withErrors(['email' => 'Invalid password.'])->onlyInput('email');
        }

        $windowReason = $user->loginWindowBlockReason();

        if ($windowReason) {
            $this->logDenied($user, $request, 'sign-in refused outside the allowed login hours');

            return back()->withErrors([
                'email' => $windowReason . ' Sign in again during your allowed hours.',
            ])->onlyInput('email');
        }

        RateLimiter::clear($key);

        if ($user->two_factor_enabled && $user->two_factor_secret) {
            $request->session()->put([
                'two_factor_user_id' => $user->id,
                'two_factor_started_at' => now()->timestamp,
                'two_factor_attempts' => 0,
                'two_factor_remember' => $request->boolean('remember'),
            ]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->completeLogin($request, $user, $request->boolean('remember'));
    }

    public function showTwoFactor(Request $request)
    {
        $user = $this->pendingUser($request);

        if (!$user) {
            return redirect()->route('login')->withErrors(['email' => 'Sign in again to start the two-factor step.']);
        }

        return view('auth.two-factor-challenge', ['user' => $user]);
    }

    public function verifyTwoFactor(Request $request)
    {
        $user = $this->pendingUser($request);

        if (!$user) {
            return redirect()->route('login')->withErrors(['email' => 'That sign-in session expired. Sign in again.']);
        }

        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ], [
            'code.required' => 'Enter the 6-digit code from your authenticator app.',
            'code.size' => 'The code is 6 digits, with no spaces.',
        ]);

        $attempts = (int) $request->session()->get('two_factor_attempts', 0) + 1;
        $request->session()->put('two_factor_attempts', $attempts);

        if (TotpService::verify((string) $user->two_factor_secret, $validated['code'])) {
            $remember = (bool) $request->session()->get('two_factor_remember', false);
            $this->forgetChallenge($request);

            return $this->completeLogin($request, $user, $remember, true);
        }

        if ($attempts >= self::MAX_CODE_ATTEMPTS) {
            $this->forgetChallenge($request);
            $this->logDenied($user, $request, 'two-factor session dropped after 5 wrong codes');

            return redirect()->route('login')->withErrors([
                'email' => 'Too many wrong codes. Sign in again.',
            ]);
        }

        return back()->withErrors([
            'code' => 'That code is wrong or has expired. ' . (self::MAX_CODE_ATTEMPTS - $attempts) . ' attempt(s) left.',
        ]);
    }

    public function cancelTwoFactor(Request $request)
    {
        $this->forgetChallenge($request);

        return redirect()->route('login')->withErrors(['email' => 'Sign in cancelled.']);
    }

    public function logout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            $reason = $request->query('reason') === 'inactive' ? ' (inactive session)' : '';
            ActivityLog::logAuth($user->id, 'logout', "User {$user->name} logged out from {$request->ip()}{$reason}");
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->query('reason') === 'inactive') {
            return redirect()->route('login')->with('success', 'You have been logged out due to inactivity.');
        }

        return redirect()->route('login')->with('success', 'You have been logged out successfully.');
    }

    private function completeLogin(Request $request, User $user, bool $remember = false, bool $throughTwoFactor = false)
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();

        $user->recordSuccessfulLogin($request->ip());

        $suffix = $throughTwoFactor ? ' with two-factor code' : '';
        ActivityLog::logAuth($user->id, 'login', "User {$user->name} logged in from {$request->ip()}{$suffix}");

        return redirect()->intended('/dashboard')->with('success', 'Welcome back, ' . $user->name . '!');
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('two_factor_user_id');
        $startedAt = (int) $request->session()->get('two_factor_started_at', 0);

        if (!$id || (now()->timestamp - $startedAt) > self::CHALLENGE_SECONDS) {
            $this->forgetChallenge($request);

            return null;
        }

        $user = User::find($id);

        if (!$user || !$user->two_factor_enabled || $user->isLocked() || !$user->is_active) {
            $this->forgetChallenge($request);

            return null;
        }

        return $user;
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget([
            'two_factor_user_id',
            'two_factor_started_at',
            'two_factor_attempts',
            'two_factor_remember',
        ]);
    }

    private function logDenied(User $user, Request $request, string $reason): void
    {
        ActivityLog::logAuth($user->id, 'login_blocked', "User {$user->name} sign-in blocked from {$request->ip()}: {$reason}");
    }
}
