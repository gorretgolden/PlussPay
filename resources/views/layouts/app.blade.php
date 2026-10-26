<!DOCTYPE html>
<html lang="en" class="@auth{{ auth()->user()->theme === 'dark' ? 'dark' : '' }}@endauth">
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
        .sidebar-sublink.active { background-color: #14532d; color: #4ade80; }
        .sidebar-sublink { padding-left: 2.5rem; }
        .sidebar-group-header { padding-left: 1.5rem; }
        .sidebar-nav { scrollbar-width: thin; scrollbar-color: #166534 transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 6px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: #166534; border-radius: 3px; }
        :where(button, a, input, select, textarea, summary):focus-visible { outline: 2px solid #15803d; outline-offset: 3px; }
        .admin-confirm::backdrop { background: rgb(0 0 0 / 50%); }
        .admin-confirm { max-width: min(28rem, calc(100vw - 2rem)); }
        /* A form on its way to the server reads as frozen: no pointer reaches a box, selects and
           tick boxes grey out, and text fields are read only so their values still post. */
        .form-locked { cursor: progress; }
        .form-locked :is(input, select, textarea, button, a, label) { pointer-events: none; }
        .form-locked :is(button, a) { opacity: .6; }
        .form-locked :is(input:not([type=hidden]), select, textarea, button[role="combobox"]) { background-color: #f3f4f6; }
        .field-invalid { border-color: #b91c1c !important; }
        .field-invalid:not([type=checkbox]):not([type=radio]) { border-width: 2px; box-shadow: none; background-color: #fff; color: inherit; }
        [data-error-summary]:not(:empty) { border-left: 3px solid #b42318; }
        [data-error-summary] a { color: #b42318; text-decoration: underline; }
        [data-error-summary] a + a::before { content: ' · '; text-decoration: none; }
        .field-error:not([hidden]):not(:empty)::before { content: '!'; display: inline-flex; align-items: center; justify-content: center; width: 1rem; height: 1rem; background: #b91c1c; color: #fff; border: 0; border-radius: 50%; font-size: .7rem; margin-right: .35rem; }
        /* Per-user dark theme: the same surfaces, dimmed. The sidebar and header keep their
           green; only the reading surfaces, boxes and text follow the choice. */
        .dark body { background-color: #0b1220; }
        .dark .bg-white { background-color: #1e293b; }
        .dark .bg-gray-50 { background-color: #111827; }
        .dark .bg-gray-100 { background-color: #1f2937; }
        .dark .bg-gray-200 { background-color: #334155; }
        .dark .border-gray-200 { border-color: #334155; }
        .dark .border-gray-300 { border-color: #475569; }
        .dark .text-gray-900 { color: #f1f5f9; }
        .dark .text-gray-800 { color: #e2e8f0; }
        .dark .text-gray-700 { color: #cbd5e1; }
        .dark .text-gray-600 { color: #94a3b8; }
        .dark .text-gray-500 { color: #7c8ba1; }
        .dark .text-gray-400 { color: #64748b; }
        .dark input, .dark select, .dark textarea { background-color: #0f172a; color: #e2e8f0; border-color: #475569; }
        .dark .form-locked :is(input:not([type=hidden]), select, textarea, button[role="combobox"]) { background-color: #0f172a; }
        .dark .bg-green-50 { background-color: #052e16; }
        .dark .bg-green-100 { background-color: #14532d; }
        .dark .bg-red-50 { background-color: #450a0a; }
        .dark .bg-red-100 { background-color: #7f1d1d; }
        .dark .bg-amber-50 { background-color: #451a03; }
        .dark .bg-amber-100 { background-color: #78350f; }
        .dark .bg-blue-50 { background-color: #172554; }
        .dark .bg-blue-100 { background-color: #1e3a8a; }
        .dark .text-green-950, .dark .text-green-900, .dark .text-green-800 { color: #86efac; }
        .dark .text-green-700, .dark .text-green-600 { color: #4ade80; }
        .dark .text-red-800 { color: #fca5a5; }
        .dark .text-red-700, .dark .text-red-600 { color: #f87171; }
        .dark .text-amber-800 { color: #fcd34d; }
        .dark .text-amber-700, .dark .text-amber-600 { color: #fcd34d; }
        .dark .text-blue-800, .dark .text-blue-700, .dark .text-blue-600 { color: #93c5fd; }
        .dark .border-red-200, .dark .border-red-300, .dark .border-red-400 { border-color: #7f1d1d; }
        .dark .border-amber-200, .dark .border-amber-300 { border-color: #78350f; }
        .dark .border-green-200 { border-color: #14532d; }
        .dark .border-blue-200 { border-color: #1e3a8a; }
        .dark .hover\:bg-gray-50:hover { background-color: #1e293b; }
        .dark .hover\:bg-green-50:hover { background-color: #052e16; }
        .dark dialog.admin-confirm { background-color: #1e293b; }
        .dark dialog.admin-confirm .text-gray-800 { color: #e2e8f0; }
        .dark dialog.admin-confirm [data-cancel] { background-color: #334155; }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { scroll-behavior: auto !important; transition-duration: 0s !important; } }
    </style>
    @stack('styles')
</head>
<body class="bg-[#f5f7f6] min-h-screen">
    @auth
    <div class="flex">
        {{-- Sidebar --}}
        <aside id="appSidebar" class="w-64 bg-green-900 text-white flex flex-col no-print fixed h-full z-40 -translate-x-full md:translate-x-0 transition-transform duration-200">
            <button type="button" onclick="toggleSidebar(false)" class="md:hidden absolute right-2 top-2 p-2 rounded bg-green-800" aria-label="Close the menu">&times;</button>
            <div class="p-4 border-b border-green-800">
                <a href="{{ route('dashboard') }}" class="block">
                    <img src="{{ asset('logo.webp') }}" alt="PlusPay" width="640" height="232" class="w-full h-auto">
                </a>
            </div>
            <nav class="sidebar-nav flex-1 py-4 overflow-y-auto">
                @can('view-dashboard')
                <a href="{{ route('dashboard') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4"/></svg>
                    Dashboard
                </a>
                @endcan
                {{-- Word of what the desk did while you were away. No ability gate here: the
                     controller is what proves a reader can open any of it, and this is the set
                     of abilities that can earn a notice at all. --}}
                @canany(\App\Support\ActivityNews::AUDIENCE)
                <a href="{{ route('notifications.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('notifications.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                    <span class="flex-1">What Happened</span>
                    @include('partials._news-badge', ['slot' => 'total'])
                </a>
                @endcanany
                @can('view-wallets')
                <a href="{{ route('wallet.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('wallet.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    My Wallet
                </a>
                <a href="{{ route('cash-handovers.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('cash-handovers.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    Cash Hand-ins
                </a>
                @endcan
                @can('view-transactions')
                <a href="{{ route('transactions.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('transactions.index') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    <span class="flex-1">Transactions</span>
                    @include('partials._today-badge')
                </a>
                @endcan
                @can('view-receipts')
                <a href="{{ route('receipts.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('receipts.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Receipts
                </a>
                @endcan
                @can('view-exchanges')
                <a href="{{ route('exchanges.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('exchanges.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                    <span class="flex-1">Currency Exchange</span>
                    @include('partials._news-badge', ['slot' => 'exchanges'])
                </a>
                @endcan
                @canany(['send-to-china', 'confirm-china-payout'])
                <a href="{{ route('china.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('china.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.5 3.75-9S14.5 6.5 12 3.9M12 21c-2.5-2.5-3.75-5.5-3.75-9S9.5 6.5 12 3.9M3.5 9h17M3.5 15h17"/></svg>
                    <span class="flex-1">China Desk</span>
                    @include('partials._news-badge', ['slot' => 'china'])
                </a>
                @endcan
                @can('confirm-china-payout')
                <a href="{{ route('uganda.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('uganda.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.5 3.75-9S14.5 6.5 12 3.9M12 21c-2.5-2.5-3.75-5.5-3.75-9S9.5 6.5 12 3.9M3.5 9h17M3.5 15h17"/></svg>
                    <span class="flex-1">Uganda Desk</span>
                    @include('partials._news-badge', ['slot' => 'uganda'])
                </a>
                @endcan
                @can('view-customers')
                <a href="{{ route('customers.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('customers.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    Customers
                </a>
                @endcan

                {{-- Read as "money out": an arrow leaving the wallet. The wallet glyph is taken
                     by My Wallet and the banknote one by the Accounts group. --}}
                @can('view-expenses')
                <a href="{{ route('expenses.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('expenses.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v11m0 0l-4-4m4 4l4-4M4 20h16"/></svg>
                    <span class="flex-1">Expenses</span>
                    @include('partials._news-badge', ['slot' => 'expenses'])
                </a>
                @endcan

                {{-- Accounts Group --}}
                @can('view-accounts')
                <div>
                    <button type="button" onclick="toggleAccountsGroup()" class="w-full flex items-center justify-between px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('accounts.*') ? 'bg-green-800' : '' }}">
                        <span class="flex items-center">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                            Accounts
                        </span>
                        <svg id="accountsChevron" class="w-4 h-4 transition-transform {{ request()->routeIs('accounts.*') ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div id="accountsGroup" class="{{ request()->routeIs('accounts.*') ? '' : 'hidden' }}">
                        <a href="{{ route('accounts.index') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('accounts.index') ? 'active' : '' }}">
                            Balances
                        </a>
                        <a href="{{ route('accounts.movements') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('accounts.movements') ? 'active' : '' }}">
                            Money Movements
                        </a>
                        @can('create-account-entry')
                        <a href="{{ route('accounts.entries.create') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('accounts.entries.create') ? 'active' : '' }}">
                            Add Ledger Entry
                        </a>
                        @endcan
                        <a href="{{ route('accounts.daily-balances') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('accounts.daily-balances') ? 'active' : '' }}">
                            Daily Balances
                        </a>
                    </div>
                </div>
                @endcan
                @can('view-reports')
                <div>
                    <button type="button" onclick="toggleReportsGroup()" class="w-full flex items-center justify-between px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('reports.*') ? 'bg-green-800' : '' }}">
                        <span class="flex items-center">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            Reports
                        </span>
                        <svg id="reportsChevron" class="w-4 h-4 transition-transform {{ request()->routeIs('reports.*') ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div id="reportsGroup" class="{{ request()->routeIs('reports.*') ? '' : 'hidden' }}">
                        @foreach(\App\Support\ReportCatalog::nav(auth()->user()) as $key => $label)
                        @php $isHub = $key === 'hub'; @endphp
                        <a href="{{ route('reports.index', $isHub ? [] : ['type' => $key]) }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ $isHub ? (request()->routeIs('reports.index') && !request()->query('type') ? 'active' : '') : (request()->query('type') === $key ? 'active' : '') }}">
                            {{ $label }}
                        </a>
                        @endforeach
                    </div>
                </div>
                @endcan
                @can('view-agent-performance')
                <a href="{{ route('agents.performance') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('agents.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Agent Performance
                </a>
                @endcan

                {{-- User Management Group --}}
                @canany(['view-users', 'view-roles'])
                <div>
                    <button type="button" onclick="toggleUserMgmtGroup()" class="w-full flex items-center justify-between px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('users.*') || request()->routeIs('admin.roles') || request()->routeIs('admin.permissions') ? 'bg-green-800' : '' }}">
                        <span class="flex items-center">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            User Management
                            @include('partials._news-badge', ['slot' => 'users'])
                        </span>
                        <svg id="userMgmtChevron" class="w-4 h-4 transition-transform {{ request()->routeIs('users.*') || request()->routeIs('admin.roles') || request()->routeIs('admin.permissions') ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div id="userMgmtGroup" class="{{ request()->routeIs('users.*') || request()->routeIs('admin.roles') || request()->routeIs('admin.permissions') ? '' : 'hidden' }}">
                        @can('view-users')
                        <a href="{{ route('users.index') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('users.*') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Users
                        </a>
                        @endcan
                        @can('view-roles')
                        <a href="{{ route('admin.roles') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('admin.roles') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Roles & Permissions
                        </a>
                        <a href="{{ route('admin.permissions') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('admin.permissions') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.53 5.81L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.93-5.93A6 6 0 1121 9z"/></svg>
                            All Permissions
                        </a>
                        @endcan
                    </div>
                </div>
                @endcanany

                {{-- Settings Group --}}
                @canany(['view-settings', 'view-exchange-rates', 'manage-expense-categories'])
                <div>
                    <button type="button" onclick="toggleSettingsGroup()" class="w-full flex items-center justify-between px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('settings.*') || request()->routeIs('exchange-rates.*') || request()->routeIs('expense-categories.*') ? 'bg-green-800' : '' }}">
                        <span class="flex items-center">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Settings
                            @include('partials._news-badge', ['slot' => 'settings'])
                        </span>
                        <svg id="settingsChevron" class="w-4 h-4 transition-transform {{ request()->routeIs('settings.*') || request()->routeIs('exchange-rates.*') || request()->routeIs('expense-categories.*') ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div id="settingsGroup" class="{{ request()->routeIs('settings.*') || request()->routeIs('exchange-rates.*') || request()->routeIs('expense-categories.*') ? '' : 'hidden' }}">
                        @can('view-settings')
                        <a href="{{ route('settings.index') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('settings.*') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
                            System Settings
                        </a>
                        @endcan
                        @can('view-exchange-rates')
                        <a href="{{ route('exchange-rates.index') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('exchange-rates.*') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                            Exchange Rates
                        </a>
                        @endcan
                        {{-- The heads are the list an expense has to fall under, so it is set up
                             here once by an admin rather than typed at every booking. --}}
                        @can('manage-expense-categories')
                        <a href="{{ route('expense-categories.index') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('expense-categories.*') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h10"/></svg>
                            Expense categories
                        </a>
                        @endcan
                    </div>
                </div>
                @endcanany

                {{-- Logs Group --}}
                @canany(['view-activity-logs', 'view-login-logs', 'view-system-logs'])
                <div>
                    <button type="button" onclick="toggleLogsGroup()" class="w-full flex items-center justify-between px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('admin.audit-logs') || request()->routeIs('admin.system-logs') ? 'bg-green-800' : '' }}">
                        <span class="flex items-center">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                            Logs
                        </span>
                        <svg id="logsChevron" class="w-4 h-4 transition-transform {{ request()->routeIs('admin.audit-logs') || request()->routeIs('admin.system-logs') ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div id="logsGroup" class="{{ request()->routeIs('admin.audit-logs') || request()->routeIs('admin.system-logs') ? '' : 'hidden' }}">
                        @canany(['view-activity-logs', 'view-login-logs'])
                        <a href="{{ route('admin.audit-logs') }}" class="sidebar-sublink flex items-center justify-between py-2 text-sm hover:bg-green-800 {{ request()->routeIs('admin.audit-logs') ? 'active' : '' }}">
                            <span class="flex items-center"><svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>Audit Logs</span>
                            @if(($sidebarFailedLogins ?? 0) > 0)
                            <span class="ml-2 bg-amber-500 text-white text-xs rounded-full px-2 py-0.5">{{ $sidebarFailedLogins }}</span>
                            @endif
                        </a>
                        @endcanany
                        @can('view-system-logs')
                        <a href="{{ route('admin.system-logs') }}" class="sidebar-sublink flex items-center justify-between py-2 text-sm hover:bg-green-800 {{ request()->routeIs('admin.system-logs') ? 'active' : '' }}">
                            <span class="flex items-center"><svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>System Errors</span>
                            @if(($sidebarUnresolvedErrors ?? 0) > 0)
                            <span class="ml-2 bg-red-600 text-white text-xs rounded-full px-2 py-0.5">{{ $sidebarUnresolvedErrors }}</span>
                            @endif
                        </a>
                        @endcan
                    </div>
                </div>
                @endcanany

                {{-- Without a role gate: everyone who signs in can read their own details and
                     change their own password, whatever desk they work. --}}
                <a href="{{ route('account.show') }}" class="sidebar-link flex items-center px-6 py-3 mt-2 border-t border-green-800 text-sm hover:bg-green-800 {{ request()->routeIs('account.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    My Account
                </a>

            </nav>
            <div class="p-4 border-t border-green-800">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-green-700 flex items-center justify-center text-sm font-bold uppercase flex-shrink-0">
                        {{ mb_substr(auth()->user()->name, 0, 1) }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-medium truncate">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-green-300 truncate">
                            {{ \App\Http\Controllers\RolePermissionController::displayName(auth()->user()->getRoleNames()->first() ?? '') }} · {{ auth()->user()->countryLabel() ?: 'Desk country not set' }}
                        </p>
                    </div>
                </div>
                <form id="themeForm" method="POST" action="{{ route('account.theme') }}" class="mt-3">
                    @csrf
                    <input type="hidden" name="theme" value="{{ auth()->user()->theme === 'dark' ? 'light' : 'dark' }}">
                    <button type="submit"
                        class="w-full flex items-center justify-center gap-2 bg-green-800 hover:bg-green-700 border border-green-600 text-white text-sm font-medium px-3 py-2 rounded-md transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                        {{ auth()->user()->theme === 'dark' ? 'Light mode' : 'Dark mode' }}
                    </button>
                </form>
                <form id="sidebarLogoutForm" method="POST" action="{{ route('logout') }}" class="mt-3">
                    @csrf
                    <button type="submit"
                        class="w-full flex items-center justify-center gap-2 bg-green-800 hover:bg-green-700 border border-green-600 text-white text-sm font-medium px-3 py-2 rounded-md transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        Log out
                    </button>
                </form>
            </div>
        </aside>

        {{-- Main content --}}
        <div id="sidebarOverlay" class="hidden fixed inset-0 bg-black/50 z-30 md:hidden no-print" onclick="toggleSidebar(false)"></div>
        <div class="app-shell ml-0 md:ml-64 w-full md:w-[calc(100%-16rem)] min-w-0">
            <div class="md:hidden flex items-center gap-3 bg-green-900 text-white px-4 py-3 no-print sticky top-0 z-20">
                <button type="button" onclick="toggleSidebar()" aria-label="Open the menu" aria-controls="appSidebar" aria-expanded="false"
                    class="p-1 rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-green-300">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <span class="text-sm font-semibold">PlusPay</span>
            </div>
            <main class="p-4 md:p-6 min-w-0">
                @if(session('success'))
                    <div id="flash-toast" class="fixed top-4 right-4 z-50 bg-green-600 text-white px-5 py-3 rounded-lg shadow-lg text-sm font-medium flex items-center gap-2 transition-opacity duration-500 no-print">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        {{ session('success') }}
                    </div>
                @endif

                @if($errors->any() && (!View::hasSection('inline-validation') || $errors->has('error')))
                    <div id="flash-error" class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded no-print flex items-start gap-3 transition-opacity duration-500" role="alert">
                        <ul class="list-disc list-inside flex-1">
                            @foreach(View::hasSection('inline-validation') ? $errors->get('error') : $errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        <button type="button" onclick="dismissFlashError()" aria-label="Dismiss this message"
                            class="-mr-1 -mt-1 p-1 rounded text-red-700 hover:bg-red-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
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

    <script src="{{ asset('js/admin-forms.js') }}"></script>
    @stack('scripts')
    <script>
        function toggleReportsGroup() {
            const group = document.getElementById('reportsGroup');
            const chevron = document.getElementById('reportsChevron');
            group.classList.toggle('hidden');
            chevron.classList.toggle('rotate-180');
        }
        function toggleAccountsGroup() {
            const group = document.getElementById('accountsGroup');
            const chevron = document.getElementById('accountsChevron');
            group.classList.toggle('hidden');
            chevron.classList.toggle('rotate-180');
        }
        function toggleUserMgmtGroup() {
            const group = document.getElementById('userMgmtGroup');
            const chevron = document.getElementById('userMgmtChevron');
            group.classList.toggle('hidden');
            chevron.classList.toggle('rotate-180');
        }
        function toggleLogsGroup() {
            const group = document.getElementById('logsGroup');
            const chevron = document.getElementById('logsChevron');
            group.classList.toggle('hidden');
            chevron.classList.toggle('rotate-180');
        }
        function toggleSettingsGroup() {
            const group = document.getElementById('settingsGroup');
            const chevron = document.getElementById('settingsChevron');
            group.classList.toggle('hidden');
            chevron.classList.toggle('rotate-180');
        }

        function toggleSidebar(force) {
            const bar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const button = document.querySelector('[aria-controls="appSidebar"]');
            const open = force === undefined ? bar.classList.contains('-translate-x-full') : force;

            bar.classList.toggle('-translate-x-full', !open);
            overlay.classList.toggle('hidden', !open);
            bar.inert = !open && !window.matchMedia('(min-width: 768px)').matches;
            if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) bar.querySelector('button').focus();
            else button?.focus();
        }

        const sidebar = document.getElementById('appSidebar');
        const desktopNav = window.matchMedia('(min-width: 768px)');
        if (sidebar) {
            sidebar.inert = !desktopNav.matches;
            desktopNav.addEventListener('change', () => {
                sidebar.inert = !desktopNav.matches;
                sidebar.classList.add('-translate-x-full');
                document.getElementById('sidebarOverlay').classList.add('hidden');
                document.querySelector('[aria-controls="appSidebar"]').setAttribute('aria-expanded', 'false');
            });
            sidebar.addEventListener('keydown', (event) => {
                if (desktopNav.matches) return;
                if (event.key === 'Escape') { toggleSidebar(false); return; }
                if (event.key !== 'Tab') return;
                const focusable = Array.from(sidebar.querySelectorAll('a,button,input,select')).filter(el => el.offsetParent !== null && !el.disabled);
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            });
        }

        // A row menu sits inside a table that scrolls sideways, so a dropdown placed against the
        // row is cut off by that box and reads as a button that opens nothing. An open menu is
        // lifted to fixed coordinates under its own row, and flips above it when the row is at
        // the bottom of the screen.
        function placeMenu(menu) {
            const host = menu.closest('[data-row-menu-host]');
            if (!host) return;

            const rect = host.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.marginTop = '0';
            menu.style.left = 'auto';
            menu.style.right = (window.innerWidth - rect.right) + 'px';
            menu.style.top = (rect.bottom + 4) + 'px';

            if (rect.bottom + 4 + menu.offsetHeight > window.innerHeight
                && rect.top - menu.offsetHeight - 4 > 0) {
                menu.style.top = (rect.top - menu.offsetHeight - 4) + 'px';
            }
        }

        function hideMenu(menu) {
            menu.classList.add('hidden');
            menu.style.position = '';
            menu.style.top = '';
            menu.style.right = '';
            menu.style.left = '';
            menu.style.marginTop = '';
        }

        function toggleMenu(key) {
            const target = document.getElementById('menu-' + key);
            document.querySelectorAll('[data-row-menu]').forEach(function(menu) {
                if (menu !== target) hideMenu(menu);
            });

            if (target.classList.contains('hidden')) {
                target.classList.remove('hidden');
                placeMenu(target);
            } else {
                hideMenu(target);
            }
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('[data-row-menu-host]')) {
                document.querySelectorAll('[data-row-menu]:not(.hidden)').forEach(hideMenu);
            }
        });

        // The menu belongs to its row, so it moves with the page and the table.
        window.addEventListener('scroll', function() {
            document.querySelectorAll('[data-row-menu]:not(.hidden)').forEach(placeMenu);
        }, true);

        window.addEventListener('resize', function() {
            document.querySelectorAll('[data-row-menu]:not(.hidden)').forEach(placeMenu);
        });

        // Searchable dropdowns. The native select stays in the DOM so form
        // submit, required validation and error styling still work.
        (function() {
            const CARET = '<svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>';

            let selectIndex = 0;
            function enhance(sel) {
                if (sel.multiple || sel.dataset.noSearch !== undefined || sel.dataset.searchable) return;
                if (sel.options.length < 2) return;
                sel.dataset.searchable = '1';

                const wrap = document.createElement('div');
                wrap.className = 'relative';

                const trigger = document.createElement('button');
                trigger.type = 'button';
                trigger.disabled = sel.disabled;
                trigger.className = (sel.className ? sel.className + ' ' : '')
                    + 'flex items-center justify-between gap-2 text-left outline-none';
                trigger.innerHTML = '<span class="truncate ss-label"></span>' + CARET;
                const listId = 'select-options-' + (++selectIndex);
                trigger.setAttribute('role', 'combobox');
                trigger.setAttribute('aria-haspopup', 'listbox');
                trigger.setAttribute('aria-expanded', 'false');
                trigger.setAttribute('aria-controls', listId);
                const fieldLabel = sel.id ? document.querySelector('label[for="' + CSS.escape(sel.id) + '"]') : null;
                trigger.setAttribute('aria-label', sel.getAttribute('aria-label') || fieldLabel?.textContent.trim() || sel.name.replaceAll('_', ' '));

                const panel = document.createElement('div');
                panel.className = 'absolute left-0 top-full z-40 mt-1 w-full min-w-[14rem] bg-white rounded-lg shadow-xl border border-gray-200 hidden text-left';

                const search = document.createElement('input');
                search.type = 'text';
                search.placeholder = 'Search...';
                search.className = 'w-full px-3 py-2 border-b border-gray-100 rounded-t-lg text-sm outline-none focus:bg-green-50';
                if (sel.options.length <= 2) search.classList.add('hidden');

                const list = document.createElement('ul');
                list.className = 'max-h-56 overflow-y-auto py-1';
                list.id = listId;
                list.setAttribute('role', 'listbox');
                search.setAttribute('aria-label', 'Search ' + trigger.getAttribute('aria-label'));

                sel.parentNode.insertBefore(wrap, sel);
                wrap.appendChild(trigger);
                wrap.appendChild(panel);
                panel.appendChild(search);
                panel.appendChild(list);
                wrap.appendChild(sel);

                Object.assign(sel.style, {
                    position: 'absolute', width: '1px', height: '1px',
                    margin: '-1px', padding: '0', border: '0',
                    overflow: 'hidden', opacity: '0', pointerEvents: 'none',
                });
                sel.tabIndex = -1;
                sel.setAttribute('aria-hidden', 'true');

                const labelSpan = trigger.querySelector('.ss-label');
                const items = Array.from(sel.options).map(function(opt) {
                    const li = document.createElement('li');
                    li.className = 'px-3 py-2 text-sm hover:bg-green-50 cursor-pointer truncate';
                    li.textContent = opt.textContent.trim();
                    li.dataset.index = opt.index;
                    li.id = listId + '-' + opt.index;
                    li.tabIndex = -1;
                    li.setAttribute('role', 'option');
                    li.setAttribute('aria-disabled', opt.disabled ? 'true' : 'false');
                    // Label and code are kept apart so a search can rank the name above the
                    // value instead of treating them as one string.
                    li.dataset.label = opt.textContent.trim().toLowerCase();
                    li.dataset.value = opt.value.toLowerCase();
                    if (opt.disabled) li.classList.add('opacity-40', 'cursor-not-allowed');
                    list.appendChild(li);
                    return li;
                });

                function render() {
                    const opt = sel.options[sel.selectedIndex];
                    const text = opt ? opt.textContent.trim() : '';
                    labelSpan.textContent = text;
                    labelSpan.classList.toggle('text-gray-600', !opt || opt.value === '');
                    items.forEach(function(li) {
                        li.classList.toggle('bg-green-50', Number(li.dataset.index) === sel.selectedIndex);
                        li.setAttribute('aria-selected', Number(li.dataset.index) === sel.selectedIndex ? 'true' : 'false');
                    });
                }

                const isOpen = () => !panel.classList.contains('hidden');
                // Read from the panel rather than the option list, because a search puts the
                // best matches at the top and the arrow keys follow what the eye sees.
                const visible = () => Array.from(list.children).filter(li => !li.classList.contains('hidden'));

                function rank(li, q) {
                    const label = li.dataset.label;
                    if (label === q) return 0;
                    if (label.startsWith(q)) return 1;
                    if (label.includes(q)) return 2;
                    const value = li.dataset.value;
                    if (value === q) return 3;
                    if (value.startsWith(q)) return 4;
                    if (value.includes(q)) return 5;
                    return -1;
                }

                // Typing "admin" used to leave Super Admin first in the list because its value
                // is "superadmin", and Enter picked whatever sat on top. The name decides the
                // order; the value only breaks a tie.
                function filter() {
                    const q = search.value.toLowerCase().trim();
                    if (q === '') {
                        items.forEach(li => {
                            li.classList.remove('hidden');
                            list.appendChild(li);
                        });
                        return;
                    }
                    const hits = items
                        .map(li => [rank(li, q), li])
                        .filter(pair => pair[0] >= 0)
                        .sort((a, b) => a[0] - b[0]);
                    const keep = new Set(hits.map(pair => pair[1]));
                    items.forEach(li => li.classList.toggle('hidden', !keep.has(li)));
                    hits.forEach(pair => list.appendChild(pair[1]));
                }

                let active = -1;
                function highlight(i) {
                    const vis = visible();
                    items.forEach(li => li.classList.remove('bg-gray-100'));
                    active = i;
                    if (vis[i]) {
                        vis[i].classList.add('bg-gray-100');
                        trigger.setAttribute('aria-activedescendant', vis[i].id);
                        vis[i].scrollIntoView({ block: 'nearest' });
                    } else trigger.removeAttribute('aria-activedescendant');
                }

                function open() {
                    panel.classList.remove('hidden');
                    trigger.setAttribute('aria-expanded', 'true');
                    search.value = '';
                    filter();
                    highlight(-1);
                    if (!search.classList.contains('hidden')) search.focus();
                }

                function close() {
                    panel.classList.add('hidden');
                    trigger.setAttribute('aria-expanded', 'false');
                    trigger.removeAttribute('aria-activedescendant');
                }

                function pick(index) {
                    const opt = sel.options[index];
                    if (!opt || opt.disabled) return;
                    sel.value = opt.value;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                    // A form with the inline validator clears its own error on that change event
                    // and repaints the summary. Forms without it leave the marks from a previous
                    // attempt behind, so the click takes the field out of the invalid state here.
                    if (opt.value !== '' && sel.getAttribute('aria-invalid') === 'true'
                        && sel.form && !sel.form.dataset.validationReady) {
                        const node = sel.form.querySelector('[data-field-error="' + sel.name + '"]');
                        if (node) {
                            node.textContent = '';
                            node.hidden = true;
                            const described = new Set((sel.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
                            described.delete(node.id);
                            if (described.size) sel.setAttribute('aria-describedby', Array.from(described).join(' '));
                            else sel.removeAttribute('aria-describedby');
                        }
                        sel.setAttribute('aria-invalid', 'false');
                        sel.classList.remove('field-invalid', 'ring-2', 'ring-red-400');
                    }
                    render();
                    close();
                    trigger.focus();
                }

                trigger.addEventListener('click', () => isOpen() ? close() : open());
                trigger.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') { close(); return; }
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (!isOpen()) open();
                        highlight(Math.max(0, Math.min(visible().length - 1, active + (event.key === 'ArrowDown' ? 1 : -1))));
                    } else if (isOpen() && event.key === 'Enter') {
                        event.preventDefault();
                        const option = visible()[active] || visible()[0];
                        if (option) pick(Number(option.dataset.index));
                    }
                });
                // Pressing the mouse on an option used to move focus off the search box, which
                // hid the panel before the click landed, so the option was never picked and the
                // select kept its empty value under "This field is required". Blocking that focus
                // change lets the click through, and the guard keeps a tap inside the panel from
                // counting as leaving it.
                list.addEventListener('mousedown', (event) => event.preventDefault());
                wrap.addEventListener('focusout', (event) => {
                    if (wrap.contains(event.relatedTarget)) return;
                    queueMicrotask(() => { if (!wrap.contains(document.activeElement)) close(); });
                });
                search.addEventListener('input', () => { filter(); highlight(0); });
                search.addEventListener('keydown', function(e) {
                    const vis = visible();
                    if (e.key === 'ArrowDown') { e.preventDefault(); highlight(Math.min(active + 1, vis.length - 1)); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(active - 1, 0)); }
                    else if (e.key === 'Enter') { e.preventDefault(); const t = vis[active] || vis[0]; if (t) pick(Number(t.dataset.index)); }
                    else if (e.key === 'Escape') { close(); trigger.focus(); }
                });
                list.addEventListener('click', function(e) {
                    const li = e.target.closest('li');
                    if (li) pick(Number(li.dataset.index));
                });
                document.addEventListener('click', function(e) {
                    if (!wrap.contains(e.target)) close();
                });
                sel.addEventListener('change', render);
                sel.addEventListener('focus', function() { trigger.focus(); });

                function mirrorValidation() {
                    ['ring-2', 'ring-red-400', 'field-invalid'].forEach(function(cls) {
                        trigger.classList.toggle(cls, sel.classList.contains(cls));
                    });
                    ['aria-invalid', 'aria-describedby'].forEach(attribute => {
                        if (sel.hasAttribute(attribute)) trigger.setAttribute(attribute, sel.getAttribute(attribute));
                        else trigger.removeAttribute(attribute);
                    });
                    trigger.disabled = sel.disabled;
                }
                new MutationObserver(mirrorValidation).observe(sel, { attributes: true, attributeFilter: ['class', 'aria-invalid', 'aria-describedby', 'disabled'] });
                mirrorValidation();

                if (sel.id) {
                    const label = document.querySelector('label[for="' + sel.id + '"]');
                    if (label) label.addEventListener('click', function(e) { e.preventDefault(); open(); });
                }

                render();
            }

            document.querySelectorAll('select').forEach(enhance);
        })();

        // Every numeric field in this app holds money or a rate, so a negative is
        // never valid. The min attribute alone still lets Chrome type a minus and
        // only rejects it on submit, so block the character at the source.
        (function() {
            function isNumberInput(el) {
                return el && el.tagName === 'INPUT' && el.type === 'number';
            }

            document.addEventListener('keydown', function(e) {
                if (!isNumberInput(e.target)) return;
                if (e.key === '-' || e.key === 'e' || e.key === 'E') e.preventDefault();
            }, true);

            document.addEventListener('paste', function(e) {
                if (!isNumberInput(e.target)) return;
                const text = (e.clipboardData || window.clipboardData).getData('text');
                if (/[-eE]/.test(text)) e.preventDefault();
            }, true);

            document.addEventListener('input', function(e) {
                if (!isNumberInput(e.target)) return;
                if (e.target.value.includes('-')) {
                    e.target.value = e.target.value.replace(/-/g, '');
                }
            }, true);
        })();

        (function() {
            const toast = document.getElementById('flash-toast');
            if (toast) {
                setTimeout(function() { toast.classList.add('opacity-0'); }, 3000);
                setTimeout(function() { toast.remove(); }, 3500);
            }

            // An error can run to a whole sentence or two, so it stays far longer than the success
            // toast, but it still leaves on its own and can be closed by hand.
            const errorBanner = document.getElementById('flash-error');
            if (errorBanner) {
                const close = function() {
                    errorBanner.classList.add('opacity-0');
                    setTimeout(function() { errorBanner.remove(); }, 500);
                };
                window.dismissFlashError = close;
                setTimeout(close, 10000);
            }
        })();

        @auth
        // Inactivity auto-logout: 30 minutes
        (function() {
            const INACTIVE_LIMIT = 30 * 60 * 1000;
            let timer;

            function resetTimer() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    const form = document.getElementById('sidebarLogoutForm');
                    if (!form) return;
                    form.action = '{{ route('logout') }}?reason=inactive';
                    form.submit();
                }, INACTIVE_LIMIT);
            }

            ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function(evt) {
                document.addEventListener(evt, resetTimer, { passive: true });
            });

            resetTimer();
        })();
        @endauth
    </script>
</body>
</html>
