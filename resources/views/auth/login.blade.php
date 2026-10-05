<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PlusPay</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen flex items-center justify-center bg-cover bg-center bg-no-repeat" style="background-image: url('{{ asset('login-background.png') }}');">
    <div class="absolute inset-0 bg-black/40"></div>
    <div class="relative bg-white rounded-lg shadow-2xl p-6 w-full max-w-md mx-4 backdrop-blur-sm bg-white/95">
        <div class="text-center mb-6">
            <img src="{{ asset('logo.png') }}" alt="PlusPay" class="w-40 h-auto mx-auto mb-3">
        </div>

        <form method="POST" action="{{ route('login.post') }}">
            @csrf

            <div class="mb-3">
                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" id="email" value="{{ old('email') }}" required autofocus
                    class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500 @error('email') border-red-500 @enderror">
                @error('email')
                    <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mb-4">
                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                <input type="password" name="password" id="password" required
                    class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-green-500 focus:border-green-500">
            </div>

            <div class="flex items-center justify-between mb-4">
                <label class="flex items-center">
                    <input type="checkbox" name="remember" class="rounded border-gray-300 text-green-600">
                    <span class="ml-2 text-sm text-gray-600">Remember me</span>
                </label>
            </div>

            <button type="submit" class="w-full bg-green-800 text-white py-2 px-4 rounded-md hover:bg-green-700 font-medium">
                Sign In
            </button>
        </form>

        <div class="mt-4 pt-4 border-t border-gray-200">
            <p class="text-xs text-gray-500 text-center mb-2">Quick Login:</p>
            <div class="grid grid-cols-2 gap-2">
                <button type="button" onclick="fillLogin('admin@plussave.com', 'admin123')" class="bg-green-100 text-green-800 text-xs py-2 px-3 rounded hover:bg-green-200">
                    Admin
                </button>
                <button type="button" onclick="fillLogin('superadmin@plussave.com', 'superadmin123')" class="bg-purple-100 text-purple-800 text-xs py-2 px-3 rounded hover:bg-purple-200">
                    Superadmin
                </button>
                <button type="button" onclick="fillLogin('agent@plussave.com', 'agent123')" class="bg-blue-100 text-blue-800 text-xs py-2 px-3 rounded hover:bg-blue-200">
                    Agent
                </button>
                <button type="button" onclick="fillLogin('staff@plussave.com', 'staff123')" class="bg-gray-100 text-gray-800 text-xs py-2 px-3 rounded hover:bg-gray-200">
                    Staff
                </button>
            </div>
        </div>
    </div>

    <script>
        function fillLogin(email, password) {
            document.getElementById('email').value = email;
            document.getElementById('password').value = password;
        }
    </script>
</body>
</html>
