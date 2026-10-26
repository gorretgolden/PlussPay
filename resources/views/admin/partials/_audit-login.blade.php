@php
    $suspiciousIps = $suspicious['ips'] ?? collect();
    $suspiciousEmails = $suspicious['emails'] ?? collect();
@endphp

@if($suspiciousIps->isNotEmpty() || $suspiciousEmails->isNotEmpty())
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
    <p class="text-sm font-semibold text-red-800 mb-2">Possible brute-force activity in the last 15 minutes</p>
    <div class="flex flex-wrap gap-2">
        @foreach($suspiciousIps as $ip => $count)
        <a href="{{ route('admin.audit-logs', ['tab' => 'login', 'ip_address' => $ip, 'status' => 'failed']) }}"
            class="bg-red-100 text-red-800 px-2 py-1 rounded text-xs hover:bg-red-200">IP {{ $ip }} - {{ $count }} failed</a>
        @endforeach
        @foreach($suspiciousEmails as $email => $count)
        <span class="bg-red-100 text-red-800 px-2 py-1 rounded text-xs">{{ $email }} - {{ $count }} failed</span>
        @endforeach
    </div>
</div>
@endif

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('admin.audit-logs', ['tab' => 'login']) }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm text-gray-600 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Name, email, IP, browser..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Status</label>
            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Statuses</option>
                @foreach(\App\Models\LoginLog::STATUSES as $value => $label)
                <option value="{{ $value }}" {{ request('status') === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">User</label>
            <select name="user_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All Users</option>
                @foreach($users as $user)
                <option value="{{ $user->id }}" {{ request('user_id') == $user->id ? 'selected' : '' }}>{{ $user->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">From Date</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">To Date</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}"
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        @if(request('ip_address'))
        <input type="hidden" name="ip_address" value="{{ request('ip_address') }}">
        @endif
        <div class="md:col-span-5 flex gap-2">
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
            <a href="{{ route('admin.audit-logs', ['tab' => 'login']) }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Reset</a>
            @if(request('ip_address'))
            <a href="{{ route('admin.audit-logs', request()->except('ip_address')) }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Clear IP filter ({{ request('ip_address') }})</a>
            @endif
        </div>
    </form>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email Attempted</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP Address</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Device</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Details</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($logs as $log)
                <tr>
                    <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $log->created_at->format('Y-m-d h:i A') }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $log->user?->name ?? ($log->email_attempted ? 'No such account' : 'Unknown') }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600">{{ $log->email_attempted ?? '-' }}</td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 text-xs rounded-full {{ ['success' => 'bg-green-100 text-green-800', 'failed' => 'bg-amber-100 text-amber-800', 'locked' => 'bg-red-100 text-red-800', 'logout' => 'bg-gray-100 text-gray-700'][$log->status] ?? 'bg-gray-100 text-gray-700' }}">
                            {{ $log->statusLabel() }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm font-mono text-gray-900">{{ $log->ip_address ?? 'not recorded' }}</td>
                    <td class="px-6 py-4 text-sm text-gray-600">{{ $log->device ?? '-' }}{{ $log->browser ? ' / '.$log->browser : '' }}</td>
                    <td class="px-6 py-4 text-sm text-right">
                        <button type="button" class="text-green-700 hover:text-green-900 hover:underline"
                            data-login-details
                            data-user="{{ $log->user?->name ?? 'No account' }}"
                            data-email="{{ $log->email_attempted ?? '-' }}"
                            data-status="{{ $log->statusLabel() }}"
                            data-ip="{{ $log->ip_address ?? 'not recorded' }}"
                            data-device="{{ $log->device ?? '-' }}"
                            data-browser="{{ $log->browser ?? '-' }}"
                            data-when="{{ $log->created_at->format('M d, Y h:i A') }}"
                            data-agent="{{ $log->user_agent ?? 'Not recorded' }}">View</button>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-6 py-8 text-center text-gray-500">No login logs found.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())
    <div class="px-6 py-4 border-t border-gray-200">
        {{ $logs->links() }}
    </div>
    @endif
</div>

{{-- Login Details Modal --}}
<div id="loginDetailModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black bg-opacity-50 login-detail-close"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4 pointer-events-none">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg pointer-events-auto">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">Sign-in Details</h3>
                <button type="button" class="login-detail-close text-gray-400 hover:text-gray-700 text-2xl leading-none">&times;</button>
            </div>
            <div id="loginDetailBody" class="px-6 py-5 text-sm"></div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
                <button type="button" class="login-detail-close px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Close</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    const loginDetailModal = document.getElementById('loginDetailModal');
    const loginDetailBody = document.getElementById('loginDetailBody');
    const esc = (s) => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

    const closeLoginDetail = () => loginDetailModal.classList.add('hidden');

    document.querySelectorAll('[data-login-details]').forEach((button) => {
        button.addEventListener('click', () => {
            const d = button.dataset;
            const rows = [
                ['User', d.user], ['Email attempted', d.email], ['Status', d.status],
                ['When', d.when], ['IP address', d.ip], ['Device', d.device], ['Browser', d.browser],
            ];
            loginDetailBody.innerHTML =
                '<dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3">' +
                rows.map(([k, v]) => `<div><dt class="text-xs uppercase tracking-wide text-gray-500">${esc(k)}</dt><dd class="text-gray-900 break-words">${esc(v)}</dd></div>`).join('') +
                '</dl><div class="mt-4"><span class="block text-xs uppercase tracking-wide text-gray-500 mb-1">User agent</span><p class="text-gray-700 break-all"></p></div>';
            loginDetailBody.querySelector('p').textContent = d.agent;
            loginDetailModal.classList.remove('hidden');
        });
    });

    document.querySelectorAll('.login-detail-close').forEach((el) => el.addEventListener('click', closeLoginDetail));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeLoginDetail(); });
</script>
@endpush
