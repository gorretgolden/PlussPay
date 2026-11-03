{{-- The counter price first, because that is the figure every desk books at. The market mid sits
     underneath it and is only built for the two accounts that asked to see it. --}}
<section aria-labelledby="dashboard-rate-heading" class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-gray-100 px-4 py-2.5 sm:px-5">
        {{-- Same 40px mark the wallet cards carry, so the board's money cards read as one family. --}}
        <span aria-hidden="true" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-800">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
        </span>
        <div class="min-w-0">
            <h2 id="dashboard-rate-heading" class="text-sm font-semibold text-gray-900 sm:text-base">Exchange Rate</h2>
            <p class="text-xs text-gray-500">The desk's selling rate.</p>
        </div>
        @can('view-exchange-rates')
        <a href="{{ route('exchange-rates.index') }}" class="ml-auto inline-flex items-center gap-1 text-sm font-medium text-green-800 hover:underline">
            Rate history
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
        </a>
        @endcan
    </div>

    <div class="bg-gray-50 p-2.5 sm:p-3">
        <div class="grid grid-cols-2 gap-2.5 sm:gap-3">
            @foreach($pairs as $pair)
            <div class="relative min-w-0 overflow-hidden rounded-lg border border-gray-200 bg-white p-2.5 sm:p-3">
                <span aria-hidden="true" class="absolute inset-x-0 top-0 h-0.5 bg-green-600"></span>
                <span aria-hidden="true" class="pointer-events-none absolute right-2 top-1/2 hidden -translate-y-1/2 select-none text-4xl font-black tracking-tighter text-gray-100 sm:block">{{ $pair['unit'] }}</span>
                <div class="relative text-[10px] font-semibold uppercase tracking-wide text-gray-500 sm:text-xs">{{ $pair['label'] }}</div>
                @if($pair['value'] !== null)
                <div class="relative mt-1.5 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span class="text-xl font-bold tracking-tight text-gray-900 tabular-nums sm:text-2xl">{{ $pair['value'] }}</span>
                    <span class="text-xs font-medium text-gray-500">{{ $pair['unit'] }} per USD</span>
                </div>
                @if($pair['updated_at'])
                {{-- A counter price is only worth what it was worth when it was set. The desk books
                     at the figure on the card, so a price that has not been touched in a day is
                     said out loud in the same breath as the number, not left for someone to notice
                     the date two lines down. --}}
                @php $isOld = $pair['updated_at']->lt(now()->subDay()); @endphp
                <div class="relative mt-1 flex items-start gap-1.5 text-[11px] sm:text-xs {{ $isOld ? 'font-medium text-amber-700' : 'text-gray-500' }}">
                    @if($isOld)
                    <svg class="h-3.5 w-3.5 shrink-0 mt-px" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 4h.01M10.29 3.86l-8.02 13.89A1.5 1.5 0 003.57 20h16.86a1.5 1.5 0 001.3-2.25L13.71 3.86a1.5 1.5 0 00-2.42 0z"/></svg>
                    <span title="{{ $pair['updated_at']->format('M j, Y, g:i A') }}">Set {{ $pair['updated_at']->diffForHumans(null, true) }} ago. Set a new price before booking.</span>
                    @else
                    <svg class="hidden h-3.5 w-3.5 shrink-0 sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>Updated {{ $pair['updated_at']->format('d M, g:i A') }}</span>
                    @endif
                </div>
                @endif
                @else
                <div class="mt-2 text-sm text-gray-500">No price is in use.</div>
                @endif
            </div>
            @endforeach
        </div>

        @if($showMarket)
        <div class="mt-4 border-t border-gray-200 pt-4">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h3 class="flex items-center gap-1.5 text-sm font-semibold text-gray-900">
                    <span aria-hidden="true" class="h-2 w-2 rounded-full {{ $market === [] ? 'bg-gray-400' : ($stale ? 'bg-amber-500' : 'bg-green-600') }}"></span>
                    Live market
                </h3>
                <p class="text-xs text-gray-500">Mid-market reference, not the counter price. The desk still sells at the rate above.</p>
            </div>

            @if($market === [])
            <p class="mt-2 text-sm text-gray-500">No live reading yet. The system pulls one every hour.</p>
            @else
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach($market as $reading)
                <div class="flex min-w-0 items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2.5">
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
    </div>
</section>
