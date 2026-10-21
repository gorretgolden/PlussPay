{{-- The counter price first, because that is the figure every desk books at. The market mid sits
     underneath it and is only built for the two accounts that asked to see it. --}}
<section aria-labelledby="dashboard-rate-heading" class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-gray-100 px-4 py-3 sm:px-5">
        <h2 id="dashboard-rate-heading" class="text-base font-semibold text-gray-900 sm:text-lg">Exchange Rate</h2>
        <p class="text-xs text-gray-500">The desk's selling rate.</p>
        @can('view-exchange-rates')
        <a href="{{ route('exchange-rates.index') }}" class="ml-auto text-sm font-medium text-green-800 hover:underline">Rate history</a>
        @endcan
    </div>

    <div class="grid grid-cols-2 divide-x divide-gray-100">
        @foreach($pairs as $pair)
        <div class="min-w-0 p-3 sm:p-5">
            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ $pair['label'] }}</div>
            @if($pair['value'] !== null)
            <div class="mt-1 flex flex-wrap items-baseline gap-x-2">
                <span class="text-2xl font-bold text-gray-900 tabular-nums">{{ $pair['value'] }}</span>
                <span class="text-xs text-gray-500">{{ $pair['unit'] }} per USD</span>
            </div>
            @if($pair['updated_at'])
            <div class="mt-1 text-xs text-gray-500">Updated {{ $pair['updated_at']->format('d M, H:i') }}</div>
            @endif
            @else
            <div class="mt-1 text-sm text-gray-500">No price is in use.</div>
            @endif
        </div>
        @endforeach
    </div>

    @if($showMarket)
    <div class="border-t border-gray-100 bg-gray-50 px-4 py-4 sm:px-5">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h3 class="text-sm font-semibold text-gray-900">Live market</h3>
            <p class="text-xs text-gray-500">Mid-market reference, not the counter price. The desk still sells at the rate above.</p>
        </div>

        @if($market === [])
        <p class="mt-2 text-sm text-gray-500">No live reading yet. The system pulls one every hour.</p>
        @else
        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach($market as $reading)
            <div class="flex min-w-0 items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2">
                <div class="min-w-0">
                    <div class="text-xs text-gray-500">Dollar to {{ $reading['label'] }}</div>
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-lg font-semibold text-gray-900 tabular-nums">{{ $reading['display'] }}</span>
                        <span class="text-xs text-gray-500">{{ $reading['unit'] }} per USD</span>
                    </div>
                    <div class="text-xs {{ $reading['tone'] }}">{{ $reading['change_text'] }}</div>
                </div>
                @if($reading['points'])
                <svg viewBox="0 0 120 36" class="h-9 w-24 shrink-0 {{ $reading['line'] }}" role="img" aria-label="{{ $reading['chart_label'] }}" preserveAspectRatio="none">
                    <polyline points="{{ $reading['points'] }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                </svg>
                @endif
            </div>
            @endforeach
        </div>

        <p class="mt-2 text-xs {{ $stale ? 'font-medium text-amber-700' : 'text-gray-500' }}">
            Last pulled {{ $lastPulled->format('d M, H:i') }} from {{ implode(' and ', $sources) }}.
            {{ $stale ? 'The hourly pull may have stopped.' : 'The system pulls every hour.' }}
        </p>
        @endif
    </div>
    @endif
</section>
