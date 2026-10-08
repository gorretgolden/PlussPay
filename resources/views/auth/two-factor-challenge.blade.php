<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-factor sign-in - PlusPay</title>
    <link rel="preload" as="image" href="{{ asset('login-background.webp') }}" fetchpriority="high">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-cover bg-center px-4 bg-green-950" style="background-image: url('{{ asset('login-background.webp') }}');">
    <div class="absolute inset-0 bg-black/60"></div>

    <div class="relative w-full max-w-md bg-white rounded-2xl shadow-2xl p-6">
        <div class="text-center mb-4">
            <img src="{{ asset('logo.webp') }}" alt="PlusPay" width="640" height="232" class="h-14 w-auto mx-auto mb-2">
            <h1 class="text-2xl font-bold text-gray-900">Enter your code</h1>
            <p class="text-sm text-gray-500 mt-1">{{ $user->name }} &middot; two-factor sign-in is on for this account</p>
        </div>

        @if(session('success'))
            <div id="flash-toast" class="fixed top-4 right-4 z-50 bg-green-600 text-white px-5 py-3 rounded-lg shadow-lg text-sm font-medium flex items-center gap-2 transition-opacity duration-500">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('two-factor.verify') }}" class="space-y-4" id="codeForm">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">6-digit code</label>
                <input type="text" name="code" id="code" inputmode="numeric" autocomplete="one-time-code"
                    maxlength="6" required autofocus
                    class="w-full rounded-lg border border-gray-300 px-4 py-3 text-center text-gray-900 text-lg tracking-[0.4em] focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition @error('code') border-red-500 @enderror">
                @error('code')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
                <p class="text-xs text-gray-500 mt-2">Open your authenticator app and type the code showing right now. It changes every 30 seconds.</p>
            </div>

            <button type="submit" id="verifyBtn"
                class="w-full rounded-lg bg-green-800 hover:bg-green-700 active:bg-green-900 text-white font-semibold py-3 shadow-md transition flex items-center justify-center gap-2">
                <span id="verifyText">Verify and sign in</span>
                <svg id="verifySpinner" class="hidden animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </button>
        </form>

        <form method="POST" action="{{ route('two-factor.cancel') }}" class="mt-4 text-center">
            @csrf
            <button type="submit" class="text-green-700 font-medium text-sm hover:underline">Use a different account</button>
        </form>
    </div>

    <script>
        (function() {
            const toast = document.getElementById('flash-toast');
            if (toast) {
                setTimeout(function() { toast.classList.add('opacity-0'); }, 3000);
                setTimeout(function() { toast.remove(); }, 3500);
            }

            const code = document.getElementById('code');
            code.addEventListener('input', function () {
                code.value = code.value.replace(/\D/g, '');
            });

            document.getElementById('codeForm').addEventListener('submit', function () {
                const btn = document.getElementById('verifyBtn');
                const text = document.getElementById('verifyText');
                const spinner = document.getElementById('verifySpinner');
                btn.disabled = true;
                text.textContent = 'Checking...';
                spinner.classList.remove('hidden');
            });
        })();
    </script>
</body>
</html>
