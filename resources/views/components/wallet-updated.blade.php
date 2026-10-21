@props([
    'at' => null,
    'label' => 'Updated',
    'emptyText' => 'No movement yet',
])

{{-- Finance reads the clock as part of the figure: a balance with no age on it cannot say whether
     the last event was a minute or a month ago. The relative phrase answers that at a glance and
     the exact stamp beside it is what gets quoted in a message. --}}
@if($at)
<p {{ $attributes->merge(['class' => 'text-xs text-gray-500']) }}>
    <time datetime="{{ $at->toIso8601String() }}" title="{{ $at->format('Y-m-d H:i:s T') }}">{{ $label }} {{ $at->diffForHumans() }}</time>
    <span aria-hidden="true">·</span>
    <span class="tabular-nums">{{ $at->format('M j, Y, g:i A') }}</span>
</p>
@else
<p {{ $attributes->merge(['class' => 'text-xs text-gray-500']) }}>{{ $emptyText }}</p>
@endif
