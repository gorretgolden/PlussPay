{{-- A muted info icon carries the long explanation on hover or keyboard focus, so each field
     under it stays one short line. --}}
<span class="relative inline-flex group shrink-0" tabindex="0" aria-describedby="{{ $id }}">
    <svg class="w-4 h-4 text-gray-400 group-hover:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <span role="tooltip" id="{{ $id }}" class="pointer-events-none absolute top-full right-0 z-20 mt-2 w-64 rounded-md bg-gray-900 px-3 py-2 text-left text-xs font-normal leading-snug text-white opacity-0 shadow-lg transition-opacity group-hover:opacity-100 group-focus:opacity-100">{{ $text }}</span>
</span>
