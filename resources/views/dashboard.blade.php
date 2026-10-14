@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
@php
    $cur = $usdToUgx ? 'UGX' : 'USD';
    // Stored UGX, not a fresh conversion, so these match the Reports page.
    $stored = fn($ugx, $usd) => $usdToUgx ? 'UGX ' . number_format($ugx) : '$' . number_format($usd, 2);
@endphp
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
    <p class="text-gray-600">Welcome, {{ auth()->user()->name }}</p>
    <div class="flex items-center gap-4 mt-2">
        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 capitalize">{{ $role }}</span>
        <span class="text-sm text-gray-500" id="liveClock">{{ now()->format('l, F d, Y') }} · {{ now()->format('h:i:s A') }}</span>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    @foreach($wallets as $currency => $account)
    <a href="{{ route('wallet.index') }}" class="bg-white rounded-lg shadow p-5 hover:shadow-lg transition-shadow block">
        <div class="text-sm text-gray-500 uppercase tracking-wide">{{ $currency }} Wallet</div>
        <div class="text-2xl font-bold text-gray-900 mt-1">
            @if($currency === 'UGX')
                UGX {{ number_format((float) $account->balance(), 0) }}
            @elseif($currency === 'RMB')
                &yen;{{ number_format((float) $account->balance(), 2) }}
            @else
                ${{ number_format((float) $account->balance(), 2) }}
            @endif
        </div>
        @if($currency === 'UGX')
        <div class="text-xs text-gray-400 mt-1">This month: UGX {{ number_format($commissionMonth) }}</div>
        @endif
    </a>
    @endforeach
</div>

<div class="bg-green-800 rounded-lg shadow p-6 mb-6">
    <h2 class="text-white text-lg font-semibold mb-4">Today's Summary</h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
        <div class="bg-white/10 rounded-lg p-4">
            <div class="text-green-200 text-sm">Completed Today</div>
            <div class="text-white text-3xl font-bold mt-1">{{ number_format($stats['today_transactions']) }}</div>
        </div>
        <div class="bg-white/10 rounded-lg p-4">
            <div class="text-green-200 text-sm">Money Sent ({{ $cur }})</div>
            <div class="text-white text-3xl font-bold mt-1">{{ $stored($stats['today_ugx_sent'], $stats['today_usd_sent']) }}</div>
        </div>
        <div class="bg-white/10 rounded-lg p-4">
            <div class="text-green-200 text-sm">Service Fees Today</div>
            <div class="text-white text-3xl font-bold mt-1">{{ $stored($stats['today_revenue_ugx'], $stats['today_revenue']) }}</div>
        </div>
    </div>
    @role('admin|superadmin')
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white/10 rounded-lg p-4">
            <div class="text-green-200 text-sm">Service Fees</div>
            <div class="text-white text-2xl font-bold mt-1">UGX {{ number_format($profit['today']['fees']) }}</div>
        </div>
        <div class="bg-white/10 rounded-lg p-4">
            <div class="text-green-200 text-sm">Exchange Profit</div>
            <div class="text-white text-2xl font-bold mt-1 {{ $profit['today']['exchange'] < 0 ? 'text-red-300' : '' }}">UGX {{ number_format($profit['today']['exchange']) }}</div>
        </div>
        <div class="bg-white/10 rounded-lg p-4">
            <div class="text-green-200 text-sm">Total Profit</div>
            <div class="text-white text-2xl font-bold mt-1 {{ $profit['today']['total'] < 0 ? 'text-red-300' : '' }}">UGX {{ number_format($profit['today']['total']) }}</div>
        </div>
    </div>
    @endrole
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
    <a href="{{ route('transactions.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Total Transactions</div>
                <div class="text-3xl font-bold text-green-800 mt-1">{{ number_format($stats['total_transactions']) }}</div>
            </div>
            <svg class="w-10 h-10 text-green-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        </div>
    </a>
    <a href="{{ route('transactions.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Money Sent ({{ $cur }})</div>
                <div class="text-3xl font-bold text-green-700 mt-1">{{ $stored($stats['total_ugx_sent'], $stats['total_usd_sent']) }}</div>
            </div>
            <svg class="w-10 h-10 text-green-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('transactions.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Total RMB Sent</div>
                <div class="text-3xl font-bold text-purple-700 mt-1">¥{{ number_format($stats['total_rmb_sent'], 2) }}</div>
            </div>
            <svg class="w-10 h-10 text-purple-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 8h6m-5 0a3 3 0 110 6H9l3 3m-3-6h6m6 1a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('reports.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Service Fees (All Time)</div>
                <div class="text-3xl font-bold text-green-700 mt-1">{{ $stored($stats['total_revenue_ugx'], $stats['total_revenue']) }}</div>
            </div>
            <svg class="w-10 h-10 text-green-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
        </div>
    </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <a href="{{ route('transactions.index', ['status' => 'pending']) }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Still Pending</div>
                <div class="text-3xl font-bold text-yellow-600 mt-1">{{ number_format($stats['pending_transactions']) }}</div>
            </div>
            <svg class="w-10 h-10 text-yellow-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('transactions.index', ['status' => 'completed']) }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Completed</div>
                <div class="text-3xl font-bold text-green-700 mt-1">{{ number_format($stats['completed_transactions']) }}</div>
            </div>
            <svg class="w-10 h-10 text-green-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('transactions.index', ['status' => 'cancelled']) }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Cancelled</div>
                <div class="text-3xl font-bold text-red-600 mt-1">{{ number_format($stats['cancelled_transactions']) }}</div>
            </div>
            <svg class="w-10 h-10 text-red-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('transactions.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Avg Transaction</div>
                <div class="text-3xl font-bold text-blue-700 mt-1">{{ $stored($stats['avg_ugx_sent'], $stats['avg_transaction']) }}</div>
            </div>
            <svg class="w-10 h-10 text-blue-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        </div>
    </a>
</div>

@role('admin|superadmin')
@php
    $shilling = fn ($value) => $value < 0
        ? 'Loss UGX '.number_format(abs($value), 2)
        : 'UGX '.number_format($value, 2);
    $shillingTone = fn ($value) => $value < 0 ? 'text-red-600' : 'text-gray-900';
@endphp
<div class="bg-white rounded-lg shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-1">Profit</h2>
    <p class="text-sm text-gray-500 mb-4">The fee the till keeps, plus what the two currency legs came to on the rates each transfer was booked at.</p>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach(['today' => 'Today', 'month' => 'This Month', 'all' => 'All Time'] as $window => $label)
        <div class="border border-gray-200 rounded-lg p-4">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $label }}</div>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Service Fees</dt>
                    <dd class="{{ $shillingTone($profit[$window]['fees']) }} font-medium">{{ $shilling($profit[$window]['fees']) }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500">Exchange Profit</dt>
                    <dd class="{{ $shillingTone($profit[$window]['exchange']) }} font-medium">{{ $shilling($profit[$window]['exchange']) }}</dd>
                </div>
                <div class="flex justify-between gap-3 border-t pt-2">
                    <dt class="text-gray-500">Total Profit</dt>
                    <dd class="{{ $shillingTone($profit[$window]['total']) }} font-bold">{{ $shilling($profit[$window]['total']) }}</dd>
                </div>
            </dl>
            @if($profit[$window]['unpriced'] > 0)
            @php $count = $profit[$window]['unpriced']; @endphp
            <p class="text-xs text-gray-400 mt-3">{{ number_format($count) }} completed {{ Str::plural('transfer', $count) }} {{ $count === 1 ? 'has' : 'have' }} no rates stored, so {{ $count === 1 ? 'it adds' : 'they add' }} nothing to Exchange Profit.</p>
            @endif
        </div>
        @endforeach
    </div>
</div>
@endrole

@role('admin|superadmin')
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
    <a href="{{ route('users.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Active Users</div>
                <div class="text-3xl font-bold text-indigo-700 mt-1">{{ number_format($stats['total_users']) }}</div>
            </div>
            <svg class="w-10 h-10 text-indigo-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </div>
    </a>
    <a href="{{ route('exchange-rates.index') }}" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow cursor-pointer block">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-sm text-gray-500">Active Exchange Rates</div>
                <div class="text-3xl font-bold text-teal-700 mt-1">{{ number_format($stats['active_rates']) }}</div>
            </div>
            <svg class="w-10 h-10 text-teal-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
        </div>
    </a>
</div>
@endrole

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Completed Transfers (Last 7 Days)</h2>
        <div style="position:relative; height:250px;">
            <canvas id="transactionsChart"></canvas>
        </div>
    </div>
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Service Fees (Last 7 Days)</h2>
        <div style="position:relative; height:250px;">
            <canvas id="revenueChart"></canvas>
        </div>
    </div>
</div>

@role('admin|superadmin')
<div class="bg-white rounded-lg shadow p-6 mb-8">
    <h2 class="text-lg font-semibold text-gray-900 mb-1">Profit (Last 7 Days)</h2>
    <p class="text-sm text-gray-500 mb-4">Stacked in shillings. The yuan leg sits below the line on the days it cost money.</p>
    <div style="position:relative; height:280px;">
        <canvas id="profitChart"></canvas>
    </div>
</div>
@endrole

<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
        <h2 class="text-lg font-semibold text-gray-900">Recent Transactions</h2>
        @role('admin|agent')
        <a href="{{ route('transactions.create') }}" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">New Transaction</a>
        @endrole
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receipt #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Sender</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Receiver</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">RMB</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($recentTransactions as $t)
                <tr>
                    <td class="px-6 py-4 text-sm font-medium text-green-800">
                        <a href="{{ route('receipts.show', $t->receipt_number) }}">{{ $t->receipt_number }}</a>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $t->sender_name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $t->receiver_name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">{{ $t->currency }} {{ number_format($t->amount, 2) }}</td>
                    <td class="px-6 py-4 text-sm text-gray-900">¥{{ number_format($t->rmb_amount, 2) }}</td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 text-xs rounded-full
                            @if($t->status === 'completed') bg-green-100 text-green-800
                            @elseif($t->status === 'pending') bg-yellow-100 text-yellow-800
                            @else bg-red-100 text-red-800 @endif">
                            {{ ucfirst($t->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $t->created_at->format('Y-m-d h:i A') }}</td>
                    <td class="px-6 py-4 text-sm">
                        <a href="{{ route('receipts.print', $t->receipt_number) }}" target="_blank" class="text-green-600 hover:text-green-800 mr-2">Print</a>
                        <a href="{{ route('receipts.show', $t->receipt_number) }}" class="text-green-600 hover:text-green-800">View</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-6 py-8 text-center text-gray-500">No transactions yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const chartData = @json($chartData);

    new Chart(document.getElementById('transactionsChart'), {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'Completed',
                data: chartData.transactions,
                borderColor: 'rgb(22, 163, 74)',
                backgroundColor: 'rgba(22, 163, 74, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });

    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'Service Fees ({{ $cur }})',
                data: chartData.revenue,
                backgroundColor: 'rgba(22, 163, 74, 0.8)',
                borderColor: 'rgb(22, 163, 74)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });

    @role('admin|superadmin')
    if (document.getElementById('profitChart')) {
        new Chart(document.getElementById('profitChart'), {
            type: 'bar',
            data: {
                labels: chartData.labels,
                datasets: [
                    {
                        label: 'Service Fees',
                        data: chartData.fees,
                        backgroundColor: 'rgba(22, 163, 74, 0.8)'
                    },
                    {
                        label: 'UGX to USD',
                        data: chartData.margin_ugx,
                        backgroundColor: 'rgba(37, 99, 235, 0.8)'
                    },
                    {
                        label: 'USD to RMB',
                        data: chartData.yuan_ugx,
                        backgroundColor: 'rgba(217, 119, 6, 0.8)'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (item) {
                                return item.dataset.label + ': UGX ' + item.parsed.y.toLocaleString('en', {maximumFractionDigits: 2});
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true }
                }
            }
        });
    }
    @endrole

    function updateClock() {
        const now = new Date();
        const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        const day = days[now.getDay()];
        const month = months[now.getMonth()];
        const date = now.getDate();
        const year = now.getFullYear();
        let h = now.getHours();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        const m = String(now.getMinutes()).padStart(2, '0');
        const s = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('liveClock').textContent = `${day}, ${month} ${String(date).padStart(2, '0')}, ${year} · ${String(h).padStart(2, '0')}:${m}:${s} ${ampm}`;
    }
    setInterval(updateClock, 1000);
</script>
@endsection
