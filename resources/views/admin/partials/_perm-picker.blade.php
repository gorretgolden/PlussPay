@php
    $checkedSet = $checked ?? [];
    $total = $groups->flatten()->count();
@endphp

<div class="perm-picker flex flex-col min-h-0">
    <div class="px-6 pt-4">
        <input type="text" class="perm-filter w-full px-4 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-green-500 focus:border-green-500"
            placeholder="Search permissions... e.g. view-transactions">
    </div>
    <div class="px-6 pt-3 flex items-center justify-between gap-4">
        <span class="text-xs text-gray-500"><span class="perm-selected">0</span> of {{ $total }} selected</span>
        <div class="flex gap-4 text-sm">
            <button type="button" data-check="true" class="text-green-800 hover:underline font-medium">Select all</button>
            <button type="button" data-check="false" class="text-red-600 hover:underline font-medium">Remove all</button>
        </div>
    </div>
    <div class="p-6 space-y-4 {{ $listClass ?? 'max-h-96 overflow-y-auto' }}">
        @foreach($groups as $group => $perms)
        <div class="perm-group">
            <h4 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">{{ ucwords(str_replace('-', ' ', $group)) }}</h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-1">
                @foreach($perms as $permission)
                <label class="perm-item flex items-center gap-2 text-sm py-1" data-name="{{ $permission->name }}">
                    <input type="checkbox" name="permissions[]" value="{{ $permission->name }}"
                        @checked(in_array($permission->name, $checkedSet, true))
                        class="rounded border-gray-300 text-green-700 focus:ring-green-600">
                    <span class="text-gray-700">{{ $permission->name }}</span>
                </label>
                @endforeach
            </div>
        </div>
        @endforeach
        <p class="perm-no-match hidden text-sm text-gray-500 py-2">No permission matches that search.</p>
    </div>
</div>
