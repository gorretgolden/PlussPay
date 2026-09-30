<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
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

        $key = 'login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->withErrors([
                'email' => 'Too many login attempts. Try again in ' . $seconds . ' seconds.',
            ])->onlyInput('email');
        }

        if (!Auth::attempt(['email' => $request->email, 'password' => $request->password], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            $user = \App\Models\User::where('email', $request->email)->first();
            $message = $user ? 'Invalid password.' : 'Invalid email.';

            return back()->withErrors(['email' => $message])->onlyInput('email');
        }

        RateLimiter::clear($key);

        $request->session()->regenerate();

        if (!Auth::user()->is_active) {
            Auth::logout();
            return back()->withErrors(['email' => 'Your account has been deactivated. Contact admin.']);
        }

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
