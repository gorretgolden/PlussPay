@extends('layouts.app')
@section('title', 'Permission Catalog')
@section('content')
<div class="flex flex-wrap justify-between items-start gap-4 mb-6">
    <div><h1 class="text-2xl font-bold text-gray-900">Permission catalog</h1><p class="text-gray-500 mt-1">{{ $permissions->flatten()->count() }} permissions, grouped by the work they control.</p></div>
    <a href="{{ route('admin.roles') }}" class="text-sm text-green-800 underline py-3">Manage roles & permissions</a>
</div>
<div class="bg-green-50 border border-green-200 text-green-950 rounded-xl p-4 text-sm mb-6">Permission slugs are stable application identifiers, not editable labels. List access does not grant access to everyone's records or profit. Assign access on the role page; every change is audited.</div>
@if($permissions->isEmpty())
<div class="bg-white border rounded-xl p-8 text-center"><h2 class="font-semibold">No permissions have been initialized</h2><p class="text-sm text-gray-500 mt-2">Initialize defaults without resetting existing role customizations.</p>
    @can('seed-permissions')<form method="POST" action="{{ route('admin.permissions.seed') }}" class="mt-4">@csrf<button class="bg-green-800 text-white rounded-lg px-4 py-3">Seed default permissions</button></form>@else<p class="text-sm mt-4">Ask a Super Admin to seed default permissions.</p>@endcan
</div>
@else
<div class="bg-white border border-gray-200 rounded-xl p-5 sm:p-6">
    @include('admin.partials._perm-picker', ['groups' => $permissions, 'checked' => [], 'locked' => true])
</div>
@endif
@endsection
@push('scripts')
<script>
(() => {
    const picker = document.querySelector('.perm-picker'); if (!picker) return;
    picker.querySelector('.perm-selected').parentElement.textContent = 'Read-only catalog · Hover a label to see its slug';
    picker.querySelectorAll('[name="permissions[]"]').forEach(input => input.classList.add('hidden'));
})();
</script>
@endpush
