@php
    $checkedSet = $checked ?? [];
    $locked = $locked ?? false;
@endphp
<div class="perm-picker space-y-4">
    <label class="block text-sm font-medium text-gray-700">Search permissions
        <input type="search" class="perm-filter mt-1 w-full border-gray-300 rounded-lg px-3 py-2 border" placeholder="Search permissions by label or module">
    </label>
    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
        <span class="text-gray-500"><span class="perm-selected">{{ count($checkedSet) }}</span> of {{ $groups->flatten()->count() }} {{ Str::plural('permission', $groups->flatten()->count()) }} selected</span>
        @unless($locked)
        <div class="flex gap-3">
            <button type="button" data-check="true" class="min-h-[44px] text-green-800 font-medium">Select all available</button>
            <button type="button" data-check="false" class="min-h-[44px] text-gray-600">Clear selection</button>
        </div>
        @endunless
    </div>
    @foreach($groups as $group => $perms)
    <details class="perm-group border border-gray-200 rounded-lg" open>
        <summary class="cursor-pointer px-4 py-3 font-semibold text-sm text-gray-800">{{ $group }} <span class="text-gray-500 font-normal">({{ $perms->count() }})</span></summary>
        @unless($locked)
        <label class="flex items-center gap-2 text-sm px-4 py-2 text-green-800 min-h-[44px]">
            <input type="checkbox" class="perm-group-check rounded border-gray-300"> Select this module
        </label>
        @endunless
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 px-4 pb-3">
            @foreach($perms as $permission)
            @php
                $available = !$locked && \App\Support\PermissionCatalog::mayDelegate(auth()->user(), $permission->name);
                $label = \App\Support\PermissionCatalog::label($permission->name);
            @endphp
            <label class="perm-item flex items-center gap-2 text-sm min-h-[44px] {{ $available ? 'text-gray-700' : 'text-gray-400' }}" data-name="{{ $group.' '.$label.' '.$permission->name }}" title="{{ $permission->name }}{{ $available ? '' : ' (protected or not delegable)' }}">
                <input type="checkbox" name="permissions[]" value="{{ $permission->name }}" data-label="{{ $label }}" data-sensitive="{{ \App\Support\PermissionCatalog::sensitive($permission->name) ? '1' : '0' }}"
                    @checked(in_array($permission->name, $checkedSet, true)) @disabled(!$available)
                    class="rounded border-gray-300 text-green-700 focus:ring-green-600">
                <span>{{ $label }}@if(in_array($permission->name, \App\Support\PermissionCatalog::RESERVED, true)) <span class="text-xs">(Super Admin only)</span>@endif</span>
            </label>
            @endforeach
        </div>
    </details>
    @endforeach
    <p class="perm-no-match hidden text-sm text-gray-500">No matching permissions. Try another label or module.</p>
</div>
