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
        .sidebar-link.active { background-color: #14532d; border-left: 3px solid #4ade80; }
    </style>
    @stack('styles')
</head>
<body class="bg-gray-100 min-h-screen">
    @auth
    <div class="flex min-h-screen">
        {{-- Sidebar --}}
        <aside class="w-64 bg-green-900 text-white flex flex-col no-print fixed h-full">
            <div class="p-6 border-b border-green-800">
                <a href="{{ route('dashboard') }}" class="text-2xl font-bold">PlusPay</a>
                <p class="text-green-300 text-xs mt-1">Plus Save Financial Solutions</p>
            </div>
            <nav class="flex-1 py-4">
                <a href="{{ route('dashboard') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4"/></svg>
                    Dashboard
                </a>
                <a href="{{ route('transactions.create') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('transactions.create') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Send Money
                </a>
                <a href="{{ route('transactions.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('transactions.index') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    Transactions
                </a>
                <a href="{{ route('receipts.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('receipts.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Receipts
                </a>
                <a href="{{ route('reports.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('reports.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Reports
                </a>
                @role('admin')
                <div class="px-6 pt-4 pb-2 text-xs text-green-400 uppercase tracking-wider">Admin</div>
                <a href="{{ route('users.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('users.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    Users
                </a>
                <a href="{{ route('exchange-rates.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('exchange-rates.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    Exchange Rates
                </a>
                @endrole
            </nav>
            <div class="p-4 border-t border-green-800">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-green-300">{{ auth()->user()->getRoleNames()->first() }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="text-xs text-green-300 hover:text-white">Logout</button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- Main content --}}
        <div class="ml-64 flex-1 flex flex-col">
            <main class="flex-1 p-6">
                @if(session('success'))
                    <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded no-print">
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
        </div>
    </div>
    @endauth

    @guest
    @yield('content')
    @endguest

    @stack('scripts')
</body>
</html>
