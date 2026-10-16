<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PlusPay</title>
    <link rel="preload" as="image" href="{{ asset('login-background.webp') }}" fetchpriority="high">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-cover bg-center px-4 bg-green-950" style="background-image: url('{{ asset('login-background.webp') }}');">
    <div class="absolute inset-0 bg-black/60"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl p-6">
        <div class="text-center mb-4">
            <img src="{{ asset('logo.webp') }}" alt="PlusPay" width="640" height="232" class="h-14 w-auto mx-auto mb-2">
            <h1 class="text-2xl font-bold text-gray-900">Welcome back</h1>
            <p class="text-sm text-gray-500 mt-1">Sign in to your PlusPay account</p>
        </div>

        @if(session('success'))
            <div id="flash-toast" class="fixed top-4 right-4 z-50 bg-green-600 text-white px-5 py-3 rounded-lg shadow-lg text-sm font-medium flex items-center gap-2 transition-opacity duration-500">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('login.post') }}" class="space-y-4" id="loginForm">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" autocomplete="username" required autofocus
                    class="w-full rounded-lg border border-gray-300 px-4 py-3 text-gray-900 focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition @error('email') border-red-500 @enderror">
                @error('email')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <div class="relative">
                    <input id="password" type="password" name="password" autocomplete="current-password" required placeholder="••••••••"
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

            <button type="submit" id="signInBtn"
                class="w-full rounded-lg bg-green-800 hover:bg-green-700 active:bg-green-900 text-white font-semibold py-3 shadow-md transition flex items-center justify-center gap-2">
                <span id="signInText">Sign In</span>
                <svg id="signInSpinner" class="hidden animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </button>
        </form>

        {{-- Site Mode in System Settings controls this. Testing keeps the one-click
             logins for her, Production hides the list of valid admin emails from visitors. --}}
        @if (\App\Models\Setting::get('site_mode', 'testing') === 'testing')
        <div class="mt-5">
            <div class="flex items-center gap-3 mb-3">
                <div class="flex-1 h-px bg-gray-200"></div>
                <span class="text-xs uppercase tracking-wide text-gray-400">Demo accounts</span>
                <div class="flex-1 h-px bg-gray-200"></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                @php
                    // The SEED_*_PASSWORD values are only ever printed where the machine is the
                    // developer's own. On a public host the page stays silent about passwords.
                    $printPasswords = ! \App\Http\Controllers\SettingController::testingModeBlocked(request());
                    $demoPasswords = config('demo.accounts', []);
                @endphp
                @foreach (['Admin' => 'admin@plussave.com', 'Superadmin' => 'superadmin@plussave.com', 'Agent' => 'agent@plussave.com', 'Accountant' => 'accountant@plussave.com'] as $label => $demoEmail)
                    <button type="button" data-demo-email="{{ $demoEmail }}"@if($printPasswords && ! empty($demoPasswords[$demoEmail])) data-demo-password="{{ $demoPasswords[$demoEmail] }}"@endif
                        class="rounded-lg border border-green-700/30 text-green-800 text-sm font-medium py-2 hover:bg-green-50 transition">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <p id="demoHint" class="mt-2 text-center text-xs text-gray-500 hidden">Sign in once and the password is kept in this browser for these buttons.</p>
        </div>
        @endif
    </div>

    <script>
        (function() {
            const toast = document.getElementById('flash-toast');
            if (toast) {
                setTimeout(function() { toast.classList.add('opacity-0'); }, 3000);
                setTimeout(function() { toast.remove(); }, 3500);
            }
        })();

        // The demo buttons only exist while Site Mode is Testing. On a machine of the developer's
        // own the password comes from SEED_*_PASSWORD in .env. On a public host the page prints no
        // password at all, so the one typed for a demo account is kept in this browser instead and
        // refilled on the next click. Nothing is sent anywhere from here.
        (function() {
            const STORE = 'plussave.demoPasswords';
            const emailBox = document.getElementById('email');
            const passwordBox = document.getElementById('password');
            const form = document.getElementById('loginForm');
            const buttons = Array.from(document.querySelectorAll('[data-demo-email]'));

            if (!buttons.length) return;

            const accounts = buttons.map(function (button) { return button.dataset.demoEmail; });

            function saved() {
                try { return JSON.parse(localStorage.getItem(STORE)) || {}; } catch (e) { return {}; }
            }

            buttons.forEach(function (button) {
                button.addEventListener('click', function () {
                    emailBox.value = button.dataset.demoEmail;
                    const password = button.dataset.demoPassword || saved()[button.dataset.demoEmail] || '';
                    if (password) {
                        passwordBox.value = password;
                    }
                    passwordBox.focus();
                    document.getElementById('demoHint').classList.toggle('hidden', !!password);
                });
            });

            form.addEventListener('submit', function () {
                const email = emailBox.value.trim().toLowerCase();
                const password = passwordBox.value;

                if (accounts.includes(email) && password) {
                    const store = saved();
                    store[email] = password;
                    try { localStorage.setItem(STORE, JSON.stringify(store)); } catch (e) {}
                }
            });
        })();

        function togglePassword() {
            const p = document.getElementById('password');
            p.type = p.type === 'password' ? 'text' : 'password';
        }

        document.getElementById('loginForm').addEventListener('submit', function () {
            const btn = document.getElementById('signInBtn');
            const text = document.getElementById('signInText');
            const spinner = document.getElementById('signInSpinner');
            btn.disabled = true;
            text.textContent = 'Signing in...';
            spinner.classList.remove('hidden');
        });
    </script>
</body>
</html>
