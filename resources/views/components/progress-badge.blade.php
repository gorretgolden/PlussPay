@props(['transaction'])

<x-stage-badge :stage="$transaction->progress()" {{ $attributes }} />
