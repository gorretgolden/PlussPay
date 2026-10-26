@extends('layouts.app')

@section('title', 'System Errors')

@section('content')
@php
    $badge = [
        'critical' => 'bg-red-800 text-white',
        'error' => 'bg-red-100 text-red-800',
        'warning' => 'bg-yellow-100 text-yellow-800',
    ];
    $shortName = fn ($path) => $path ? basename(str_replace('\\', '/', $path)) : '';
@endphp

<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">System Errors</h1>
        <p class="text-gray-600">Unhandled exceptions and failed jobs, newest first</p>
    </div>
    <div class="flex gap-2">
        <a href="{{ route('admin.system-logs.print', request()->query()) }}" target="_blank"
            class="bg-gray-700 text-white px-4 py-2 rounded-md text-sm hover:bg-gray-600">Export PDF</a>
        <a href="{{ route('export.system-logs', request()->query()) }}"
            class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
    </div>
</div>

@include('admin.partials._logs-tabs')

<div class="flex flex-wrap gap-2 mb-6">
    <span class="text-xs text-gray-500 uppercase self-center mr-1">Jump to</span>
    <a href="{{ route('admin.system-logs', request()->except(['level', 'resolved', 'page'])) }}"
        class="px-3 py-1 rounded-full text-xs {{ request('level') || request()->has('resolved') ? 'bg-gray-100 text-gray-700' : 'bg-green-800 text-white' }}">All</a>
    <a href="{{ route('admin.system-logs', ['resolved' => '0']) }}"
        class="px-3 py-1 rounded-full text-xs {{ request('resolved') === '0' ? 'bg-green-800 text-white' : 'bg-red-100 text-red-800' }}">
        Unresolved {{ number_format($counts['unresolved']) }}
    </a>
    @foreach(['critical', 'error', 'warning'] as $level)
    <a href="{{ route('admin.system-logs', ['level' => $level]) }}"
        class="px-3 py-1 rounded-full text-xs {{ $badge[$level] }} {{ request('level') === $level ? 'ring-2 ring-offset-1 ring-gray-900' : '' }} {{ $counts[$level] === 0 ? 'opacity-40' : '' }}">
        {{ ucfirst($level) }} {{ number_format($counts[$level]) }}
    </a>
    @endforeach
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('admin.system-logs') }}" class="grid grid-cols-1 md:grid-cols-5 gap-4">
        <div>
            <label class="block text-sm text-gray-600 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Message, class, file, URL..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Level</label>
            <select name="level" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All levels</option>
                @foreach(\App\Models\SystemLog::LEVELS as $level)
                <option value="{{ $level }}" {{ request('level') === $level ? 'selected' : '' }}>{{ ucfirst($level) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">Status</label>
            <select name="resolved" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Any</option>
                <option value="0" {{ request('resolved') === '0' ? 'selected' : '' }}>Unresolved</option>
                <option value="1" {{ request('resolved') === '1' ? 'selected' : '' }}>Resolved</option>
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
        <div class="md:col-span-5 flex gap-2">
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
            <a href="{{ route('admin.system-logs') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Reset</a>
        </div>
    </form>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Level</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Message</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Where</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Details</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($logs as $log)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap align-top">{{ $log->created_at->format('Y-m-d h:i A') }}</td>
                    <td class="px-4 py-3 align-top">
                        <span class="px-2 py-1 text-xs rounded-full {{ $badge[$log->level] ?? 'bg-gray-100 text-gray-700' }}">{{ strtoupper($log->level) }}</span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900 align-top">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $log->message), 200) }}</td>
                    <td class="px-4 py-3 text-sm text-gray-600 align-top whitespace-nowrap" title="{{ $log->file }}">{{ $shortName((string) $log->file) ?: '-' }}</td>
                    <td class="px-4 py-3 align-top">
                        @if($log->resolved)
                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">Resolved</span>
                        @else
                        <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-800">Open</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right align-top">
                        <button type="button" data-log-toggle="sys-{{ $log->id }}"
                            class="text-green-700 hover:text-green-900 text-sm font-medium">Show</button>
                    </td>
                </tr>
                <tr id="sys-{{ $log->id }}" class="hidden bg-gray-50">
                    <td colspan="6" class="px-4 py-4">
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                            <div class="lg:col-span-1 text-sm space-y-2">
                                <div><span class="block text-xs uppercase text-gray-500">Exception</span><p class="text-gray-900 break-all">{{ $log->exception_class ?: '-' }}</p></div>
                                <div><span class="block text-xs uppercase text-gray-500">Message</span><p class="text-gray-900 break-words">{{ $log->message }}</p></div>
                                @if($log->file)
                                <div><span class="block text-xs uppercase text-gray-500">Thrown at</span><p class="text-gray-700 break-all">{{ $log->file }}:{{ $log->line }}</p></div>
                                @endif
                                @if($log->url)
                                <div><span class="block text-xs uppercase text-gray-500">URL</span><p class="text-gray-700 break-all">{{ $log->method }} {{ $log->url }}</p></div>
                                @endif
                                <div><span class="block text-xs uppercase text-gray-500">User / IP</span><p class="text-gray-700">{{ $log->user_id ? 'user #'.$log->user_id : 'guest' }} {{ $log->ip_address ? '· '.$log->ip_address : '' }}</p></div>
                                @if($log->resolved)
                                <div><span class="block text-xs uppercase text-gray-500">Resolved by</span><p class="text-gray-700">{{ $log->resolver?->name ?? 'Unknown' }} on {{ $log->resolved_at?->format('M d, Y h:i A') }}</p></div>
                                @endif
                                @if($log->note)
                                <div><span class="block text-xs uppercase text-gray-500">Note</span><p class="text-gray-900 whitespace-pre-wrap">{{ $log->note }}</p></div>
                                @endif
                            </div>
                            <div class="lg:col-span-2">
                                <span class="block text-xs uppercase text-gray-500 mb-1">Stack trace</span>
                                <pre class="bg-white border border-gray-200 rounded-md p-3 text-xs text-gray-700 overflow-auto max-h-80 whitespace-pre-wrap break-all">{{ $log->trace ?: 'No stack trace was captured.' }}</pre>

                                @if(! $log->resolved)
                                <form method="POST" action="{{ route('admin.system-logs.resolve', $log) }}" class="mt-3">
                                    @csrf
                                    @method('PATCH')
                                    <label class="block text-xs uppercase text-gray-500 mb-1">Resolution note (optional)</label>
                                    <textarea name="note" rows="2" maxlength="2000"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm mb-2"
                                        placeholder="What you did about it, or why it can be ignored."></textarea>
                                    <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Mark resolved</button>
                                </form>
                                @endif
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-6 py-8 text-center text-gray-500">
                        {{ request()->hasAny(['search', 'level', 'resolved']) ? 'Nothing matches these filters.' : 'No errors have been recorded. That is a good sign.' }}
                    </td>
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

{{-- Secondary source: the raw log file, in case something reached disk but not the table. --}}
<div class="mt-8 bg-white rounded-lg shadow">
    <button type="button" data-log-toggle="rawFilePanel" class="w-full flex justify-between items-center px-6 py-4 text-left">
        <div>
            <h2 class="text-sm font-semibold text-gray-900">Raw log file (secondary source)</h2>
            <p class="text-xs text-gray-500">{{ $meta['file'] ?? 'no log file found' }} &middot; {{ number_format($meta['size'] / 1000000, 2) }} MB &middot; showing the latest {{ count($fileEntries) }} of {{ number_format($meta['read'] / 1000000, 2) }} MB read</p>
        </div>
        <span class="text-green-700 text-sm font-medium">Show</span>
    </button>
    <div id="rawFilePanel" class="hidden border-t border-gray-200 px-6 py-4">
        @forelse($fileEntries as $entry)
        <div class="py-2 border-b border-gray-100 last:border-0">
            <div class="flex items-center gap-2 mb-1">
                <span class="text-xs text-gray-500">{{ $entry['moment']->format('Y-m-d h:i A') }}</span>
                <span class="px-2 py-0.5 text-xs rounded-full {{ $badge[strtolower($entry['level'])] ?? 'bg-gray-100 text-gray-700' }}">{{ $entry['level'] }}</span>
            </div>
            <p class="text-sm text-gray-900 break-words">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $entry['message']), 300) }}</p>
        </div>
        @empty
        <p class="text-sm text-gray-500">No readable entries in the file window.</p>
        @endforelse
        <p class="text-xs text-gray-500 mt-3">This panel only reads the file. It never changes or deletes anything.</p>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-log-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const row = document.getElementById(button.dataset.logToggle);
            if (!row) return;
            const open = row.classList.toggle('hidden');
            const label = button.matches('button[data-log-toggle^="sys-"]') ? button : button.querySelector('span') || button;
            if (label.textContent.trim() === 'Show') { label.textContent = 'Hide'; }
            else if (label.textContent.trim() === 'Hide') { label.textContent = 'Show'; }
        });
    });
</script>
@endpush
