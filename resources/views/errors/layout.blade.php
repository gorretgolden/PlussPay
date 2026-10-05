<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('code') @yield('title') - PlusPay</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-100 px-4">
    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl p-8 text-center">
        <div class="text-6xl font-bold text-green-800 mb-2">@yield('code')</div>
        <h1 class="text-xl font-semibold text-gray-900 mb-2">@yield('title')</h1>
        <p class="text-sm text-gray-500 mb-6">@yield('message')</p>

        <div class="flex flex-col gap-3">
            @auth
                <a href="{{ route('dashboard') }}"
                    class="w-full rounded-lg bg-green-800 hover:bg-green-700 text-white font-semibold py-2.5 transition">
                    Back to Dashboard
                </a>
                @role('admin|superadmin')
                    <a href="{{ route('admin.activity-logs') }}"
                        class="w-full rounded-lg border border-green-700/30 text-green-800 font-medium py-2.5 hover:bg-green-50 transition">
                        View Activity Logs
                    </a>
                    <a href="{{ route('admin.login-logs') }}"
                        class="w-full rounded-lg border border-green-700/30 text-green-800 font-medium py-2.5 hover:bg-green-50 transition">
                        View Login Logs
                    </a>
                @endrole
            @else
                <a href="{{ route('login') }}"
                    class="w-full rounded-lg bg-green-800 hover:bg-green-700 text-white font-semibold py-2.5 transition">
                    Go to Login
                </a>
            @endauth
        </div>
    </div>
</body>
</html>
