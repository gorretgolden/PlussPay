@extends('layouts.app')

@section('title', 'Audit Logs')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Audit Logs</h1>
        <p class="text-gray-600">{{ $tab === 'login' ? 'Every sign-in, sign-out, failed attempt and lockout' : 'Transaction activity audit trail' }}</p>
    </div>
    <a href="{{ $tab === 'login' ? route('export.login-logs', request()->query()) : route('export.activity-logs', request()->query()) }}"
        class="bg-indigo-700 text-white px-4 py-2 rounded-md text-sm hover:bg-indigo-600">Export Excel</a>
</div>

@include('admin.partials._logs-tabs')

@if($tab === 'login')
    @include('admin.partials._audit-login')
@else
    @include('admin.partials._audit-activity')
@endif
@endsection
