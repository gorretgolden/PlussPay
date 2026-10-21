@props(['transaction'])

@php
    $progress = $transaction->progress();

    $tone = match ($progress['key']) {
        'cash' => 'bg-blue-100 text-blue-800',
        'waiting' => 'bg-amber-100 text-amber-800',
        'paid' => 'bg-green-100 text-green-800',
        'cancelled' => 'bg-red-100 text-red-800',
        default => 'bg-yellow-100 text-yellow-800',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-block whitespace-nowrap px-2 py-1 text-xs rounded-full '.$tone]) }}>{{ $progress['label'] }}</span>
