@extends('layouts.app')

@section('title', 'What Happened')

@section('content')
@php
    // A notice carries its own words, so this page prints nothing it has to go and look up.
    // That is also why an entry still reads correctly months later, after the audit row behind
    // it has aged out of the retention window.
    $slots = [
        'transfers' => 'Transfer',
        'china' => 'China desk',
        'exchanges' => 'Exchange',
        'expenses' => 'Expense',
        'wallets' => 'Wallet',
        'users' => 'Users',
        'rates' => 'Prices',
        'settings' => 'Settings',
    ];
@endphp

<div class="mb-6 flex flex-wrap items-start justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">What Happened</h1>
        <p class="text-gray-600">Newest first. You only receive word about the parts of the book you can open.</p>
    </div>
    @if($counts['unread'] > 0)
    <form method="POST" action="{{ route('notifications.read-all') }}">
        @csrf
        @method('PATCH')
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">
            Mark all {{ number_format($counts['unread']) }} as read
        </button>
    </form>
    @endif
</div>

<div class="flex flex-wrap gap-2 mb-6">
    <a href="{{ route('notifications.index', request()->except(['state', 'page'])) }}"
        class="px-3 py-1 rounded-full text-xs {{ request('state') ? 'bg-gray-100 text-gray-700' : 'bg-green-800 text-white' }}">
        All {{ number_format($counts['all']) }}
    </a>
    <a href="{{ route('notifications.index', ['state' => 'unread']) }}"
        class="px-3 py-1 rounded-full text-xs {{ request('state') === 'unread' ? 'bg-green-800 text-white' : 'bg-amber-100 text-amber-800' }}">
        Unread {{ number_format($counts['unread']) }}
    </a>
</div>

<div class="bg-white rounded-lg shadow p-4 mb-6">
    <form method="GET" action="{{ route('notifications.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <input type="hidden" name="state" value="{{ request('state') }}">
        <div class="md:col-span-3">
            <label for="search" class="block text-sm text-gray-600 mb-1">Search</label>
            <input type="text" id="search" name="search" value="{{ request('search') }}"
                placeholder="Receipt number, name, amount..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm">
        </div>
        <div class="flex gap-2 items-end">
            <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm hover:bg-green-700">Filter</button>
            <a href="{{ route('notifications.index') }}" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">Reset</a>
        </div>
    </form>
</div>

<div class="bg-white rounded-lg shadow divide-y divide-gray-200">
    @forelse($notices as $notice)
    @php $data = $notice->data; @endphp
    <div class="flex gap-3 p-4 {{ $notice->read_at ? '' : 'bg-green-50' }}">
        <span class="mt-2 shrink-0 w-2 h-2 rounded-full {{ $notice->read_at ? 'bg-gray-200' : 'bg-green-600' }}"
            aria-label="{{ $notice->read_at ? 'seen' : 'not seen yet' }}"></span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">{{ $slots[$data['section'] ?? ''] ?? 'Update' }}</span>
                <a href="{{ $data['path'] ?? '#' }}" class="font-semibold text-green-800 hover:text-green-900">{{ $data['headline'] ?? 'Something happened' }}</a>
                <span class="text-xs text-gray-500 ml-auto tabular-nums whitespace-nowrap">{{ $notice->created_at->format('Y-m-d h:i A') }}</span>
            </div>
            <p class="text-sm text-gray-900 mt-1">{{ $data['body'] ?? '' }}</p>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2">
                <span class="text-xs text-gray-500">
                    By {{ $data['actor'] ?? 'the system' }}
                    @if(!empty($data['actor_role']))<span class="text-gray-400">({{ $data['actor_role'] }})</span>@endif
                </span>
                <a href="{{ $data['path'] ?? '#' }}" class="text-sm font-medium text-green-700 hover:text-green-900">{{ $data['link_label'] ?? 'Open' }}</a>
                @unless($notice->read_at)
                <form method="POST" action="{{ route('notifications.read', $notice->id) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="text-sm text-gray-600 hover:text-gray-900 underline underline-offset-2">Mark as read</button>
                </form>
                @endunless
            </div>
        </div>
    </div>
    @empty
    <div class="p-8 text-center">
        <p class="text-gray-900 font-medium">Nothing to catch up on.</p>
        <p class="text-sm text-gray-600 mt-1">
            {{ request('state') === 'unread' || request()->filled('search')
                ? 'Nothing matches this filter. Clear it to see everything.'
                : 'Word arrives here whenever someone books a transfer, enters an expense, changes a price or an account.' }}
        </p>
    </div>
    @endforelse
</div>

@if($notices->hasPages())
<div class="mt-6">{{ $notices->links() }}</div>
@endif

<p class="text-xs text-gray-500 mt-6">
    This page is for glancing. For a copy of the whole trail, the Audit Log screen has an Export to Excel.
</p>
@endsection
