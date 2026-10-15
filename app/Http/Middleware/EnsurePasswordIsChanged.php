<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password && !$request->routeIs(
            'password.required', 'password.change', 'password.enroll', 'password.enroll.store',
            'logout', 'two-factor.*'
        )) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Change your password before continuing.', 'redirect' => route('password.required')], 403);
            }

            return redirect()->route('password.required');
        }

        return $next($request);
    }
}
