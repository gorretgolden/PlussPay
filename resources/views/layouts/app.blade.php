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
        .sidebar-sublink.active { background-color: #14532d; color: #4ade80; }
        .sidebar-sublink { padding-left: 2.5rem; }
        .sidebar-group-header { padding-left: 1.5rem; }
        .sidebar-nav { scrollbar-width: thin; scrollbar-color: #166534 transparent; }
        .sidebar-nav::-webkit-scrollbar { width: 6px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: #166534; border-radius: 3px; }
    </style>
    @stack('styles')
</head>
<body class="bg-gray-100 min-h-screen">
    @auth
    <div class="flex">
        {{-- Sidebar --}}
        <aside class="w-64 bg-green-900 text-white flex flex-col no-print fixed h-full">
            <div class="p-4 border-b border-green-800">
                <a href="{{ route('dashboard') }}" class="block">
                    <img src="{{ asset('logo.webp') }}" alt="PlusPay" width="640" height="232" class="w-full h-auto">
                </a>
            </div>
            <nav class="sidebar-nav flex-1 py-4 overflow-y-auto">
                <a href="{{ route('dashboard') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-4 0h4"/></svg>
                    Dashboard
                </a>
                <a href="{{ route('wallet.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('wallet.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                    My Wallet
                </a>
                @role('admin|superadmin|agent')
                <a href="{{ route('transactions.create') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('transactions.create') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Send Money
                </a>
                @endrole
                <a href="{{ route('transactions.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('transactions.index') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    Transactions
                </a>
                <a href="{{ route('receipts.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('receipts.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Receipts
                </a>
                @role('admin|superadmin|accountant')
                <a href="{{ route('customers.index') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('customers.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    Customers
                </a>
                @endrole

                {{-- Accounts Group --}}
                @role('admin|superadmin|accountant')
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
                        <a href="{{ route('accounts.entries.create') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('accounts.entries.create') ? 'active' : '' }}">
                            Add Ledger Entry
                        </a>
                        <a href="{{ route('accounts.daily-balances') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('accounts.daily-balances') ? 'active' : '' }}">
                            Daily Balances
                        </a>
                    </div>
                </div>
                @endrole
                <div>
                    <button type="button" onclick="toggleReportsGroup()" class="w-full flex items-center justify-between px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('reports.*') ? 'bg-green-800' : '' }}">
                        <span class="flex items-center">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                            Reports
                        </span>
                        <svg id="reportsChevron" class="w-4 h-4 transition-transform {{ request()->routeIs('reports.*') ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div id="reportsGroup" class="{{ request()->routeIs('reports.*') ? '' : 'hidden' }}">
                        <a href="{{ route('reports.index') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('reports.index') && !request()->query('type') ? 'active' : '' }}">
                            Reports Hub
                        </a>
                        <a href="{{ route('reports.index', ['type' => 'transaction']) }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->query('type') === 'transaction' ? 'active' : '' }}">
                            Transaction Report
                        </a>
                        <a href="{{ route('reports.index', ['type' => 'revenue']) }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->query('type') === 'revenue' ? 'active' : '' }}">
                            Revenue Report
                        </a>
                        @role('admin|superadmin|accountant')
                        <a href="{{ route('reports.index', ['type' => 'profit']) }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->query('type') === 'profit' ? 'active' : '' }}">
                            Profit Report
                        </a>
                        @endrole
                        <a href="{{ route('reports.index', ['type' => 'commission']) }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->query('type') === 'commission' ? 'active' : '' }}">
                            Commission Report
                        </a>
                        <a href="{{ route('reports.index', ['type' => 'agent']) }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->query('type') === 'agent' ? 'active' : '' }}">
                            Agent Report
                        </a>
                        <a href="{{ route('reports.index', ['type' => 'daily']) }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->query('type') === 'daily' ? 'active' : '' }}">
                            Daily Summary
                        </a>
                        <a href="{{ route('reports.index', ['type' => 'customer']) }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->query('type') === 'customer' ? 'active' : '' }}">
                            Customer Report
                        </a>
                        @role('admin|superadmin|accountant')
                        <a href="{{ route('reports.index', ['type' => 'rates']) }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->query('type') === 'rates' ? 'active' : '' }}">
                            Rate Report
                        </a>
                        @endrole
                    </div>
                </div>
                @role('admin|superadmin|accountant')
                <a href="{{ route('agents.performance') }}" class="sidebar-link flex items-center px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('agents.*') ? 'active' : '' }}">
                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Agent Performance
                </a>
                @endrole

                @role('admin|superadmin')
                {{-- User Management Group --}}
                <div>
                    <button type="button" onclick="toggleUserMgmtGroup()" class="w-full flex items-center justify-between px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('users.*') || request()->routeIs('admin.roles') || request()->routeIs('admin.permissions') || request()->routeIs('admin.activity-logs') || request()->routeIs('admin.login-logs') || request()->routeIs('admin.system-logs') ? 'bg-green-800' : '' }}">
                        <span class="flex items-center">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            User Management
                        </span>
                        <svg id="userMgmtChevron" class="w-4 h-4 transition-transform {{ request()->routeIs('users.*') || request()->routeIs('admin.roles') || request()->routeIs('admin.permissions') || request()->routeIs('admin.activity-logs') || request()->routeIs('admin.login-logs') || request()->routeIs('admin.system-logs') ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div id="userMgmtGroup" class="{{ request()->routeIs('users.*') || request()->routeIs('admin.roles') || request()->routeIs('admin.permissions') || request()->routeIs('admin.activity-logs') || request()->routeIs('admin.login-logs') || request()->routeIs('admin.system-logs') ? '' : 'hidden' }}">
                        <a href="{{ route('users.index') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('users.*') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Users
                        </a>
                        <a href="{{ route('admin.roles') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('admin.roles') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Roles & Permissions
                        </a>
                        <a href="{{ route('admin.permissions') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('admin.permissions') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.53 5.81L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.93-5.93A6 6 0 1121 9z"/></svg>
                            All Permissions
                        </a>
                        <a href="{{ route('admin.activity-logs') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('admin.activity-logs') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                            Activity Logs
                        </a>
                        <a href="{{ route('admin.login-logs') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('admin.login-logs') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                            Login Logs
                        </a>
                        <a href="{{ route('admin.system-logs') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('admin.system-logs') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                            System Logs
                        </a>
                    </div>
                </div>

                {{-- Settings Group --}}
                <div>
                    <button type="button" onclick="toggleSettingsGroup()" class="w-full flex items-center justify-between px-6 py-3 text-sm hover:bg-green-800 {{ request()->routeIs('settings.*') || request()->routeIs('exchange-rates.*') ? 'bg-green-800' : '' }}">
                        <span class="flex items-center">
                            <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            Settings
                        </span>
                        <svg id="settingsChevron" class="w-4 h-4 transition-transform {{ request()->routeIs('settings.*') || request()->routeIs('exchange-rates.*') ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div id="settingsGroup" class="{{ request()->routeIs('settings.*') || request()->routeIs('exchange-rates.*') ? '' : 'hidden' }}">
                        <a href="{{ route('settings.index') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('settings.*') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
                            System Settings
                        </a>
                        <a href="{{ route('exchange-rates.index') }}" class="sidebar-sublink flex items-center py-2 text-sm hover:bg-green-800 {{ request()->routeIs('exchange-rates.*') ? 'active' : '' }}">
                            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                            Exchange Rates
                        </a>
                    </div>
                </div>
                @endrole
            </nav>
            <div class="p-4 border-t border-green-800">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-green-700 flex items-center justify-center text-sm font-bold uppercase flex-shrink-0">
                        {{ mb_substr(auth()->user()->name, 0, 1) }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-medium truncate">{{ auth()->user()->name }}</p>
                        <p class="text-xs text-green-300">{{ \App\Http\Controllers\RolePermissionController::displayName(auth()->user()->getRoleNames()->first() ?? '') }}</p>
                    </div>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="mt-3">
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
        <div class="ml-64 w-[calc(100%-16rem)]">
            <main class="p-6">
                @if(session('success'))
                    <div id="flash-toast" class="fixed top-4 right-4 z-50 bg-green-600 text-white px-5 py-3 rounded-lg shadow-lg text-sm font-medium flex items-center gap-2 transition-opacity duration-500 no-print">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
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
        function toggleSettingsGroup() {
            const group = document.getElementById('settingsGroup');
            const chevron = document.getElementById('settingsChevron');
            group.classList.toggle('hidden');
            chevron.classList.toggle('rotate-180');
        }

        function toggleMenu(key) {
            const target = document.getElementById('menu-' + key);
            document.querySelectorAll('[data-row-menu]').forEach(function(menu) {
                if (menu !== target) menu.classList.add('hidden');
            });
            target.classList.toggle('hidden');
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('[data-row-menu-host]')) {
                document.querySelectorAll('[data-row-menu]').forEach(function(menu) {
                    menu.classList.add('hidden');
                });
            }
        });

        // Searchable dropdowns. The native select stays in the DOM so form
        // submit, required validation and error styling still work.
        (function() {
            const CARET = '<svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>';

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

                const panel = document.createElement('div');
                panel.className = 'absolute left-0 top-full z-40 mt-1 w-full min-w-[14rem] bg-white rounded-lg shadow-xl border border-gray-200 hidden text-left';

                const search = document.createElement('input');
                search.type = 'text';
                search.placeholder = 'Search...';
                search.className = 'w-full px-3 py-2 border-b border-gray-100 rounded-t-lg text-sm outline-none focus:bg-green-50';
                if (sel.options.length <= 2) search.classList.add('hidden');

                const list = document.createElement('ul');
                list.className = 'max-h-56 overflow-y-auto py-1';

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

                const labelSpan = trigger.querySelector('.ss-label');
                const items = Array.from(sel.options).map(function(opt) {
                    const li = document.createElement('li');
                    li.className = 'px-3 py-2 text-sm hover:bg-green-50 cursor-pointer truncate';
                    li.textContent = opt.textContent.trim();
                    li.dataset.index = opt.index;
                    li.dataset.search = (opt.textContent + ' ' + opt.value).toLowerCase();
                    if (opt.disabled) li.classList.add('opacity-40', 'cursor-not-allowed');
                    list.appendChild(li);
                    return li;
                });

                function render() {
                    const opt = sel.options[sel.selectedIndex];
                    const text = opt ? opt.textContent.trim() : '';
                    labelSpan.textContent = text;
                    labelSpan.classList.toggle('text-gray-400', !opt || opt.value === '');
                    items.forEach(function(li) {
                        li.classList.toggle('bg-green-50', Number(li.dataset.index) === sel.selectedIndex);
                    });
                }

                const isOpen = () => !panel.classList.contains('hidden');
                const visible = () => items.filter(li => !li.classList.contains('hidden'));

                function filter() {
                    const q = search.value.toLowerCase().trim();
                    items.forEach(li => li.classList.toggle('hidden', q !== '' && !li.dataset.search.includes(q)));
                }

                let active = -1;
                function highlight(i) {
                    const vis = visible();
                    items.forEach(li => li.classList.remove('bg-gray-100'));
                    active = i;
                    if (vis[i]) {
                        vis[i].classList.add('bg-gray-100');
                        vis[i].scrollIntoView({ block: 'nearest' });
                    }
                }

                function open() {
                    panel.classList.remove('hidden');
                    search.value = '';
                    filter();
                    highlight(-1);
                    if (!search.classList.contains('hidden')) search.focus();
                }

                function close() {
                    panel.classList.add('hidden');
                }

                function pick(index) {
                    const opt = sel.options[index];
                    if (!opt || opt.disabled) return;
                    sel.value = opt.value;
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                    render();
                    close();
                    trigger.focus();
                }

                trigger.addEventListener('click', () => isOpen() ? close() : open());
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

                // Mirror the wizard's invalid-field flash onto the visible control.
                new MutationObserver(function() {
                    ['ring-2', 'ring-red-400'].forEach(function(cls) {
                        trigger.classList.toggle(cls, sel.classList.contains(cls));
                    });
                }).observe(sel, { attributes: true, attributeFilter: ['class'] });

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
        })();

        @auth
        // Inactivity auto-logout: 30 minutes
        (function() {
            const INACTIVE_LIMIT = 30 * 60 * 1000;
            let timer;

            function resetTimer() {
                clearTimeout(timer);
                timer = setTimeout(function() {
                    const form = document.querySelector('form[action="{{ route('logout') }}"]');
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
