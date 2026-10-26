@php
    $onAudit = request()->routeIs('admin.audit-logs');
    $activeTab = request('tab') === 'login' ? 'login' : 'activity';
    $tabClass = fn (string $tab) => $onAudit && $activeTab === $tab
        ? 'border-green-800 text-green-800 font-semibold'
        : 'border-transparent text-gray-500 hover:text-gray-700';
@endphp

<div class="border-b border-gray-200 mb-6">
    <nav class="flex gap-6 -mb-px text-sm">
        @can('view-activity-logs')
        <a href="{{ route('admin.audit-logs', ['tab' => 'activity']) }}" class="pb-2 border-b-2 {{ $tabClass('activity') }}">
            Activity
        </a>
        @endcan
        @can('view-login-logs')
        <a href="{{ route('admin.audit-logs', ['tab' => 'login']) }}" class="pb-2 border-b-2 {{ $tabClass('login') }}">
            Sign-ins
        </a>
        @endcan
        @can('view-system-logs')
        <a href="{{ route('admin.system-logs') }}" class="pb-2 border-b-2 {{ request()->routeIs('admin.system-logs') ? 'border-green-800 text-green-800 font-semibold' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
            System Errors
        </a>
        @endcan
    </nav>
</div>
