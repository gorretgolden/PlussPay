@php
    $tabActive = fn (string $route) => request()->routeIs($route)
        ? 'border-green-800 text-green-800 font-semibold'
        : 'border-transparent text-gray-500 hover:text-gray-700';
@endphp

<div class="border-b border-gray-200 mb-6">
    <nav class="flex gap-6 -mb-px text-sm">
        <a href="{{ route('admin.activity-logs') }}" class="pb-2 border-b-2 {{ $tabActive('admin.activity-logs') }}">
            Audit Logs
        </a>
        <a href="{{ route('admin.login-logs') }}" class="pb-2 border-b-2 {{ $tabActive('admin.login-logs') }}">
            Login Logs
        </a>
        @role('superadmin')
        <a href="{{ route('admin.system-logs') }}" class="pb-2 border-b-2 {{ $tabActive('admin.system-logs') }}">
            System Errors
        </a>
        @endrole
    </nav>
</div>
