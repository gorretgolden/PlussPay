<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'PlusPay') - Plus Save Financial Solutions</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            body { background: white; }
        }
        .print-only { display: none; }
    </style>
    @stack('styles')
</head>
<body class="bg-gray-100 min-h-screen">
    @auth
    <nav class="bg-blue-800 text-white shadow-lg no-print">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="{{ route('dashboard') }}" class="text-xl font-bold">PlusPay</a>
                    <div class="hidden md:flex ml-10 space-x-4">
                        <a href="{{ route('dashboard') }}" class="px-3 py-2 rounded-md text-sm font-medium hover:bg-blue-700 {{ request()->routeIs('dashboard') ? 'bg-blue-900' : '' }}">Dashboard</a>
                        <a href="{{ route('transactions.create') }}" class="px-3 py-2 rounded-md text-sm font-medium hover:bg-blue-700 {{ request()->routeIs('transactions.*') ? 'bg-blue-900' : '' }}">Send Money</a>
                        <a href="{{ route('receipts.index') }}" class="px-3 py-2 rounded-md text-sm font-medium hover:bg-blue-700 {{ request()->routeIs('receipts.*') ? 'bg-blue-900' : '' }}">Receipts</a>
                        @role('admin')
                        <a href="{{ route('users.index') }}" class="px-3 py-2 rounded-md text-sm font-medium hover:bg-blue-700 {{ request()->routeIs('users.*') ? 'bg-blue-900' : '' }}">Users</a>
                        <a href="{{ route('exchange-rates.index') }}" class="px-3 py-2 rounded-md text-sm font-medium hover:bg-blue-700 {{ request()->routeIs('exchange-rates.*') ? 'bg-blue-900' : '' }}">Exchange Rates</a>
                        @endrole
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">{{ auth()->user()->name }} <span class="text-blue-300">({{ auth()->user()->getRoleNames()->first() }})</span></span>
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="px-3 py-2 rounded-md text-sm font-medium hover:bg-blue-700">Logout</button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    {{-- Mobile nav --}}
    <div class="md:hidden bg-blue-800 text-white no-print">
        <div class="px-4 py-2 flex flex-wrap gap-2 text-sm">
            <a href="{{ route('dashboard') }}" class="px-2 py-1 rounded hover:bg-blue-700">Dashboard</a>
            <a href="{{ route('transactions.create') }}" class="px-2 py-1 rounded hover:bg-blue-700">Send Money</a>
            <a href="{{ route('receipts.index') }}" class="px-2 py-1 rounded hover:bg-blue-700">Receipts</a>
            @role('admin')
            <a href="{{ route('users.index') }}" class="px-2 py-1 rounded hover:bg-blue-700">Users</a>
            <a href="{{ route('exchange-rates.index') }}" class="px-2 py-1 rounded hover:bg-blue-700">Rates</a>
            @endrole
        </div>
    </div>
    @endauth

    <main class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        @if(session('success'))
            <div class="mb-4 bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded no-print">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded no-print">
                <ul class="list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
