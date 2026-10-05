<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PlusPay</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-cover bg-center px-4" style="background-image: url('{{ asset('login-background.png') }}');">
    <div class="absolute inset-0 bg-black/60"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl p-8">
        <div class="text-center mb-6">
            <img src="{{ asset('logo.png') }}" alt="PlusPay" class="h-16 mx-auto mb-4">
            <h1 class="text-2xl font-bold text-gray-900">Welcome back</h1>
            <p class="text-sm text-gray-500 mt-1">Sign in to your PlusPay account</p>
        </div>

        <form method="POST" action="{{ route('login.post') }}" class="space-y-5">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus placeholder="you@example.com"
                    class="w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition @error('email') border-red-500 @enderror">
                @error('email')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <div class="relative">
                    <input id="password" type="password" name="password" required placeholder="••••••••"
                        class="w-full rounded-lg border border-gray-300 px-4 py-3 pr-12 text-gray-900 focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition">
                    <button type="button" onclick="togglePassword()"
                        class="absolute inset-y-0 right-3 flex items-center text-gray-400 hover:text-gray-600 text-sm">
                        <svg id="eye-icon" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2 text-gray-600">
                    <input type="checkbox" name="remember" class="rounded border-gray-300 text-green-700 focus:ring-green-600">
                    Remember me
                </label>
                <a href="#" class="text-green-700 font-medium hover:underline">Forgot password?</a>
            </div>

            <button type="submit"
                class="w-full rounded-lg bg-green-800 hover:bg-green-700 active:bg-green-900 text-white font-semibold py-3 shadow-md transition">
                Sign In
            </button>
        </form>

        @env('local')
        <div class="mt-8">
            <div class="flex items-center gap-3 mb-4">
                <div class="flex-1 h-px bg-gray-200"></div>
                <span class="text-xs uppercase tracking-wide text-gray-400">Demo accounts</span>
                <div class="flex-1 h-px bg-gray-200"></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <button type="button" onclick="fillLogin('admin@plussave.com', 'admin123')"
                    class="rounded-lg border border-green-700/30 text-green-800 text-sm font-medium py-2 hover:bg-green-50 transition">
                    Admin
                </button>
                <button type="button" onclick="fillLogin('superadmin@plussave.com', 'superadmin123')"
                    class="rounded-lg border border-green-700/30 text-green-800 text-sm font-medium py-2 hover:bg-green-50 transition">
                    Superadmin
                </button>
                <button type="button" onclick="fillLogin('agent@plussave.com', 'agent123')"
                    class="rounded-lg border border-green-700/30 text-green-800 text-sm font-medium py-2 hover:bg-green-50 transition">
                    Agent
                </button>
                <button type="button" onclick="fillLogin('staff@plussave.com', 'staff123')"
                    class="rounded-lg border border-green-700/30 text-green-800 text-sm font-medium py-2 hover:bg-green-50 transition">
                    Staff
                </button>
            </div>
        </div>
        @endenv
    </div>

    <script>
        function fillLogin(email, password) {
            document.getElementById('email').value = email;
            document.getElementById('password').value = password;
        }

        function togglePassword() {
            const p = document.getElementById('password');
            p.type = p.type === 'password' ? 'text' : 'password';
        }
    </script>
</body>
</html>
