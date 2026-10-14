@extends('layouts.app')

@section('title', 'System Logs')

@section('content')
@php
    $badge = [
        'EMERGENCY' => 'bg-red-800 text-white',
        'ALERT' => 'bg-red-800 text-white',
        'CRITICAL' => 'bg-red-700 text-white',
        'ERROR' => 'bg-red-100 text-red-800',
        'WARNING' => 'bg-yellow-100 text-yellow-800',
        'NOTICE' => 'bg-blue-100 text-blue-800',
        'INFO' => 'bg-gray-100 text-gray-700',
        'DEBUG' => 'bg-gray-100 text-gray-600',
    ];
    $shortName = fn ($path) => $path === '' ? '' : basename(str_replace('\\', '/', $path));
@endphp

<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">System Logs</h1>
        <p class="text-gray-600">Errors and warnings the application wrote itself, newest first</p>
    </div>
    <a href="{{ route('export.system-logs', request()->query()) }}"
        class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
</div>

@if($meta['file'])
<div class="bg-white rounded-lg shadow px-4 py-3 mb-6 text-sm text-gray-600 flex flex-wrap gap-x-6 gap-y-1">
    <span>File: <span class="font-medium text-gray-900">{{ $meta['file'] }}</span></span>
    <span>Size: <span class="font-medium text-gray-900">{{ number_format($meta['size'] / 1000000, 2) }} MB</span></span>
    <span>Read: <span class="font-medium text-gray-900">{{ number_format($meta['read'] / 1000000, 2) }} MB</span></span>
    <span>Entries read: <span class="font-medium text-gray-900">{{ number_format($totalRead) }}</span></span>
    <span>Matching: <span class="font-medium text-gray-900">{{ number_format($logs->total()) }}</span></span>
    @if($meta['truncated'])
    <span class="text-amber-700">Only the tail of the file is read. Widen the window below for older entries.</span>
    @endif
</div>
@else
<div class="bg-white rounded-lg shadow p-8 text-center text-gray-500 mb-6">
    There is no log file in the folder yet. Nothing has been written, which is a good sign.
</div>
@endif

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <div class="flex flex-wrap gap-2 items-center">
        <span class="text-xs text-gray-500 uppercase mr-1">Jump to</span>
        <a href="{{ route('admin.system-logs', array_merge(request()->except(['level', 'page']), ['file' => request('file')])) }}"
            class="px-3 py-1 rounded-full text-xs {{ request('level') ? '' : 'bg-green-800 text-white' }}">
            All {{ number_format($totalRead) }}
        </a>
        @foreach($allLevels as $level => $count)
        <a href="{{ route('admin.system-logs', array_merge(request()->except('page'), ['level' => $level])) }}"
            class="px-3 py-1 rounded-full text-xs {{ $badge[strtoupper($level)] ?? 'bg-gray-100 text-gray-700' }} {{ request('level') === $level ? 'ring-2 ring-offset-1 ring-gray-900' : '' }} {{ $count === 0 ? 'opacity-40' : '' }}">
            {{ ucfirst($level) }} {{ number_format($count) }}
        </a>
        @endforeach
    </div>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('admin.system-logs') }}" class="flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}"
                placeholder="Message, file, class, SQL..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Level</label>
            <select name="level" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">All levels</option>
                @foreach(\App\Services\SystemLogReader::LEVELS as $level)
                <option value="{{ $level }}" {{ request('level') === $level ? 'selected' : '' }}>{{ ucfirst($level) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="{{ request('date_to') }}"
                class="px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">File</label>
            <select name="file" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                <option value="">Latest</option>
                @foreach($files as $logFile)
                <option value="{{ $logFile['name'] }}" {{ request('file') === $logFile['name'] ? 'selected' : '' }}>
                    {{ $logFile['name'] }} ({{ number_format($logFile['bytes'] / 1000, 1) }} KB, {{ $logFile['written'] }})
                </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Window</label>
            <select name="window" class="px-3 py-2 border border-gray-300 rounded-md text-sm">
                @foreach(\App\Services\SystemLogReader::WINDOWS as $megabytes => $label)
                <option value="{{ $megabytes }}" {{ (int) request('window', 2) === $megabytes ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-3 items-end">
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
                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Details</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @forelse($logs as $entry)
                @php $rowId = ($logs->firstItem() ?? 1) + $loop->index; @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap align-top">{{ $entry['moment']->format('Y-m-d h:i A') }}</td>
                    <td class="px-4 py-3 align-top">
                        <span class="px-2 py-1 text-xs rounded-full {{ $badge[$entry['level']] ?? 'bg-gray-100 text-gray-700' }}">{{ $entry['level'] }}</span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-900 align-top">
                        {{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $entry['message']), 220) }}
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600 align-top whitespace-nowrap" title="{{ $entry['where'] }}">
                        {{ $shortName($entry['where']) ?: '-' }}
                    </td>
                    <td class="px-4 py-3 text-right align-top">
                        <button type="button" data-log-toggle="log-{{ $rowId }}"
                            class="text-green-700 hover:text-green-900 text-sm font-medium">Show</button>
                    </td>
                </tr>
                <tr id="log-{{ $rowId }}" class="hidden bg-gray-50">
                    <td colspan="5" class="px-4 py-4">
                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                            <div class="lg:col-span-1 text-sm space-y-2">
                                <div>
                                    <span class="block text-xs uppercase text-gray-500">Full message</span>
                                    <p class="text-gray-900 break-words">{{ \Illuminate\Support\Str::limit($entry['message'], 2000) }}</p>
                                </div>
                                <div>
                                    <span class="block text-xs uppercase text-gray-500">Channel</span>
                                    <p class="text-gray-700">{{ $entry['channel'] }}.{{ strtolower($entry['level']) }}</p>
                                </div>
                                @if($entry['where'])
                                <div>
                                    <span class="block text-xs uppercase text-gray-500">Thrown at</span>
                                    <p class="text-gray-700 break-all">{{ $entry['where'] }}</p>
                                </div>
                                @endif
                                @if(count($entry['context']))
                                <div>
                                    <span class="block text-xs uppercase text-gray-500">Context</span>
                                    @foreach($entry['context'] as $key => $value)
                                        @if(! is_array($value) && $key !== 'exception')
                                        <p class="text-gray-700"><span class="text-gray-500">{{ $key }}:</span>
                                            <span class="break-all">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value }}</span></p>
                                        @endif
                                    @endforeach
                                </div>
                                @endif
                                <button type="button" data-log-copy="log-{{ $rowId }}"
                                    class="bg-gray-200 text-gray-700 px-3 py-1 rounded-md text-xs hover:bg-gray-300">Copy for support</button>
                            </div>
                            <div class="lg:col-span-2">
                                <span class="block text-xs uppercase text-gray-500 mb-1">Stack trace</span>
                                @php
                                    $trace = $entry['stack'] === '' ? [] : explode("\n", $entry['stack']);
                                    $shown = implode("\n", array_slice($trace, 0, 15));
                                    $hidden = max(0, count($trace) - 15);
                                    $traceText = \Illuminate\Support\Str::limit($shown, 4000);
                                @endphp
                                <pre data-log-stack
                                    class="bg-white border border-gray-200 rounded-md p-3 text-xs text-gray-700 overflow-auto max-h-80 whitespace-pre-wrap break-all">{{ $traceText ?: 'No stack trace was written for this entry.' }}@if($hidden > 0)

... {{ $hidden }} more line(s) below the ones shown. The top of a trace is what points at the file that failed.@endif</pre>
                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                        {{ $totalRead === 0 ? 'No log entries in the window that was read.' : 'Nothing matches these filters.' }}
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

<p class="text-xs text-gray-500 mt-4">
    This page reads {{ $meta['file'] ?? 'the log folder' }} only. It never changes or deletes anything, and it always shows the newest entries first.
</p>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-log-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const row = document.getElementById(button.dataset.logToggle);
            if (!row) return;
            const open = row.classList.toggle('hidden');
            button.textContent = open ? 'Show' : 'Hide';
        });
    });

    document.querySelectorAll('[data-log-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            const row = document.getElementById(button.dataset.logCopy);
            if (!row) return;

            const text = Array.from(row.querySelectorAll('p, pre'))
                .map(function (node) { return node.innerText; })
                .join('\n\n');

            navigator.clipboard.writeText(text).then(function () {
                const original = button.textContent;
                button.textContent = 'Copied';
                setTimeout(function () { button.textContent = original; }, 1500);
            });
        });
    });
</script>
@endpush
