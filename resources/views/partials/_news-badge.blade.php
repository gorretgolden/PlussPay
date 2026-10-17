{{-- The number of things in this part of the book the reader has not seen yet. Amber is kept
     for failed sign-ins and red for machine errors, so an unread count gets its own colour
     and never reads as a fault. --}}
@php $newsCount = ($sidebarNews[$slot] ?? 0); @endphp
@if($newsCount > 0)
<span class="ml-2 bg-yellow-300 text-gray-900 text-xs font-semibold rounded-full px-2 py-0.5 tabular-nums">{{ $newsCount }}</span>
@endif
